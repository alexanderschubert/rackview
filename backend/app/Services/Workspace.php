<?php

namespace App\Services;

use App\Models\Device;
use App\Models\Rack;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Jedes Konto ist ein eigener, streng getrennter Workspace: Es sieht nur
 * die eigenen Racks samt Geraeten, Ports und Verbindungen.
 */
class Workspace
{
    /**
     * Konto anlegen - aus der Registrierung, der Kontoverwaltung oder
     * per "php artisan rackview:user". Das allererste Konto wird Admin
     * und uebernimmt Racks, die noch niemandem gehoeren.
     */
    public static function createAccount(string $name, string $email, string $passwort, bool $admin = false): User
    {
        return DB::transaction(function () use ($name, $email, $passwort, $admin) {
            $erstesKonto = ! User::query()->exists();

            $user = new User([
                'name' => trim($name),
                'email' => strtolower(trim($email)),
                'password' => $passwort,
            ]);

            $user->is_admin = $admin || $erstesKonto;
            $user->save();

            if ($erstesKonto) {
                self::adoptOrphans($user);
            }

            return $user;
        });
    }

    /**
     * Racks ohne Besitzer (angelegt, bevor es Workspaces gab) dem Konto
     * zuordnen. Die Migration erledigt das fuer bestehende Konten.
     */
    public static function adoptOrphans(User $user): int
    {
        return Rack::query()->whereNull('user_id')->update(['user_id' => $user->id]);
    }

    /**
     * Konto samt Workspace loeschen. Geraete einzeln, damit ihr
     * deleting-Ereignis die hochgeladenen Fotos mit entfernt; Ports und
     * Verbindungen fallen per Kaskade in der Datenbank.
     */
    public static function deleteAccount(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->racks()->with('devices')->get()->each(function (Rack $rack) {
                $rack->devices->each(fn (Device $device) => $device->delete());
                $rack->delete();
            });

            AccountSessions::end($user);

            $user->delete();
        });
    }
}
