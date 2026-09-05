<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\TableSession;

/** Rejects a complete pending staff round without touching money or children. */
final class RejectStaffRoundAction
{
    public function __construct(
        private readonly ConfirmStaffRoundAction $review,
        private readonly AppendTableSessionEventAction $journal,
    ) {}

    /** @return array<string, mixed> */
    public function handle(Device $device, string $uuid, int $roundId): array
    {
        return $this->review->locked($device, $uuid, $roundId, function (Device $device, TableSession $seating, Order $order, QrOrderRound $round): array {
            if ($round->status !== QrOrderRound::STATUS_PENDING_CONFIRMATION) {
                return $this->review->present($seating, $order, $round, 'replayed');
            }
            $round->update([
                'status' => QrOrderRound::STATUS_REJECTED,
                'resolved_at' => now(),
                'resolved_by_device_id' => $device->id,
                'confirm_payload' => null,
            ]);
            $this->journal->handle($seating, 'round_resolved', [
                'round_id' => (int) $round->id,
                'order_uuid' => $order->uuid,
                'outcome' => QrOrderRound::STATUS_REJECTED,
                'dropped_line_count' => 0,
            ], (int) $device->id);

            return $this->review->present($seating, $order, $round->fresh(), 'rejected');
        });
    }
}
