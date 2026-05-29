<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DeviceActivationTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Phase 8 — a one-time device pairing credential (shared
 * pos_device_activation_tokens table, minted by the Admin Portal).
 *
 * Stored as a SHA-256 hash (token_hash); the plaintext is shown to
 * the admin once and entered on the device during pairing. Pairing
 * marks it used_at; an unused, unrevoked, unexpired token is the
 * only one that can pair.
 */
#[Fillable([
    'device_id',
    'token_hash',
    'created_by_user_id',
    'expires_at',
    'used_at',
    'revoked_at',
])]
class DeviceActivationToken extends Model
{
    /** @use HasFactory<DeviceActivationTokenFactory> */
    use HasFactory;

    protected $table = 'pos_device_activation_tokens';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Usable = never used, never revoked, not expired.
     */
    public function isUsable(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && $this->expires_at !== null
            && $this->expires_at->isFuture();
    }

    /**
     * SHA-256 of the plaintext, matching how the Admin Portal stores it.
     */
    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }
}
