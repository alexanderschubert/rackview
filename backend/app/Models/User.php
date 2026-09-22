<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// is_admin bewusst nicht fillable: wird nur gezielt gesetzt, nie aus
// einer Anfrage uebernommen.
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            // Mit APP_KEY verschluesselt - ein Datenbank-Dump allein
            // verraet den Schluessel fuer die Einmalcodes nicht
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_step' => 'integer',
        ];
    }

    /** Zwei-Faktor-Anmeldung eingerichtet und bestaetigt? */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && ! empty($this->two_factor_secret);
    }

    /** Der Workspace: jedes Konto hat seine eigenen Racks */
    public function racks(): HasMany
    {
        return $this->hasMany(Rack::class);
    }

    /** Standorte ausserhalb der Racks - Flur, Dachboden, Carport */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /** Was die Oberflaeche ueber das angemeldete Konto erfaehrt */
    public function clientData(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'is_admin' => (bool) $this->is_admin,
            'two_factor' => $this->hasTwoFactor(),
            // Meldet sich ueber den OIDC-Anbieter an
            'via_oidc' => (bool) $this->oidc_sub,
        ];
    }
}
