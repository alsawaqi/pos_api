<?php

declare(strict_types=1);

namespace App\Actions\Qr;

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
        $hasQrProvenance = $order->qr_session_id !== null
            || ($order->source === Order::SOURCE_QR_WEB
                && $order->order_type === 'dine_in'
                && $order->table_id !== null);

        return $hasQrProvenance
            && in_array($order->status, [
                Order::STATUS_HELD,
                Order::STATUS_OPEN,
                Order::STATUS_KITCHEN,
            ], true)
            && $this->isAmbiguousCharge($order, $at);
    }

    public function isAttendedDevice(Device $device): bool
    {
        return in_array($device->device_type, self::ATTENDED_DEVICE_TYPES, true);
    }
}
