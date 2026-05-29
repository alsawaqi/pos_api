<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DeviceFactory;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Phase 8 — the device record (shared pos_devices table, owned by
 * pos_admin's schema). pos_api treats it as the authenticatable
 * subject of the `pos_device` guard: the long-lived `device_token`
 * column is the Bearer credential a paired terminal presents on
 * every request.
 *
 * Provisioning (register / assign / generate activation token)
 * happens in the Admin Portal; pos_api only PAIRS (consumes an
 * activation token → issues a device_token) and serves the device.
 *
 * Geofence + health columns (last_lat/last_lng/last_battery/
 * last_seen_at) are updated by the heartbeat endpoint.
 */
#[Fillable([
    'device_token',
    'status',
    'last_seen_at',
    'last_ip',
    'last_lat',
    'last_lng',
    'last_battery',
    'app_version',
])]
class Device extends Model implements Authenticatable
{
    /** @use HasFactory<DeviceFactory> */
    use AuthenticatableTrait, HasFactory, SoftDeletes;

    protected $table = 'pos_devices';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'assigned_at' => 'datetime',
            'last_lat' => 'decimal:7',
            'last_lng' => 'decimal:7',
            'last_battery' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * One-time activation tokens minted by the Admin Portal for this
     * device. Pairing consumes the first usable one.
     *
     * @return HasMany<DeviceActivationToken, $this>
     */
    public function activationTokens(): HasMany
    {
        return $this->hasMany(DeviceActivationToken::class);
    }

    /**
     * A device is operable once it's been assigned to a branch. The
     * `pos_device` guard additionally requires a matching device_token.
     */
    public function isAssigned(): bool
    {
        return $this->company_id !== null && $this->branch_id !== null;
    }
}
