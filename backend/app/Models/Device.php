<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'rack_id',
        'location_id',
        'name',
        'manufacturer',
        'model',
        'device_type',
        'image_path',
        'status',
        'height_units',
        'start_unit',
        'mount_side',
        'serial_number',
        'ip_address',
        'mac_address',
        'vlan',
        'switch_port',
        'uplink_port',
        'network_ports',
        'poe_ports',
        'poe_type',
        'outlet_count',
        'purchase_date',
        'warranty_until',
        'description',
        'notes',
    ];

    /**
     * image_url wird mit ausgeliefert, damit das Frontend den
     * Pfad nicht selbst zusammensetzen muss.
     */
    protected $appends = [
        'image_url',
    ];

    protected $casts = [
        'rack_id' => 'integer',
        'location_id' => 'integer',
        'height_units' => 'integer',
        'start_unit' => 'integer',
        'network_ports' => 'integer',
        'poe_ports' => 'integer',
        'outlet_count' => 'integer',
        // Nur das Datum, ohne Uhrzeit: So kommt es als "2026-09-21"
        // im JSON an und passt ohne Umrechnung in ein <input type="date">.
        'purchase_date' => 'date:Y-m-d',
        'warranty_until' => 'date:Y-m-d',
    ];

    /**
     * Bewusst ein relativer Pfad: Hinter einem Reverse Proxy ist die
     * Adresse, unter der die Anwendung erreichbar ist, nicht bekannt.
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path
            ? '/storage/'.ltrim($this->image_path, '/')
            : null;
    }

    /**
     * Steckplaetze, wenn das Geraet eine Steckdosenleiste oder USV ist.
     * Der Zaehler heisst outlet_count und nicht outlets - sonst wuerde
     * die Spalte die Beziehung verdecken.
     */
    public function outlets(): HasMany
    {
        return $this->hasMany(PowerOutlet::class)->orderBy('position');
    }

    /**
     * Beim Loeschen eines Geraets darf die Bilddatei nicht zurueckbleiben.
     */
    protected static function booted(): void
    {
        static::deleting(function (Device $device): void {
            if ($device->image_path) {
                Storage::disk('public')->delete($device->image_path);
            }

            // Alles, was zu diesem Geraet hochgeladen wurde, liegt hier.
            Storage::disk('public')->deleteDirectory("device-images/{$device->id}");
        });
    }

    public function ports(): HasMany
    {
        return $this->hasMany(DevicePort::class);
    }

    public function outgoingConnections(): HasMany
    {
        return $this->hasMany(Connection::class, 'source_device_id');
    }

    public function incomingConnections(): HasMany
    {
        return $this->hasMany(Connection::class, 'target_device_id');
    }

    public function rack(): BelongsTo
    {
        return $this->belongsTo(Rack::class);
    }

    /** Der Standort, falls das Geraet nicht im Rack steht */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * Nur Geraete dieses Kontos.
     *
     * Ein Geraet haengt entweder an einem Rack oder an einem Standort;
     * beide gehoeren zu genau einem Konto. Bewusst kein eigenes
     * user_id am Geraet: Dann gaebe es zwei Stellen, die dasselbe
     * behaupten, und irgendwann widersprechen sie sich.
     */
    public function scopeOwnedBy(Builder $query, ?int $userId): Builder
    {
        return $query->where(fn (Builder $frage) => $frage
            ->whereHas('rack', fn (Builder $rack) => $rack->ownedBy($userId))
            ->orWhereHas('location', fn (Builder $ort) => $ort->ownedBy($userId)));
    }
}
