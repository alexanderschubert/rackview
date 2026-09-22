<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AccountSessions;
use App\Services\TwoFactor;
use App\Services\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Konten verwalten - nur fuer Admins (Route-Middleware "can:admin").
 *
 * Jedes Konto hat seinen eigenen Workspace; auch Admins sehen fremde Racks
 * nicht. Anlegen, Aendern und Loeschen laufen zusaetzlich durch
 * "password.confirm": Wer ein Passwort zuruecksetzt oder ein Konto samt
 * Daten loescht, muss vorher das eigene Passwort bestaetigen.
 */
class UserController extends Controller
{
    private const MELDUNGEN = [
        'name.required' => 'Bitte einen Namen angeben.',
        'name.max' => 'Der Name darf höchstens 255 Zeichen lang sein.',
        'email.required' => 'Bitte eine E-Mail-Adresse angeben.',
        'email.email' => 'Das ist keine gültige E-Mail-Adresse.',
        'email.max' => 'Die E-Mail-Adresse darf höchstens 255 Zeichen lang sein.',
        'email.unique' => 'Diese E-Mail-Adresse gehört bereits zu einem anderen Konto.',
        'password.required' => 'Bitte ein Passwort angeben.',
        'password.min' => 'Das Passwort muss mindestens 10 Zeichen lang sein.',
        'password.max' => 'Das Passwort darf höchstens 255 Zeichen lang sein.',
        'password.confirmed' => 'Die beiden Passwörter stimmen nicht überein.',
    ];

    public function index(): JsonResponse
    {
        // Letzte Aktivitaet aus der Sitzungstabelle - ohne eigene Spalte
        $zuletztAktiv = config('session.driver') === 'database'
            ? DB::connection(config('session.connection'))
                ->table(config('session.table', 'sessions'))
                ->selectRaw('user_id, max(last_activity) as zuletzt')
                ->whereNotNull('user_id')
                ->groupBy('user_id')
                ->pluck('zuletzt', 'user_id')
            : collect();

        $konten = User::query()
            ->select(['id', 'name', 'email', 'is_admin', 'created_at', 'two_factor_confirmed_at'])
            ->withCount('racks')
            ->orderBy('name')
            ->get()
            ->map(fn (User $user) => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => (bool) $user->is_admin,
                'racks_count' => $user->racks_count,
                'two_factor' => $user->two_factor_confirmed_at !== null,
                'created_at' => $user->created_at?->toIso8601String(),
                'last_active_at' => isset($zuletztAktiv[$user->id])
                    ? Carbon::createFromTimestamp((int) $zuletztAktiv[$user->id])->toIso8601String()
                    : null,
            ]);

        return response()->json($konten);
    }

    public function store(Request $request): JsonResponse
    {
        $this->normalisiereEmail($request);

        $daten = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:10', 'max:255', 'confirmed'],
            'is_admin' => ['sometimes', 'boolean'],
        ], self::MELDUNGEN);

        $user = Workspace::createAccount(
            $daten['name'],
            $daten['email'],
            $daten['password'],
            (bool) ($daten['is_admin'] ?? false),
        );

        return response()->json($user->clientData(), 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $this->normalisiereEmail($request);

        $daten = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', 'string', 'min:10', 'max:255', 'confirmed'],
            'is_admin' => ['sometimes', 'boolean'],
            // Handy verloren: Admin schaltet 2FA ab, die Person richtet neu ein
            'disable_two_factor' => ['sometimes', 'boolean'],
        ], self::MELDUNGEN);

        $neuesPasswort = $daten['password'] ?? null;
        $eigenesKonto = $user->is($request->user());

        // Das eigene Passwort nur mit dem bisherigen aendern (Profil)
        if ($neuesPasswort && $eigenesKonto) {
            return response()->json([
                'message' => 'Dein eigenes Passwort änderst du oben unter „Passwort ändern".',
            ], 422);
        }

        // Sich selbst die Adminrechte nehmen hiesse, sich auszusperren
        if ($eigenesKonto && array_key_exists('is_admin', $daten) && ! $daten['is_admin']) {
            return response()->json([
                'message' => 'Du kannst dir die Adminrechte nicht selbst entziehen.',
            ], 422);
        }

        $user->fill(['name' => trim($daten['name']), 'email' => $daten['email']]);

        if (array_key_exists('is_admin', $daten)) {
            $user->is_admin = (bool) $daten['is_admin'];
        }

        if ($neuesPasswort) {
            $user->password = $neuesPasswort;
        }

        $user->save();

        if (! $eigenesKonto && ! empty($daten['disable_two_factor'])) {
            TwoFactor::disable($user);
        }

        // Mit dem alten Passwort angemeldete Geraete abmelden
        if ($neuesPasswort) {
            AccountSessions::end($user);
        }

        return response()->json($user->clientData());
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        // Sichert zugleich, dass immer mindestens ein Konto bleibt
        if ($user->is($request->user())) {
            return response()->json([
                'message' => 'Das eigene Konto kann nicht gelöscht werden.',
            ], 422);
        }

        // Samt Workspace: Racks, Geraete, Ports, Verbindungen, Fotos
        Workspace::deleteAccount($user);

        return response()->json(['message' => 'Konto gelöscht.']);
    }

    // Klein geschrieben speichern - PostgreSQL vergleicht sonst
    // Gross-/Kleinschreibung, und die Anmeldung wuerde scheitern.
    private function normalisiereEmail(Request $request): void
    {
        $request->merge(['email' => strtolower(trim((string) $request->input('email')))]);
    }
}
