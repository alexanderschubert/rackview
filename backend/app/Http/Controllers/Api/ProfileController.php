<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountSessions;
use App\Services\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Das eigene Konto: Name, E-Mail-Adresse und Passwort.
 */
class ProfileController extends Controller
{
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        // Klein geschrieben speichern - PostgreSQL vergleicht sonst
        // Gross-/Kleinschreibung, und die Anmeldung wuerde scheitern.
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);

        $daten = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ], [
            'name.required' => 'Bitte einen Namen angeben.',
            'name.max' => 'Der Name darf höchstens 255 Zeichen lang sein.',
            'email.required' => 'Bitte eine E-Mail-Adresse angeben.',
            'email.email' => 'Das ist keine gültige E-Mail-Adresse.',
            'email.max' => 'Die E-Mail-Adresse darf höchstens 255 Zeichen lang sein.',
            'email.unique' => 'Diese E-Mail-Adresse gehört bereits zu einem anderen Konto.',
        ]);

        $user->update(['name' => trim($daten['name']), 'email' => $daten['email']]);

        return response()->json([
            'user' => $user->clientData(),
        ]);
    }

    /**
     * Eigenes Passwort aendern. Verlangt das bisherige Passwort, damit
     * niemand an einem kurz unbeaufsichtigten Rechner das Konto kapert.
     */
    public function password(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => ['required', 'string', 'current_password'],
            'password' => ['required', 'string', 'min:10', 'max:255', 'confirmed'],
        ], [
            'current_password.required' => 'Bitte das aktuelle Passwort angeben.',
            'current_password.current_password' => 'Das aktuelle Passwort ist falsch.',
            'password.required' => 'Bitte ein neues Passwort angeben.',
            'password.min' => 'Das neue Passwort muss mindestens 10 Zeichen lang sein.',
            'password.max' => 'Das neue Passwort darf höchstens 255 Zeichen lang sein.',
            'password.confirmed' => 'Die beiden neuen Passwörter stimmen nicht überein.',
        ]);

        // Vor dem Abmelden merken, ob hier "Angemeldet bleiben" aktiv war
        $angemeldetBleiben = $request->hasCookie(Auth::guard('web')->getRecallerName());

        // Der Cast "hashed" im User-Modell verschluesselt das Passwort
        $user->update(['password' => $request->input('password')]);

        // Alle anderen Geraete abmelden; diese Sitzung bleibt bestehen.
        AccountSessions::end($user, $request->session()->getId());

        // Neu anmelden: neue Sitzungs-ID und - falls gewuenscht - ein
        // frisches "Angemeldet bleiben"-Cookie mit dem neuen Token.
        Auth::guard('web')->login($user, $angemeldetBleiben);

        return response()->json([
            'message' => 'Passwort geändert. Andere Geräte, auf denen du angemeldet warst, wurden abgemeldet.',
        ]);
    }

    /**
     * Passwort bestaetigen, bevor Konten verwaltet werden. Laravel merkt
     * sich den Zeitpunkt in der Sitzung; die Middleware "password.confirm"
     * verlangt danach drei Stunden lang keine erneute Eingabe.
     */
    public function confirmPassword(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string', 'current_password'],
        ], [
            'password.required' => 'Bitte dein Passwort angeben.',
            'password.current_password' => 'Das Passwort ist falsch.',
        ]);

        $request->session()->passwordConfirmed();

        return response()->json(['message' => 'Passwort bestätigt.']);
    }

    /**
     * Eigenes Konto samt Workspace loeschen: alle Racks, Geraete, Ports,
     * Verbindungen und Fotos. Verlangt das Passwort im selben Aufruf.
     */
    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        $request->validate([
            'password' => ['required', 'string', 'current_password'],
        ], [
            'password.required' => 'Bitte dein Passwort angeben.',
            'password.current_password' => 'Das Passwort ist falsch.',
        ]);

        // Ohne Admin liesse sich kein Konto mehr verwalten
        $andereAdmins = User::query()->where('is_admin', true)->whereKeyNot($user->id)->exists();
        $andereKonten = User::query()->whereKeyNot($user->id)->exists();

        if ($user->is_admin && $andereKonten && ! $andereAdmins) {
            return response()->json([
                'message' => 'Du bist der einzige Admin. Ernenne zuerst ein anderes Konto zum Admin.',
            ], 422);
        }

        Auth::guard('web')->logout();
        Workspace::deleteAccount($user);

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Konto gelöscht.']);
    }
}
