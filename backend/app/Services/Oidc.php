<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Anmeldung ueber einen OIDC-Anbieter, z.B. Authentik.
 *
 * Bewusst ohne zusaetzliche Bibliothek: RackView benutzt den
 * Authorization-Code-Flow mit PKCE und holt die Benutzerdaten danach am
 * userinfo-Endpunkt ab. Der Code wird serverseitig ueber TLS direkt
 * beim Anbieter gegen ein Token getauscht - damit entfaellt das Pruefen
 * von JWT-Signaturen, das sonst eine Krypto-Bibliothek verlangen wuerde
 * (so sieht es auch die OIDC-Spezifikation in Abschnitt 3.1.3.7 vor).
 */
class Oidc
{
    /** Wie lange die Endpunkte des Anbieters zwischengespeichert werden */
    private const CACHE_MINUTEN = 60;

    public static function aktiv(): bool
    {
        return (bool) config('rackview.oidc.enabled')
            && config('rackview.oidc.issuer')
            && config('rackview.oidc.client_id')
            && config('rackview.oidc.client_secret');
    }

    public static function aufschrift(): string
    {
        return (string) config('rackview.oidc.label');
    }

    /**
     * Die Endpunkte des Anbieters aus seinem Steckbrief unter
     * /.well-known/openid-configuration.
     *
     * @return array<string, mixed>
     */
    public static function konfiguration(): array
    {
        return Cache::remember('oidc-konfiguration', now()->addMinutes(self::CACHE_MINUTEN), function () {
            $issuer = rtrim((string) config('rackview.oidc.issuer'), '/');

            $antwort = Http::timeout(10)
                ->acceptJson()
                ->get($issuer.'/.well-known/openid-configuration');

            if (! $antwort->successful()) {
                throw new RuntimeException("Der Anbieter antwortet nicht ({$antwort->status()}).");
            }

            $daten = $antwort->json();

            foreach (['issuer', 'authorization_endpoint', 'token_endpoint', 'userinfo_endpoint'] as $pflicht) {
                if (empty($daten[$pflicht])) {
                    throw new RuntimeException("Der Anbieter nennt kein {$pflicht}.");
                }
            }

            return $daten;
        });
    }

    /** Adresse, zu der der Anbieter zurueckschickt - genau so eintragen */
    public static function rueckkehrAdresse(): string
    {
        return url('/api/auth/oidc/callback');
    }

    /**
     * Die Adresse, zu der der Browser geschickt wird. code_verifier
     * bleibt in der Sitzung; der Anbieter sieht nur seinen Abdruck.
     */
    public static function anmeldeUrl(string $state, string $codeVerifier): string
    {
        $konfiguration = self::konfiguration();

        $abfrage = http_build_query([
            'response_type' => 'code',
            'client_id' => config('rackview.oidc.client_id'),
            'redirect_uri' => self::rueckkehrAdresse(),
            'scope' => config('rackview.oidc.scopes'),
            'state' => $state,
            'code_challenge' => self::abdruck($codeVerifier),
            'code_challenge_method' => 'S256',
        ]);

        $ziel = $konfiguration['authorization_endpoint'];

        return $ziel.(str_contains($ziel, '?') ? '&' : '?').$abfrage;
    }

    /**
     * Den einmaligen Code gegen ein Token tauschen.
     *
     * @return array<string, mixed>
     */
    public static function token(string $code, string $codeVerifier): array
    {
        $antwort = Http::asForm()
            ->timeout(10)
            ->acceptJson()
            ->withBasicAuth(
                (string) config('rackview.oidc.client_id'),
                (string) config('rackview.oidc.client_secret')
            )
            ->post(self::konfiguration()['token_endpoint'], [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => self::rueckkehrAdresse(),
                'code_verifier' => $codeVerifier,
            ]);

        if (! $antwort->successful()) {
            // Die Fehlerbeschreibung des Anbieters ist hier die wertvollste
            // Angabe - meist stimmt die Rueckkehradresse nicht.
            $grund = $antwort->json('error_description') ?? $antwort->json('error') ?? $antwort->status();

            throw new RuntimeException("Der Anbieter hat den Code nicht angenommen ({$grund}).");
        }

        $token = $antwort->json();

        if (empty($token['access_token'])) {
            throw new RuntimeException('Der Anbieter hat kein Zugriffstoken geschickt.');
        }

        return $token;
    }

    /**
     * @return array<string, mixed>
     */
    public static function benutzerdaten(string $accessToken): array
    {
        $antwort = Http::withToken($accessToken)
            ->timeout(10)
            ->acceptJson()
            ->get(self::konfiguration()['userinfo_endpoint']);

        if (! $antwort->successful()) {
            throw new RuntimeException("Die Benutzerdaten waren nicht abrufbar ({$antwort->status()}).");
        }

        $daten = $antwort->json();

        if (empty($daten['sub'])) {
            throw new RuntimeException('Der Anbieter hat keine Kennung (sub) mitgeschickt.');
        }

        return $daten;
    }

    /** base64url(sha256(verifier)) - der Abdruck fuer PKCE */
    private static function abdruck(string $codeVerifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');
    }
}
