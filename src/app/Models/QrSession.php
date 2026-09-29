<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A single rotation of the QR code displayed by a payment station.
 *
 * The public handle is uuid. The rotating token may bind the row once; after
 * binding, the SHA-256 client-secret hash is the credential for public calls.
 */
class QrSession extends Model
{
    protected $table = 'pos_qr_sessions';

    protected static function booted(): void
    {
        static::creating(function (self $session): void {
            if ($session->origin === 'table_card' && $session->table_id !== null) {
                $token = Table::query()->whereKey($session->table_id)->value('qr_token');
                $session->table_qr_token_hash = $token === null ? null : hash('sha256', $token);
            }
        });
    }

    protected $guarded = [];

    protected $hidden = [
        'token',
        'client_secret_hash',
        'table_qr_token_hash',
    ];

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_ORDERED = 'ordered';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_EXPIRED = 'expired';

    /** @var list<string> */
    public const CREDENTIAL_STATUSES = [self::STATUS_ACTIVE, self::STATUS_ORDERED];

    /** @var list<string> */
    public const EXPIRABLE_STATUSES = [self::STATUS_PENDING, self::STATUS_ACTIVE, self::STATUS_ORDERED];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token_expires_at' => 'datetime',
            'bound_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'secret_rotated_at' => 'datetime',
            'expires_at' => 'datetime',
            'closed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public static function hashClientSecret(string $clientSecret): string
    {
        return hash('sha256', $clientSecret);
    }

    public function clientSecretMatches(string $clientSecret): bool
    {
        return is_string($this->client_secret_hash)
            && $this->client_secret_hash !== ''
            && hash_equals($this->client_secret_hash, self::hashClientSecret($clientSecret));
    }

    public function isExpiredAt(CarbonInterface $at): bool
    {
        return $this->expires_at === null || $this->expires_at->lte($at);
    }

    /**
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class, 'qr_session_id');
    }

    /**
     * @return BelongsTo<Table, $this>
     */
    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    /** @return BelongsTo<TableSession, $this> */
    public function tableSession(): BelongsTo
    {
        return $this->belongsTo(TableSession::class, 'table_session_id');
    }

    /**
     * @return HasMany<QrOrderRound, $this>
     */
    public function rounds(): HasMany
    {
        return $this->hasMany(QrOrderRound::class, 'qr_session_id');
    }

    /** @return BelongsTo<QrSession, $this> */
    public function handoverFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'handover_from_id');
    }

    public function isDineIn(): bool
    {
        return $this->table_id !== null;
    }
}
