<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;

/** Confirms one frozen round without repricing or resolving catalogue data. */
final class ConfirmDineInQrRoundAction
{
    public function __construct(
        private readonly WithLockedDineInQrRoundAction $locked,
        private readonly AppendQrPricedLinesAction $append,
        private readonly RefreshQrOrderTotalsAction $totals,
        private readonly PresentDeviceQrRoundAction $present,
        private readonly AllocateQrRoundAcceptedSequenceAction $acceptedSequence,
    ) {}

    /** @return array<string, mixed> */
    public function handle(Device $device, int $roundId): array
    {
        return $this->locked->handle(
            $device,
            $roundId,
            function (Order $order, QrSession $session, QrOrderRound $round) use ($device): array {
                if ($round->status !== QrOrderRound::STATUS_PENDING_CONFIRMATION
                    || $order->status !== Order::STATUS_OPEN) {
                    throw $this->notPending();
                }

                if ($session->status !== QrSession::STATUS_ACTIVE
                    || $session->isExpiredAt(now())) {
                    throw new QrDineInException(
                        'qr_session_expired',
                        409,
                        'This table session has expired.',
                    );
                }

                $payload = $round->confirm_payload;
                if (! is_array($payload)) {
                    // Never accept a legacy/corrupt row without its cost-bearing
                    // snapshots: that would add money while consuming no stock.
                    throw $this->notPending();
                }

                $this->append->handleStored($order, $payload);
                $round->update([
                    'status' => QrOrderRound::STATUS_ACCEPTED,
                    'resolved_at' => now(),
                    'resolved_by_device_id' => $device->getKey(),
                    'confirm_payload' => null,
                ]);
                $this->totals->handle($order);
                // Allocate last so the PostgreSQL advisory xact lock is held
                // for the shortest possible interval before commit.
                $round->update(['accepted_seq' => $this->acceptedSequence->next()]);

                return $this->present->handle($order->fresh(), $session, $round->fresh());
            },
        );
    }

    private function notPending(): QrDineInException
    {
        return new QrDineInException(
            'qr_round_not_pending',
            409,
            'This QR round is no longer awaiting confirmation.',
        );
    }
}
