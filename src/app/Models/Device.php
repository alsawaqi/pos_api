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
use Illuminate\Support\Str;

/**
 * Phase 8 — the device record (shared pos_devices table, owned by
 * pos_admin's schema). pos_api treats it as the authenticatable
 * subject of the `pos_device` guard: the long-lived `device_token`
 * column stores only SHA-256 of the Bearer credential presented on
 * every request, bound to the company and branch at activation.
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
    'token_company_id',
    'token_branch_id',
    'token_issued_at',
    'pending_outbox_count',
    'quarantined_count',
    'outbox_reported_at',
    'printer_status',
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

    /** Returned once at activation; never persisted or included in serialization. */
    public ?string $plainTextToken = null;

    /** Reviewed historical settlement must never consult or mutate live device state. */
    public ?array $reviewedSoftposSnapshot = null;

    public array $syncIntegrityFlags = [];

    protected $hidden = ['device_token'];

    public function issueCredential(): void
    {
        $this->plainTextToken = 'mdev_'.Str::random(60);
        $this->forceFill([
            'device_token' => hash('sha256', $this->plainTextToken),
            'token_company_id' => $this->company_id,
            'token_branch_id' => $this->branch_id,
            // This is the first activation of the current assignment, not a
            // sliding cutoff each time the same terminal is reactivated.
            'token_issued_at' => $this->assignment_activated_at
                ?? (((int) $this->token_company_id === (int) $this->company_id
                    && (int) $this->token_branch_id === (int) $this->branch_id)
                    ? $this->token_issued_at : now()),
            'assignment_activated_at' => $this->assignment_activated_at
                ?? (((int) $this->token_company_id === (int) $this->company_id
                    && (int) $this->token_branch_id === (int) $this->branch_id)
                    ? $this->token_issued_at : now()),
            'status' => 'active',
            'last_seen_at' => now(),
            'pending_outbox_count' => null,
            'outbox_reported_at' => null,
        ])->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
            'auth_v_seen_at' => 'datetime',
            'assigned_at' => 'datetime',
            'token_issued_at' => 'datetime',
            'assignment_activated_at' => 'datetime',
            'serial_verified_at' => 'datetime',
            'location_mode_since' => 'datetime',
            'location_any_started_at' => 'datetime',
            'location_any_windows' => 'array',
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

    /**
     * LAUNCH-P1 decision 1a (P1-10) — the app name a device reports at
     * activation → the only device_type that app may activate.
     */
    public const ACTIVATION_APP_TYPES = [
        'till' => 'fixed_pos',
        'handheld' => 'handheld',
        'station' => 'payment_station',
        'customer_tablet' => 'customer_tablet',
    ];

    public function acceptsActivationApp(string $app): bool
    {
        return (self::ACTIVATION_APP_TYPES[strtolower(trim($app))] ?? null) === $this->device_type;
    }

    /**
     * LAUNCH-P1 decision 2a — 'branch' (geofenced to the branch, the default)
     * or 'any' (may work at any location). Anything unexpected is 'branch'.
     */
    public function locationMode(): string
    {
        return $this->getAttribute('location_mode') === 'any' ? 'any' : 'branch';
    }

    public function isPaymentStation(): bool
    {
        return $this->device_type === 'payment_station';
    }

    /**
     * LAUNCH-P5 fix order 1 (F8) — the device types staff log in on (a till
     * and a handheld). Only these may log staff in, check a manager PIN,
     * unlock a PIN lock, read the approvers or the staff status, and record
     * attendance; a customer tablet or a payment station faces the customer.
     */
    public const ATTENDED_TYPES = ['fixed_pos', 'handheld'];

    public function isAttended(): bool
    {
        return in_array($this->device_type, self::ATTENDED_TYPES, true);
    }
}
