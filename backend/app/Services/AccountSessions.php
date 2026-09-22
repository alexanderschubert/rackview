<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Meldet ein Konto ab - z.B. nach einer Passwortaenderung oder wenn das
 * Konto geloescht wird.
 *
 * Angemeldet halten einen zwei Dinge: die Sitzung in der Datenbank und
 * das "Angemeldet bleiben"-Cookie. Beides wird hier ungueltig.
 */
class AccountSessions
{
    /**
     * @param  string|null  $ausser  Sitzungs-ID, die bestehen bleibt (die eigene)
     */
    public static function end(User $user, ?string $ausser = null): void
    {
        // Neues Token: alte "Angemeldet bleiben"-Cookies passen nicht mehr
        $user->setRememberToken(Str::random(60));
        $user->save();

        // Bei anderen Treibern (Datei, Cookie) laesst sich nicht gezielt
        // nach Konto suchen - dort laufen die Sitzungen regulaer aus.
        if (config('session.driver') !== 'database') {
            return;
        }

        DB::connection(config('session.connection'))
            ->table(config('session.table', 'sessions'))
            ->where('user_id', $user->getKey())
            ->when($ausser, fn ($abfrage) => $abfrage->where('id', '!=', $ausser))
            ->delete();
    }
}
