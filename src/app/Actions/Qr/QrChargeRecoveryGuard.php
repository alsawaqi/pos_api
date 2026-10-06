<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Tables\StaffTableCheckoutAction;
use App\Models\Device;
use App\Models\Order;
use Carbon\CarbonInterface;

/** Canonical money-safety predicates for attended QR charge recovery. */
final class QrChargeRecoveryGuard
{
    /**
     * Mirrors the attended cases in pos_admin's App\Enums\DeviceType.
     * Keep these values in sync when that owning enum changes.
     *
     * @var list<string>
     */
    public const ATTENDED_DEVICE_TYPES = ['fixed_pos', 'handheld'];

    /** A started claim whose result cannot safely be inferred by time alone. */
    public function isAmbiguousCharge(Order $order, CarbonInterface $at): bool
    {
        if (in_array($order->charge_outcome, [
            Order::CHARGE_OUTCOME_LAPSED,
            Order::CHARGE_OUTCOME_UNCERTAIN,
        ], true)) {
            return true;
        }

        return $order->charge_claimed_at !== null
            && $order->charge_outcome === null
            && $order->charge_deadline_at !== null
            && $order->charge_deadline_at->lessThanOrEqualTo($at);
    }

    public function isAmbiguousCounterRecovery(Order $order, CarbonInterface $at): bool
    {
        $hasQrProvenance = StaffTableCheckoutAction::shape($order) || $order->qr_session_id !== null
            || ($order->source === Order::SOURCE_QR_WEB
                && $order->order_type === 'dine_in'
                && $order->table_id !== null)
            // LAUNCH-P6 fix order 3 (F-13) — a customer tablet's Quick / To go
            // order claimed through the same attended path recovers the same way.
            || ClaimQrSettlementAction::isTabletCounterOrder($order);

        return $hasQrProvenance
            && in_array($order->status, [
                Order::STATUS_HELD,
                Order::STATUS_OPEN,
                Order::STATUS_KITCHEN,
            ], true)
            && $this->isAmbiguousCharge($order, $at);
    }

    /**
     * LAUNCH-P6 fix order 3 (F-13) — the late cash pay of a customer tablet's
     * Quick / To go order from the attended device that held its claim, after
     * the claim lapsed by time (or was stamped lapsed by the sweeper) while the
     * device was offline. Nothing else is recorded on it yet, so the one pay
     * records the cash once; any other device goes through the counter
     * fallback or the manager payment review. An uncertain (card) outcome is
     * never settled this way.
     */
    public function isTabletLateHolderPay(Order $order, Device $device, CarbonInterface $at): bool
    {
        return $order->status === Order::STATUS_AWAITING_PAYMENT
            && $order->charge_claimed_at !== null
            && $order->charge_device_id !== null
            && (int) $order->charge_device_id === (int) $device->getKey()
            && $this->isAttendedDevice($device)
            && $order->charge_outcome !== Order::CHARGE_OUTCOME_UNCERTAIN
            && $this->isAmbiguousCharge($order, $at)
            && ClaimQrSettlementAction::isTabletCounterOrder($order)
            && ! $order->payments()->exists();
    }

    public function isAttendedDevice(Device $device): bool
    {
        return in_array($device->device_type, self::ATTENDED_DEVICE_TYPES, true);
    }
}
