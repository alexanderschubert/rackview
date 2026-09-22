<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Oidc;
use App\Services\TwoFactor;
use App\Services\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Sitzungsbasierte Anmeldung fuer die Oberflaeche.
 *
 * Frontend und API laufen unter derselben Adresse, daher genuegt Laravels
 * eingebaute Sitzung: ein HttpOnly-Cookie, das kein Skript im Browser lesen
 * kann, plus CSRF-Schutz fuer alle aendernden Anfragen (web-Middleware).
 *
 * Konten entstehen ueber die Registrierung (abschaltbar per
 * RACKVIEW_REGISTRATION), durch einen Admin in den Einstellungen oder per
 * "php artisan rackview:user". Jedes Konto hat einen eigenen Workspace.
 */
class AuthController extends Controller
{
    /**
     * Anmeldestatus fuer den Start der Oberflaeche. Antwortet immer mit
     * 200, damit die Konsole beim Laden nicht voller 401-Fehler steht.
     */
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'authenticated' => Auth::check(),
            'user' => Auth::user()?->clientData(),
            'has_users' => User::query()->exists(),
            'registration_open' => (bool) config('rackview.registration'),
            // Passwort stimmte schon, der Code fehlt noch (Seite neu geladen)
            'two_factor_pending' => ! Auth::check() && $this->offeneAnmeldung($request) !== null,

            // Anmeldung ueber einen OIDC-Anbieter, falls eingerichtet
            'oidc' => [
                'enabled' => Oidc::aktiv(),
                'label' => Oidc::aufschrift(),
            ],

            // Einmalige Meldung einer fehlgeschlagenen OIDC-Anmeldung;
            // sie wurde beim Rueckweg in die Sitzung gelegt.
            'oidc_error' => $request->session()->get('oidc_fehler'),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $daten = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ], [
            'email.required' => 'Bitte die E-Mail-Adresse angeben.',
            'email.email' => 'Das ist keine gültige E-Mail-Adresse.',
            'password.required' => 'Bitte das Passwort angeben.',
        ]);

        $guard = Auth::guard('web');

        // Adressen liegen klein geschrieben in der Datenbank
        $zugang = ['email' => strtolower(trim($daten['email'])), 'password' => $daten['password']];
        $angemeldetBleiben = (bool) ($daten['remember'] ?? false);

        // Nur pruefen, noch nicht anmelden - bei 2FA fehlt noch der Code
        if (! $guard->validate($zugang)) {
            // Bewusst dieselbe Meldung fuer unbekannte E-Mail und falsches
            // Passwort - sonst liesse sich abfragen, welche Konten existieren.
            throw ValidationException::withMessages([
                'email' => 'E-Mail-Adresse oder Passwort ist falsch.',
            ]);
        }

        /** @var User $user */
        $user = $guard->getLastAttempted();
        $guard->getProvider()->rehashPasswordIfRequired($user, $zugang);

        // Neue Sitzungs-ID (Schutz vor Session Fixation)
        $request->session()->regenerate();

        if ($user->hasTwoFactor()) {
            $request->session()->put('login.2fa', [
                'id' => $user->id,
                'remember' => $angemeldetBleiben,
                'seit' => time(),
                'fehlversuche' => 0,
            ]);

            return response()->json(['two_factor' => true]);
        }

        $guard->login($user, $angemeldetBleiben);

        return response()->json([
            'user' => $user->clientData(),
        ]);
    }

    /**
     * Zweiter Schritt: Code aus der App oder ein Wiederherstellungscode.
     * Erst danach ist das Konto angemeldet.
     */
    public function twoFactor(Request $request): JsonResponse
    {
        $offen = $this->offeneAnmeldung($request);

        if ($offen === null) {
            return $this->anmeldungNeuStarten($request, 'Die Anmeldung ist abgelaufen. Bitte gib dein Passwort erneut ein.');
        }

        $daten = $request->validate([
            'code' => ['nullable', 'string', 'max:20'],
            'recovery_code' => ['nullable', 'string', 'max:40'],
        ]);

        $user = User::query()->find($offen['id']);

        if (! $user || ! $user->hasTwoFactor()) {
            return $this->anmeldungNeuStarten($request, 'Bitte melde dich erneut an.');
        }

        $gueltig = false;

        if (! empty($daten['code'])) {
            $schritt = TwoFactor::verify($user->two_factor_secret, $daten['code'], $user->two_factor_last_step);

            if ($schritt !== null) {
                // Merken: Derselbe Code gilt kein zweites Mal
                $user->two_factor_last_step = $schritt;
                $user->save();
                $gueltig = true;
            }
        } elseif (! empty($daten['recovery_code'])) {
            $gueltig = TwoFactor::useRecoveryCode($user, $daten['recovery_code']);
        }

        if (! $gueltig) {
            $offen['fehlversuche']++;

            // Nach fuenf falschen Codes muss auch das Passwort neu her
            if ($offen['fehlversuche'] >= 5) {
                return $this->anmeldungNeuStarten($request, 'Zu viele falsche Codes. Bitte melde dich erneut an.');
            }

            $request->session()->put('login.2fa', $offen);

            throw ValidationException::withMessages([
                'code' => empty($daten['recovery_code'])
                    ? 'Der Code ist ungültig. Nimm den aktuellen Code aus der App.'
                    : 'Dieser Wiederherstellungscode ist ungültig oder wurde schon verwendet.',
            ]);
        }

        $request->session()->forget('login.2fa');

        Auth::guard('web')->login($user, (bool) $offen['remember']);
        $request->session()->regenerate();

        return response()->json(['user' => $user->clientData()]);
    }

    /** Offene 2FA-Anmeldung aus der Sitzung - hoechstens fuenf Minuten alt */
    private function offeneAnmeldung(Request $request): ?array
    {
        $offen = $request->session()->get('login.2fa');

        if (! is_array($offen) || time() - ($offen['seit'] ?? 0) > 300) {
            return null;
        }

        return $offen;
    }

    private function anmeldungNeuStarten(Request $request, string $meldung): JsonResponse
    {
        $request->session()->forget('login.2fa');

        return response()->json(['message' => $meldung, 'restart' => true], 422);
    }

    /**
     * Selbst registrieren. Das neue Konto beginnt mit einem leeren
     * Workspace und ist sofort angemeldet.
     */
    public function register(Request $request): JsonResponse
    {
        if (! config('rackview.registration')) {
            return response()->json(['message' => 'Die Registrierung ist abgeschaltet.'], 403);
        }

        // Klein geschrieben speichern - PostgreSQL vergleicht sonst
        // Gross-/Kleinschreibung, und die Anmeldung wuerde scheitern.
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $daten = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:10', 'max:255', 'confirmed'],
        ], [
            'name.required' => 'Bitte einen Namen angeben.',
            'name.max' => 'Der Name darf höchstens 255 Zeichen lang sein.',
            'email.required' => 'Bitte eine E-Mail-Adresse angeben.',
            'email.email' => 'Das ist keine gültige E-Mail-Adresse.',
            'email.max' => 'Die E-Mail-Adresse darf höchstens 255 Zeichen lang sein.',
            'email.unique' => 'Zu dieser E-Mail-Adresse gibt es schon ein Konto.',
            'password.required' => 'Bitte ein Passwort angeben.',
            'password.min' => 'Das Passwort muss mindestens 10 Zeichen lang sein.',
            'password.max' => 'Das Passwort darf höchstens 255 Zeichen lang sein.',
            'password.confirmed' => 'Die beiden Passwörter stimmen nicht überein.',
        ]);

        $user = Workspace::createAccount($daten['name'], $daten['email'], $daten['password']);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return response()->json(['user' => $user->clientData()], 201);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Abgemeldet.']);
    }
}
