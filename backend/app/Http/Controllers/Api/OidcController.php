<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Oidc;
use App\Services\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Anmeldung ueber einen OIDC-Anbieter (z.B. Authentik).
 *
 * Zwei Schritte, beide als gewoehnlicher Seitenaufruf im Browser:
 *
 *   /api/auth/oidc/redirect   schickt zum Anbieter
 *   /api/auth/oidc/callback   nimmt den Code entgegen und meldet an
 *
 * Danach geht es zurueck auf die Startseite; die Oberflaeche sieht die
 * fertige Sitzung. Schlaegt etwas fehl, wird der Grund in der Sitzung
 * hinterlegt und von /auth/status einmalig ausgeliefert.
 *
 * Konten: Wer schon eine Kennung des Anbieters hat, wird daran erkannt.
 * Sonst wird ueber die E-Mail-Adresse verknuepft - aber nur, wenn der
 * Anbieter sie als bestaetigt meldet, sonst koennte jemand mit einer
 * fremden Adresse ein bestehendes Konto uebernehmen. Gibt es noch kein
 * Konto, entsteht eines mit eigenem, leerem Bereich.
 */
class OidcController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        abort_unless(Oidc::aktiv(), 404);

        $state = Str::random(40);
        $codeVerifier = Str::random(64);

        $request->session()->put('oidc', [
            'state' => $state,
            'verifier' => $codeVerifier,
        ]);

        try {
            return redirect()->away(Oidc::anmeldeUrl($state, $codeVerifier));
        } catch (RuntimeException $fehler) {
            return $this->mitFehler($request, $fehler->getMessage());
        }
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless(Oidc::aktiv(), 404);

        // Einmalig: ein zweiter Aufruf mit demselben state prallt ab.
        $gemerkt = $request->session()->pull('oidc');

        if ($request->filled('error')) {
            return $this->mitFehler($request, 'Der Anbieter hat die Anmeldung abgelehnt.');
        }

        if (! is_array($gemerkt) || ! $request->filled('code')) {
            return $this->mitFehler($request, 'Die Anmeldung ist abgelaufen. Bitte noch einmal versuchen.');
        }

        // Vergleich in konstanter Zeit, damit der Wert nicht erratbar wird
        if (! hash_equals((string) $gemerkt['state'], (string) $request->query('state'))) {
            return $this->mitFehler($request, 'Die Anmeldung konnte nicht zugeordnet werden.');
        }

        try {
            $token = Oidc::token((string) $request->query('code'), (string) $gemerkt['verifier']);
            $daten = Oidc::benutzerdaten($token['access_token']);
            $user = $this->konto($daten);
        } catch (RuntimeException $fehler) {
            // Der Wortlaut geht an den Browser; die Einzelheiten ins Log.
            Log::warning('OIDC-Anmeldung fehlgeschlagen: '.$fehler->getMessage());

            return $this->mitFehler($request, $fehler->getMessage());
        }

        Auth::login($user, true);

        // Neue Sitzungskennung nach dem Anmelden (Session Fixation)
        $request->session()->regenerate();

        return redirect('/');
    }

    /**
     * Konto zur Antwort des Anbieters finden oder anlegen.
     *
     * @param  array<string, mixed>  $daten
     */
    private function konto(array $daten): User
    {
        $sub = (string) $daten['sub'];
        $email = strtolower(trim((string) ($daten['email'] ?? '')));
        $name = trim((string) ($daten['name'] ?? $daten['preferred_username'] ?? $email));

        $user = User::query()->where('oidc_sub', $sub)->first();

        if ($user) {
            // Namen nachfuehren, wenn er sich beim Anbieter geaendert hat
            if ($name !== '' && $name !== $user->name) {
                $user->name = $name;
                $user->save();
            }

            return $user;
        }

        if ($email === '') {
            throw new RuntimeException('Der Anbieter hat keine E-Mail-Adresse mitgeschickt.');
        }

        $bestehend = User::query()->where('email', $email)->first();

        if ($bestehend) {
            if (! ($daten['email_verified'] ?? false)) {
                throw new RuntimeException(
                    'Zu dieser E-Mail-Adresse gibt es schon ein Konto, der Anbieter hat sie aber nicht bestätigt.'
                );
            }

            $bestehend->oidc_sub = $sub;
            $bestehend->save();

            return $bestehend;
        }

        // Neues Konto: Das Passwort ist zufaellig und niemandem bekannt -
        // angemeldet wird sich ueber den Anbieter. Wer spaeter doch ein
        // Passwort braucht, setzt es mit "php artisan rackview:user".
        $user = Workspace::createAccount($name !== '' ? $name : $email, $email, Str::random(64));

        $user->oidc_sub = $sub;
        $user->save();

        return $user;
    }

    private function mitFehler(Request $request, string $meldung): RedirectResponse
    {
        $request->session()->flash('oidc_fehler', $meldung);

        return redirect('/');
    }
}
