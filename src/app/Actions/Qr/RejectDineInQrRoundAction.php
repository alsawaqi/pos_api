<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Tables\AppendTableSessionEventAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;

/** Makes a pending round terminal without appending any order children. */
final class RejectDineInQrRoundAction
{
    public function __construct(
        private readonly WithLockedDineInQrRoundAction $locked,
        private readonly PresentDeviceQrRoundAction $present,
        private readonly AppendTableSessionEventAction $journal,
    ) {}

    /** @return array<string, mixed> */
    public function handle(Device $device, int $roundId): array
    {
        return $this->locked->handle(
            $device,
            $roundId,
            function (Order $order, QrSession $session, QrOrderRound $round) use ($device): array {
                if ($round->status !== QrOrderRound::STATUS_PENDING_CONFIRMATION) {
                    throw new QrDineInException(
                        'qr_round_not_pending',
                        409,
                        'This QR round is no longer awaiting confirmation.',
                    );
                }

                $round->update([
                    'status' => QrOrderRound::STATUS_REJECTED,
                    'resolved_at' => now(),
                    'resolved_by_device_id' => $device->getKey(),
                    'confirm_payload' => null,
                ]);
                $this->journal->forOrder($order, 'round_resolved', [
                    'round_id' => (int) $round->id,
                    'outcome' => QrOrderRound::STATUS_REJECTED,
                ], (int) $device->id);

                return $this->present->handle($order, $session, $round->fresh());
            },
        );
    }
}
