<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AccountSessions;
use App\Services\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Zwei-Faktor-Anmeldung fuer das eigene Konto einrichten und verwalten.
 *
 * Einrichten, neue Wiederherstellungscodes und Abschalten laufen durch
 * "password.confirm" (Antwort 423, die Oberflaeche fragt nach).
 */
class TwoFactorController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $aktiv = $user->hasTwoFactor();

        return response()->json([
            'enabled' => $aktiv,
            'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
            'recovery_codes_left' => $aktiv ? count($user->two_factor_recovery_codes ?? []) : 0,
        ]);
    }

    /**
     * Einrichtung beginnen: neuen Schluessel erzeugen. Aktiv wird er erst,
     * wenn ein Code aus der App bestaetigt ist (confirm).
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactor()) {
            return response()->json(['message' => 'Die Zwei-Faktor-Anmeldung ist bereits aktiv.'], 422);
        }

        $secret = TwoFactor::newSecret();

        $user->two_factor_secret = $secret;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_last_step = null;
        $user->save();

        return response()->json([
            'secret' => $secret,
            'uri' => TwoFactor::uri($secret, $user->email),
        ]);
    }

    /** Ersten Code aus der App pruefen und 2FA damit einschalten */
    public function confirm(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate(
            ['code' => ['required', 'string', 'max:20']],
            ['code.required' => 'Bitte den Code aus der App eingeben.'],
        );

        if ($user->hasTwoFactor()) {
            return response()->json(['message' => 'Die Zwei-Faktor-Anmeldung ist bereits aktiv.'], 422);
        }

        if (empty($user->two_factor_secret)) {
            return response()->json(['message' => 'Bitte die Einrichtung zuerst starten.'], 422);
        }

        $schritt = TwoFactor::verify($user->two_factor_secret, (string) $request->input('code'));

        if ($schritt === null) {
            throw ValidationException::withMessages([
                'code' => 'Der Code stimmt nicht. Prüfe, ob die Uhrzeit auf dem Handy stimmt, und nimm den nächsten Code.',
            ]);
        }

        $wiederherstellung = TwoFactor::newRecoveryCodes();

        $user->two_factor_confirmed_at = now();
        $user->two_factor_last_step = $schritt;
        $user->two_factor_recovery_codes = $wiederherstellung['hashes'];
        $user->save();

        // Wie beim Passwortwechsel: andere Geraete abmelden, dieses bleibt
        $angemeldetBleiben = $request->hasCookie(Auth::guard('web')->getRecallerName());
        AccountSessions::end($user, $request->session()->getId());
        Auth::guard('web')->login($user, $angemeldetBleiben);

        return response()->json([
            'recovery_codes' => $wiederherstellung['codes'],
            'user' => $user->clientData(),
        ]);
    }

    /** Neue Wiederherstellungscodes - die alten werden ungueltig */
    public function regenerate(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactor()) {
            return response()->json(['message' => 'Die Zwei-Faktor-Anmeldung ist nicht aktiv.'], 422);
        }

        $wiederherstellung = TwoFactor::newRecoveryCodes();

        $user->two_factor_recovery_codes = $wiederherstellung['hashes'];
        $user->save();

        return response()->json(['recovery_codes' => $wiederherstellung['codes']]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        TwoFactor::disable($user);

        return response()->json(['user' => $user->clientData()]);
    }
}
