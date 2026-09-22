<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PortConnection extends Model
{
    protected $fillable = [
        'source_device_id',
        'source_port_id',
        'target_device_id',
        'target_port_id',
        'connection_type',
        'cable_type',
        'cable_length',
        'status',
        'notes',
    ];

    protected $casts = [
        'source_device_id' => 'integer',
        'source_port_id' => 'integer',
        'target_device_id' => 'integer',
        'target_port_id' => 'integer',
    ];

    public function sourceDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'source_device_id');
    }

    public function sourcePort(): BelongsTo
    {
        return $this->belongsTo(DevicePort::class, 'source_port_id');
    }

    public function targetDevice(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'target_device_id');
    }

    public function targetPort(): BelongsTo
    {
        return $this->belongsTo(DevicePort::class, 'target_port_id');
    }

    /**
     * Nur Verbindungen dieses Kontos. Beide Enden werden geprueft - der
     * Controller laesst ohnehin nur Verbindungen innerhalb eines Kontos zu.
     */
    public function scopeOwnedBy(Builder $query, ?int $userId): Builder
    {
        return $query
            ->whereHas('sourceDevice', fn (Builder $device) => $device->ownedBy($userId))
            ->whereHas('targetDevice', fn (Builder $device) => $device->ownedBy($userId));
    }
}
