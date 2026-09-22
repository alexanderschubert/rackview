<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AccountSessions;
use App\Services\TwoFactor;
use App\Services\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Legt ein Konto an oder setzt das Passwort eines bestehenden zurueck.
 *
 *   php artisan rackview:user admin@example.org --name="Alexander"
 *   php artisan rackview:user admin@example.org --admin
 *   php artisan rackview:user admin@example.org --disable-2fa
 *
 * Mit --admin wird das Konto (auch ein bestehendes) zum Admin - der Weg
 * zurueck, falls sich alle Admins ausgesperrt haben.
 *
 * Das Passwort wird verdeckt abgefragt und landet so weder in der
 * Shell-History noch in der Prozessliste.
 */
class CreateUser extends Command
{
    protected $signature = 'rackview:user
        {email : E-Mail-Adresse, mit der man sich anmeldet}
        {--name= : Anzeigename (nur beim Anlegen noetig)}
        {--admin : Konto zum Admin machen}
        {--disable-2fa : Zwei-Faktor-Anmeldung abschalten (Handy verloren)}';

    protected $description = 'RackView-Konto anlegen oder Passwort zuruecksetzen';

    public function handle(): int
    {
        $email = strtolower(trim($this->argument('email')));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email']])->fails()) {
            $this->error('Das ist keine gültige E-Mail-Adresse.');
            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        return $user ? $this->bestehendesKonto($user) : $this->neuesKonto($email);
    }

    private function neuesKonto(string $email): int
    {
        $name = $this->option('name') ?: $this->ask('Anzeigename');

        if (! trim((string) $name)) {
            $this->error('Ein Anzeigename wird benötigt.');
            return self::FAILURE;
        }

        $passwort = $this->passwortAbfragen();

        if ($passwort === null) {
            return self::FAILURE;
        }

        $neu = Workspace::createAccount($name, $email, $passwort, (bool) $this->option('admin'));
        $this->info("Konto {$email} angelegt. Die Anmeldung ist jetzt möglich.");

        if ($neu->is_admin) {
            $this->info('Das Konto ist Admin.');
        }

        return self::SUCCESS;
    }

    /**
     * Ohne Schalter wird das Passwort neu gesetzt. Mit --admin oder
     * --disable-2fa nur auf Nachfrage - das Handy kann weg sein, obwohl
     * das Passwort noch bekannt ist.
     */
    private function bestehendesKonto(User $user): int
    {
        $schalter = $this->option('admin') || $this->option('disable-2fa');

        $passwortNeu = ! $schalter || $this->confirm('Passwort ebenfalls neu setzen?', false);
        $passwort = null;

        if ($passwortNeu) {
            $this->info("Konto {$user->email} existiert – das Passwort wird neu gesetzt.");
            $passwort = $this->passwortAbfragen();

            if ($passwort === null) {
                return self::FAILURE;
            }

            // Der Cast "hashed" im User-Modell verschluesselt das Passwort
            $user->password = $passwort;
        }

        if ($this->option('admin')) {
            $user->is_admin = true;
        }

        $user->save();

        if ($this->option('disable-2fa')) {
            TwoFactor::disable($user);
            $this->info('Zwei-Faktor-Anmeldung abgeschaltet. Sie lässt sich in den Einstellungen neu einrichten.');
        }

        if ($passwortNeu) {
            // Wer das alte Passwort kannte, soll nicht angemeldet bleiben
            AccountSessions::end($user);
            $this->info('Passwort geändert, alle Sitzungen des Kontos beendet.');
        }

        if ($this->option('admin')) {
            $this->info('Das Konto ist jetzt Admin.');
        }

        return self::SUCCESS;
    }

    /** Passwort verdeckt abfragen; null bei Fehler (Meldung ist schon ausgegeben) */
    private function passwortAbfragen(): ?string
    {
        $passwort = $this->secret('Neues Passwort (mindestens 10 Zeichen)');
        $wiederholung = $this->secret('Passwort wiederholen');

        if ($passwort !== $wiederholung) {
            $this->error('Die Passwörter stimmen nicht überein.');
            return null;
        }

        $pruefung = Validator::make(['password' => $passwort], ['password' => ['required', Password::min(10)]]);

        if ($pruefung->fails()) {
            $this->error('Das Passwort muss mindestens 10 Zeichen lang sein.');
            return null;
        }

        return $passwort;
    }
}
