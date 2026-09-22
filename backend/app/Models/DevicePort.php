<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class DevicePort extends Model {
 protected $fillable=['device_id','name','port_type','speed','poe','status','vlan','notes'];
 protected $casts=['device_id'=>'integer'];
 public function device(): BelongsTo { return $this->belongsTo(Device::class); }

 /** Nur Ports von Geraeten dieses Kontos */
 public function scopeOwnedBy(Builder $query, ?int $userId): Builder
 {
     return $query->whereHas('device', fn (Builder $device) => $device->ownedBy($userId));
 }
}
