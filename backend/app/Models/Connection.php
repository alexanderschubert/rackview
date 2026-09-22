<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Connection extends Model
{
    protected $fillable = [
        'source_device_id', 'source_port', 'target_device_id', 'target_port',
        'cable_type', 'cable_color', 'cable_length', 'status', 'notes',
    ];

    protected $casts = [
        'source_device_id' => 'integer',
        'target_device_id' => 'integer',
        'cable_length' => 'integer',
    ];

    public function sourceDevice(): BelongsTo { return $this->belongsTo(Device::class, 'source_device_id'); }
    public function targetDevice(): BelongsTo { return $this->belongsTo(Device::class, 'target_device_id'); }
}
