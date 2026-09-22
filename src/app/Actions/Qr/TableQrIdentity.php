<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\TableSessionEvent;

final class TableQrIdentity
{
    public static function allowed(QrSession $session, ?Order $order): bool
    {
        if ($session->handover_from_id !== null && $order?->customer_id !== null) {
            return false;
        }
        $rounds = QrOrderRound::query()->where('qr_session_id', $session->id)->get(['id', 'status']);
        $accepted = $rounds->where('status', QrOrderRound::STATUS_ACCEPTED);
        if ($accepted->isEmpty()) {
            return true;
        }
        if ($order?->customer_id !== null) {
            return false;
        }
        $proofs = TableSessionEvent::query()
            ->where('company_id', $session->company_id)->where('branch_id', $session->branch_id)
            ->whereIn('event_type', ['round_appended', 'round_pending'])->whereIn('payload->round_id', $rounds->pluck('id'))
            ->get()->pluck('payload')->keyBy('round_id');
        if ($proofs->contains(fn (array $proof): bool => ($proof['customer_identity_set'] ?? false) === true)) {
            return false;
        }

        // Pre-T12 accepted rounds required a phone. Only explicit anonymous
        // round evidence permits a first identity later; detaching cannot reset it.
        return $accepted->every(fn (QrOrderRound $round): bool => ($proofs->get($round->id)['optional_identity'] ?? false) === true);
    }
}
