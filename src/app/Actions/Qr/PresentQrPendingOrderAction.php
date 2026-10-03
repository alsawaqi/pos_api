<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Tables\TableBillAdjustmentView;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderComp;
use App\Models\OrderItem;
use App\Models\QrSession;
use App\Support\Money;
use App\Support\Orders\DeviceOrderItems;
use Carbon\CarbonInterface;

/** Additive pending-list presentation; the legacy device mapper stays untouched. */
final class PresentQrPendingOrderAction
{
    public const CHARGE_FIELDS = [
        'charge_device_id',
        'charge_amount_baisas',
        'charge_roundup_amount_baisas',
        'charge_claimed_at',
        'charge_deadline_at',
        'charge_outcome',
    ];

    public function __construct(private readonly QrChargeRecoveryGuard $recovery) {}

    public function assertAttended(Device $device): void
    {
        if (! $this->recovery->isAttendedDevice($device)
            || ! $device->isAssigned()
            || $device->status !== 'active') {
            throw new QrChargeException('device_not_attended', 409, 'Only an active attended device may use QR pending orders.');
        }
    }

    public function hasNoChargeProvenance(Order $order): bool
    {
        foreach (self::CHARGE_FIELDS as $field) {
            if ($order->getRawOriginal($field) !== null) {
                return false;
            }
        }

        return true;
    }

    public function charge(Order $order, CarbonInterface $at): string
    {
        if ($order->status === Order::STATUS_AWAITING_PAYMENT
            && $order->charge_claimed_at !== null
            && $order->charge_outcome === null
            && $order->charge_deadline_at?->gt($at)) {
            return 'live_claim';
        }
        if ($this->recovery->isAmbiguousCharge($order, $at)
            || $order->charge_outcome === Order::CHARGE_OUTCOME_APPROVED) {
            return 'uncertain';
        }
        if ($order->charge_outcome === Order::CHARGE_OUTCOME_DECLINED) {
            return 'declined';
        }
        if ($order->charge_outcome === Order::CHARGE_OUTCOME_CANCELLED) {
            return 'cancelled';
        }

        return $this->hasNoChargeProvenance($order) ? 'none' : 'uncertain';
    }

    /** @return array<string, mixed> */
    public function handle(Order $order, ?QrSession $session, ?string $phone, CarbonInterface $at, bool $workspace = false): array
    {
        $charge = $this->charge($order, $at);
        $held = $order->status === Order::STATUS_HELD;
        $digits = preg_replace('/\\D/', '', $phone ?? '');
        $sessionState = match (true) {
            $session === null => 'missing',
            $session->status === QrSession::STATUS_CLOSED => 'closed',
            $session->status === QrSession::STATUS_EXPIRED,
            $session->expires_at?->lte($at) === true => 'expired',
            default => 'live',
        };

        return $this->mapOrder($order) + ($workspace ? [
            'edit_revision' => QuickQrWorkspaceAction::revision($order),
            'transferred_to_device_id' => $order->transferred_to_device_id,
        ] : []) + [
            'route' => $held ? 'counter' : 'machine',
            'session' => $sessionState,
            'charge' => $charge,
            'age_seconds' => $order->opened_at === null ? 0 : max(0, (int) $order->opened_at->diffInSeconds($at)),
            'phone_tail' => strlen($digits) >= 4 ? substr($digits, -4) : null,
            'actions' => [
                'settle' => $held && $charge === 'none' && $order->transferred_to_device_id === null && Money::toBaisas($order->grand_total) > 0,
                'to_counter' => $order->transferred_to_device_id === null && (($order->status === Order::STATUS_AWAITING_PAYMENT
                        && in_array($charge, ['none', 'declined', 'cancelled'], true))
                    || ($held && in_array($charge, ['declined', 'cancelled'], true))),
            ],
            'refusal_code' => match ($charge) {
                'live_claim' => 'charge_already_claimed',
                'uncertain' => 'charge_outcome_uncertain',
                default => null,
            },
        ];
    }

    /** @return array<string, mixed> */
    public function mapOrder(Order $order): array
    {
        $itemIds = DeviceOrderItems::lines($order)
            ->map(fn (OrderItem $item): int => (int) $item->id)
            ->values()
            ->all();

        return [
            'id' => (int) $order->id,
            'uuid' => $order->uuid,
            'order_type' => $order->order_type,
            'status' => $order->status,
            'source' => $order->source,
            'table_id' => $order->table_id !== null ? (int) $order->table_id : null,
            'customer_id' => $order->customer_id !== null ? (int) $order->customer_id : null,
            'staff_id' => $order->staff_id !== null ? (int) $order->staff_id : null,
            'plate_number' => $order->plate_number,
            // P-F8 — the printed receipt number; null for unnumbered orders.
            'receipt_number' => $order->receipt_number,
            'temp_reference' => $order->temp_reference,
            // P-G7 — provider linkage for delivery orders (null otherwise).
            'delivery' => $order->delivery_provider_id !== null ? [
                'provider_id' => (int) $order->delivery_provider_id,
                'provider_name' => $order->delivery_provider_name,
                'reference' => $order->delivery_reference,
            ] : null,
            'opened_at' => $order->opened_at?->toIso8601String(),
            ...TableBillAdjustmentView::deviceFields($order),
            'subtotal_baisas' => Money::toBaisas($order->subtotal),
            'discount_total_baisas' => Money::toBaisas($order->discount_total),
            'comp_total_baisas' => Money::toBaisas($order->comp_total ?? 0),
            'tax_total_baisas' => Money::toBaisas($order->tax_total),
            'grand_total_baisas' => Money::toBaisas($order->grand_total),
            'note' => $order->note,
            'comps' => $order->comps->map(function (OrderComp $comp) use ($itemIds): array {
                $lineIndex = null;
                if ($comp->order_item_id !== null) {
                    $position = array_search((int) $comp->order_item_id, $itemIds, true);
                    $lineIndex = $position !== false ? (int) $position : null;
                }

                return [
                    'id' => (int) $comp->id,
                    'order_item_id' => $comp->order_item_id !== null ? (int) $comp->order_item_id : null,
                    'line_index' => $lineIndex,
                    'comp_reason_id' => $comp->comp_reason_id !== null ? (int) $comp->comp_reason_id : null,
                    'reason_code' => $comp->reason_code_snapshot,
                    'reason_name' => $comp->reason_name_snapshot,
                    'is_gift' => (bool) $comp->is_gift,
                    'amount_baisas' => Money::toBaisas($comp->amount),
                    'qty' => $comp->qty !== null ? (int) round((float) $comp->qty) : null,
                    'staff_id' => $comp->approved_by_pos_staff_id !== null ? (int) $comp->approved_by_pos_staff_id : null,
                    'note' => $comp->note,
                    'applied_at' => $comp->applied_at?->toIso8601String(),
                ];
            })->all(),
            // LAUNCH-P4 — combo children ride their line as `combo`.
            'items' => DeviceOrderItems::present($order),
            'prices_include_tax' => (bool) $order->prices_include_tax,
        ];
    }
}
