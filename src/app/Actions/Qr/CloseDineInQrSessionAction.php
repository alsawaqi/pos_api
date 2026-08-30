<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Order;
use App\Models\QrSession;
use Carbon\CarbonInterface;

/** Explicitly closes a dine-in credential whenever its order becomes terminal. */
final class CloseDineInQrSessionAction
{
    public function handle(Order $order, CarbonInterface $closedAt): bool
    {
        if ($order->qr_session_id === null) {
            return false;
        }

        $session = QrSession::query()
            ->whereKey((int) $order->qr_session_id)
            ->lockForUpdate()
            ->first();
        if ($session === null || ! $session->isDineIn()) {
            return false;
        }

        // Acceptance criterion 8 requires an orphan lazily marked expired to
        // become definitively closed when its order is later settled.
        if (in_array($session->status, [
            QrSession::STATUS_PENDING,
            QrSession::STATUS_ACTIVE,
            QrSession::STATUS_ORDERED,
            QrSession::STATUS_EXPIRED,
        ], true)) {
            $session->update([
                'status' => QrSession::STATUS_CLOSED,
                'closed_at' => $closedAt,
                'updated_at' => $closedAt,
            ]);
        }

        return true;
    }
}
