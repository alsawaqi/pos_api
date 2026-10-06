<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** LAUNCH-P6 — the append-only audit of a tablet order (ids and outcomes only). */
final class TabletOrderEvent extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'pos_tablet_order_events';

    protected $guarded = [];

    public const TYPES = ['submitted', 'taken', 'taken_over', 'sent_to_kitchen', 'redeem_approved', 'redeem_rejected',
        // Fix order 1 (F-2, F-6, F-8).
        'redeem_superseded', 'round_rejected', 'edited'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['payload' => 'array', 'created_at' => 'datetime'];
    }

    /** @param array<string, mixed> $payload */
    public static function record(TabletOrder $order, string $type, ?int $staffId, ?int $deviceId, array $payload = []): self
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('Unknown tablet order event.');
        }

        return self::query()->create([
            'company_id' => (int) $order->company_id,
            'branch_id' => (int) $order->branch_id,
            'tablet_order_id' => (int) $order->id,
            'event_type' => $type,
            'staff_id' => $staffId,
            'device_id' => $deviceId,
            'payload' => $payload === [] ? null : $payload,
            'created_at' => now(),
        ]);
    }
}
