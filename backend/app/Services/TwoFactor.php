<?php

namespace App\Services;

use App\Models\User;
use InvalidArgumentException;

/**
 * Zwei-Faktor-Anmeldung mit Einmalcodes (TOTP, RFC 6238) - kompatibel mit
 * Google Authenticator, Microsoft Authenticator, 1Password, Aegis & Co.
 *
 * Bewusst ohne Zusatzpaket: Der Algorithmus ist ein HMAC-SHA1 ueber die
 * Zeit in 30-Sekunden-Schritten, das deckt PHP selbst ab.
 */
class TwoFactor
{
    public const PERIODE = 30;

    public const STELLEN = 6;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    // Ohne leicht verwechselbare Zeichen (0/o, 1/l/i)
    private const CODE_ZEICHEN = 'abcdefghjkmnpqrstuvwxyz23456789';

    /** Neuer geheimer Schluessel: 160 Bit, Base32 (32 Zeichen) */
    public static function newSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** Inhalt des QR-Codes fuer die Authenticator-App */
    public static function uri(string $secret, string $konto): string
    {
        $aussteller = (string) config('app.name', 'RackView');

        return 'otpauth://totp/'.rawurlencode($aussteller).':'.rawurlencode($konto).'?'
            .http_build_query([
                'secret' => $secret,
                'issuer' => $aussteller,
                'algorithm' => 'SHA1',
                'digits' => self::STELLEN,
                'period' => self::PERIODE,
            ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Prueft einen Code. Toleriert einen Zeitschritt Abweichung in beide
     * Richtungen (Uhr am Handy geht etwas vor oder nach).
     *
     * @param  int|null  $letzterSchritt  zuletzt verwendeter Zeitschritt - ein
     *                                    Code gilt nur einmal (kein Wiederholen)
     * @return int|null Zeitschritt des gueltigen Codes, sonst null
     */
    public static function verify(string $secret, string $code, ?int $letzterSchritt = null, ?int $zeit = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code);

        if (! preg_match('/^\d{'.self::STELLEN.'}$/', $code)) {
            return null;
        }

        $schluessel = self::base32Decode($secret);
        $jetzt = intdiv($zeit ?? time(), self::PERIODE);

        foreach ([0, -1, 1] as $abweichung) {
            $schritt = $jetzt + $abweichung;

            if ($letzterSchritt !== null && $schritt <= $letzterSchritt) {
                continue;
            }

            if (hash_equals(self::hotp($schluessel, $schritt), $code)) {
                return $schritt;
            }
        }

        return null;
    }

    /** Einmalcode fuer einen Zaehlerstand (RFC 4226) */
    public static function hotp(string $schluessel, int $zaehler): string
    {
        // Zaehler als 8 Byte, hoechstwertiges Byte zuerst. Die oberen vier
        // Byte sind bei 30-Sekunden-Schritten noch Jahrtausende lang null;
        // so laeuft es auch auf 32-Bit-PHP (dort fehlt pack('J')).
        $hash = hash_hmac('sha1', "\0\0\0\0".pack('N', $zaehler), $schluessel, true);

        $versatz = ord($hash[19]) & 0x0F;

        $wert = ((ord($hash[$versatz]) & 0x7F) << 24)
            | (ord($hash[$versatz + 1]) << 16)
            | (ord($hash[$versatz + 2]) << 8)
            | ord($hash[$versatz + 3]);

        return str_pad((string) ($wert % (10 ** self::STELLEN)), self::STELLEN, '0', STR_PAD_LEFT);
    }

    /**
     * Wiederherstellungscodes fuer den Fall, dass das Handy weg ist.
     * Jeder gilt einmal; gespeichert werden nur ihre Hashes.
     *
     * @return array{codes: list<string>, hashes: list<string>}
     */
    public static function newRecoveryCodes(int $anzahl = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $anzahl; $i++) {
            $zeichen = '';

            for ($j = 0; $j < 10; $j++) {
                $zeichen .= self::CODE_ZEICHEN[random_int(0, strlen(self::CODE_ZEICHEN) - 1)];
            }

            $codes[] = substr($zeichen, 0, 5).'-'.substr($zeichen, 5);
        }

        return ['codes' => $codes, 'hashes' => array_map(self::hashRecoveryCode(...), $codes)];
    }

    /**
     * SHA-256 genuegt hier: Die Codes sind zufaellig mit rund 49 Bit,
     * da hilft kein Woerterbuch. Bindestriche und Grossschreibung egal.
     */
    public static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', strtolower(preg_replace('/[^a-z0-9]/i', '', $code)));
    }

    /** Wiederherstellungscode einloesen - danach ist er verbraucht */
    public static function useRecoveryCode(User $user, string $code): bool
    {
        $gesucht = self::hashRecoveryCode($code);
        $liste = $user->two_factor_recovery_codes ?? [];

        foreach ($liste as $index => $hash) {
            if (hash_equals($hash, $gesucht)) {
                unset($liste[$index]);

                $user->two_factor_recovery_codes = array_values($liste);
                $user->save();

                return true;
            }
        }

        return false;
    }

    /** 2FA abschalten - aus den Einstellungen, vom Admin oder per artisan */
    public static function disable(User $user): void
    {
        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_last_step = null;
        $user->save();
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $text = '';

        foreach (str_split($bits, 5) as $gruppe) {
            $text .= self::BASE32[bindec(str_pad($gruppe, 5, '0'))];
        }

        return $text;
    }

    public static function base32Decode(string $text): string
    {
        $text = strtoupper(preg_replace('/[\s=]/', '', $text));
        $bits = '';

        foreach (str_split($text) as $zeichen) {
            $wert = strpos(self::BASE32, $zeichen);

            if ($wert === false) {
                throw new InvalidArgumentException('Ungültiger Base32-Schlüssel.');
            }

            $bits .= str_pad(decbin($wert), 5, '0', STR_PAD_LEFT);
        }

        $bytes = '';

        foreach (str_split($bits, 8) as $gruppe) {
            if (strlen($gruppe) === 8) {
                $bytes .= chr(bindec($gruppe));
            }
        }

        return $bytes;
    }
}
