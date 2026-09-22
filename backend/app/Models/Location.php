<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ein Standort ausserhalb der Racks - Flur, Dachboden, Carport.
 *
 * Gegenstueck zum Rack fuer alles, was keine Hoeheneinheiten hat.
 */
class Location extends Model
{
    protected $fillable = [
        'name',
        'description',
    ];

    protected $casts = [
        'user_id' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** Nur Standorte dieses Kontos */
    public function scopeOwnedBy(Builder $query, ?int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }
}
