<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ein Steckplatz einer Steckdosenleiste oder USV.
 *
 * Belegt ist er entweder mit einem Geraet aus dem eigenen Bereich
 * (connected_device_id) oder mit freiem Text (external_label) - nie
 * mit beidem. Darum kuemmert sich der Controller.
 */
class PowerOutlet extends Model
{
    protected $fillable = [
        'position',
        'label',
        'connected_device_id',
        'external_label',
        'notes',
    ];

    protected $casts = [
        'device_id' => 'integer',
        'position' => 'integer',
        'connected_device_id' => 'integer',
    ];

    /** Die Leiste, zu der dieser Platz gehoert */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /** Das angeschlossene Geraet, falls eines eingetragen ist */
    public function connectedDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'connected_device_id');
    }

    /** Nur Steckplaetze an Geraeten dieses Kontos */
    public function scopeOwnedBy(Builder $query, ?int $userId): Builder
    {
        return $query->whereHas('device', fn (Builder $device) => $device->ownedBy($userId));
    }
}
