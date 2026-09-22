<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rack extends Model
{
    protected $fillable = [
        'name',
        'location',
        'height_units',
        'description',
    ];

    // user_id bewusst nicht in $fillable: Der Besitzer kommt nie aus
    // der Anfrage, sondern immer aus der Anmeldung.
    protected $casts = [
        'height_units' => 'integer',
        'user_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Nur die Racks dieses Kontos. Ohne Konto gar keine - auch nicht die
     * herrenlosen (user_id NULL), die where('user_id', null) sonst liefern
     * wuerde.
     */
    public function scopeOwnedBy(Builder $query, ?int $userId): Builder
    {
        return $userId ? $query->where('user_id', $userId) : $query->whereRaw('1 = 0');
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }
}
