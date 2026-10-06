<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Device\GeofenceGuard;
use App\Actions\Qr\PresentQrPendingOrderAction;
use App\Actions\Qr\QrChargeException;
use App\Actions\Qr\QrChargeRecoveryGuard;
use App\Actions\Qr\QrDineInException;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\TableSession;
use App\Models\TableSessionEvent;
use App\Models\TabletOrder;
use App\Support\Money;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Staff table bills use the existing charge/tender protocol, never a fake QR credential. */
final class StaffTableCheckoutAction
{
    public const POLICY = 'staff_table_claim_v1';

    public function __construct(
        private readonly ResolveStaffSeatingAction $resolver,
        private readonly ReadTableDetailAction $detail,
        private readonly PresentQrPendingOrderAction $present,
        private readonly EnsureLegacyTableBillBaselineAction $baseline,
        private readonly AppendTableSessionEventAction $journal,
        private readonly QrChargeRecoveryGuard $recovery,
        private readonly GeofenceGuard $geofence,
    ) {}

    public static function shape(Order $order): bool
    {
        // LAUNCH-P6 — a bill a customer tablet opened is a staff-served table bill too.
        return in_array($order->source, ['main_pos', 'handheld', 'customer_tablet'], true)
            && $order->order_type === 'dine_in' && $order->qr_session_id === null
            && $order->table_id !== null && $order->table_session_id !== null;
    }

    /**
     * LAUNCH-P6 fix order 7 (F-21) — the bill's owning device is a customer
     * tablet of the bill's merchant (decided by the device, never by the
     * order's `source`).
     */
    public static function tabletOwned(Order $order): bool
    {
        return $order->device_id !== null && Device::withTrashed()->whereKey((int) $order->device_id)
            ->where('company_id', (int) $order->company_id)->value('device_type') === 'customer_tablet';
    }

    public function find(Device $device, string $uuid): ?Order
    {
        $order = Order::query()->where('uuid', trim($uuid))->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)->first();

        return $order !== null && self::shape($order) ? $order : null;
    }

    /** Called under the order lock (or the resolver's entire locked graph). */
    public function linked(Device $device, Order $order): TableSession
    {
        if (! self::shape($order)) {
            throw $this->refusal();
        }
        $detail = $this->detail->inspectLocked($device, (int) $order->table_id, static fn (Device $current, array $data): array => $data);
        $seat = TableSession::query()->whereKey($order->table_session_id)
            ->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)
            ->where('table_id', $order->table_id)->where('order_id', $order->id)->whereNull('merged_into_id')->first();
        if ($seat === null || $detail['orphaned'] || $detail['table']['archived']
            || $detail['table']['status'] !== 'active' || ($detail['bill']['uuid'] ?? null) !== $order->uuid
            || ($detail['seating']['uuid'] ?? null) !== $seat->uuid
            || ! in_array($seat->status, TableSession::LIVE_STATUSES, true)
            || $order->closed_at !== null || $order->transferred_at !== null
            || $order->transferred_from_device_id !== null || $order->transferred_to_device_id !== null) {
            throw $this->refusal();
        }

        return $seat;
    }

    public function claim(Device $device, Order $located, array $payload): array
    {
        return $this->locked($device, $located, function (Device $current, Order $order, TableSession $seat) use ($payload): array {
            if ($order->status === Order::STATUS_AWAITING_PAYMENT) {
                if (Order::whereKey($order->id)->withLiveClaim(now())->exists()) {
                    if ($order->charge_outcome !== null || (int) $order->charge_device_id !== (int) $current->id) {
                        throw new QrChargeException('charge_already_claimed', 409, 'The bill already has a live settlement claim.');
                    }
                    $this->assertFrozenClaim($order, $seat);

                    return $this->reservation($order, true);
                }
                throw new QrChargeException('qr_charge_recovery_required', 409, 'Resolve the previous payment attempt before claiming this bill.');
            }
            if (! in_array($order->status, [Order::STATUS_OPEN, Order::STATUS_HELD], true)) {
                throw $this->refusal();
            }
            if (! $this->present->hasNoChargeProvenance($order)) {
                throw new QrChargeException('qr_charge_recovery_required', 409, 'The bill has retained charge evidence.');
            }
            // LAUNCH-P6 fix order 5 (F-18) — a customer tablet round on this bill
            // still waits for its points answer (read only: the tablet rows are
            // never locked after the table graph).
            if (TabletOrder::hasOpenRedeemRequest((int) $order->id)) {
                throw new QrChargeException('redeem_pending', 409, 'Answer the points request first.');
            }
            // Another device cannot exclude an offline tender on the creator's
            // legacy copy. Exact recovery proves that copy is fenced/retired.
            // LAUNCH-P6 fix order 7 (F-21) — a bill a customer tablet opened
            // has no such copy (a tablet keeps no offline bill and can never
            // tender), so any attended device of the branch may check it out.
            if ((int) $order->device_id !== (int) $current->id && ! self::tabletOwned($order) && ! $this->hasOwnerRecovery($order, $seat)) {
                throw new QrChargeException('staff_bill_owner_required', 409, 'Recover the original draft on its owning device before checkout on another device.');
            }
            $detail = $this->detail->inspectLocked($current, (int) $order->table_id, static fn (Device $device, array $data): array => $data);
            if (DB::table('pos_payments')->where('order_id', $order->id)->exists()
                || collect($detail['rounds'])->contains('status', QrOrderRound::STATUS_PENDING_CONFIRMATION)) {
                throw $this->refusal('Resolve pending rounds or payment evidence before settlement.');
            }
            $this->baseline->handle($order);
            if (Money::toBaisas($order->grand_total) <= 0 || ! QrOrderRound::where('order_id', $order->id)
                ->where('status', QrOrderRound::STATUS_ACCEPTED)->exists()) {
                throw $this->refusal('The bill has no accepted payable amount.');
            }
            $this->enforceFence($current, $payload['gps'] ?? null);
            $at = now();
            $order->update(['status' => Order::STATUS_AWAITING_PAYMENT, 'charge_device_id' => $current->id,
                'charge_amount_baisas' => Money::toBaisas($order->grand_total), 'charge_roundup_amount_baisas' => null,
                'charge_claimed_at' => $at, 'charge_deadline_at' => $at->copy()->addSeconds(max(1, (int) config('qr.settlement_claim_seconds', 300))),
                'charge_outcome' => null]);
            if ($seat->status === TableSession::STATUS_OPEN) {
                $seat->update(['status' => TableSession::STATUS_BILLING, 'billing_at' => $at]);
                $this->journal->handle($seat, 'billing', ['order_uuid' => $order->uuid], (int) $current->id, $at);
            }

            return $this->reservation($order, false);
        });
    }

    public function reopen(Device $device, Order $located): array
    {
        return $this->locked($device, $located, function (Device $current, Order $order, TableSession $seat): array {
            if ($order->status === Order::STATUS_OPEN && $this->present->hasNoChargeProvenance($order)) {
                // No payment request exists. Preserve the original non-QR
                // reopen refusal; this is not an implicit staff checkout.
                throw new QrChargeException('order_not_found', 404, 'The order was not found.');
            }
            $safe = $order->status === Order::STATUS_HELD && $this->present->hasNoChargeProvenance($order);
            if ($order->status === Order::STATUS_AWAITING_PAYMENT
                && in_array($order->charge_outcome, [Order::CHARGE_OUTCOME_DECLINED, Order::CHARGE_OUTCOME_CANCELLED], true)) {
                $this->assertFrozenClaim($order, $seat);
                $safe = true;
            }
            if (! $safe) {
                throw new QrChargeException('qr_charge_recovery_required', 409, 'A live or uncertain payment cannot be reopened.');
            }
            $previous = $order->only(PresentQrPendingOrderAction::CHARGE_FIELDS);
            $order->update(['status' => Order::STATUS_OPEN] + array_fill_keys(PresentQrPendingOrderAction::CHARGE_FIELDS, null));
            $seat->update(['status' => TableSession::STATUS_OPEN, 'billing_at' => null]);
            $this->journal->handle($seat, 'reopened', ['order_uuid' => $order->uuid], (int) $current->id);
            Log::info('staff-table checkout reopened', ['order_uuid' => $order->uuid, 'device_id' => $current->id, 'previous_charge' => $previous]);

            return ['order_uuid' => $order->uuid, 'status' => Order::STATUS_OPEN, 'table_session_status' => TableSession::STATUS_OPEN];
        });
    }

    /** The existing attended recovery contract: preserve possibly-charged evidence. */
    public function fallback(Device $device, Order $located): array
    {
        return $this->locked($device, $located, function (Device $current, Order $order, TableSession $seat): array {
            if (! in_array($order->status, [Order::STATUS_AWAITING_PAYMENT, Order::STATUS_HELD], true)
                || ! $this->recovery->isAmbiguousCharge($order, now())) {
                throw new QrChargeException('qr_charge_recovery_required', 409, 'Only an ambiguous saved staff charge uses attended recovery.');
            }
            $this->assertFrozenClaim($order, $seat);
            if ($order->status !== Order::STATUS_HELD) {
                $order->update(['status' => Order::STATUS_HELD]);
                $this->journal->handle($seat, 'sent_to_counter', ['order_uuid' => $order->uuid], (int) $current->id);
            }

            return ['order_uuid' => $order->uuid, 'status' => Order::STATUS_HELD,
                'receipt_number' => $order->receipt_number, 'temp_reference' => $order->temp_reference];
        });
    }

    private function locked(Device $device, Order $located, Closure $operation): array
    {
        $this->present->assertAttended($device);
        try {
            $result = $this->resolver->locked($device, ['table_id' => (int) $located->table_id,
                'seating_key' => $located->uuid, 'queued_offline' => false], 'staff_checkout',
                function (Device $current, $tables, $orders) use ($located, $operation): array {
                    $order = $orders->get((int) $located->id);
                    if ($order === null || $order->uuid !== $located->uuid) {
                        throw $this->refusal();
                    }

                    return $operation($current, $order, $this->linked($current, $order));
                });

            return array_diff_key($result, array_flip(['event_id', 'event_ids']));
        } catch (QrDineInException $exception) {
            throw new QrChargeException($exception->codeName, $exception->httpStatus, $exception->getMessage());
        }
    }

    private function hasOwnerRecovery(Order $order, TableSession $seat): bool
    {
        return TableSessionEvent::query()->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)
            ->where('table_session_id', $seat->id)->where('device_id', $order->device_id)->where('event_type', 'attached')
            ->where('payload->action', 'same_bill_draft_recovered')->where('payload->result->order_uuid', $order->uuid)
            ->where('payload->result->table_session_uuid', $seat->uuid)->where('payload->result->archive_authorized', true)->exists();
    }

    private function assertFrozenClaim(Order $order, TableSession $seat): void
    {
        if ($seat->status !== TableSession::STATUS_BILLING || $seat->billing_at === null
            || $order->charge_device_id === null || $order->charge_claimed_at === null || $order->charge_deadline_at === null
            || ! $order->charge_deadline_at->gt($order->charge_claimed_at)
            || $order->charge_amount_baisas === null || (int) $order->charge_amount_baisas !== Money::toBaisas($order->grand_total)
            || (int) ($order->charge_roundup_amount_baisas ?? 0) !== 0) {
            throw new QrChargeException('qr_charge_recovery_required', 409, 'The saved claim and bill no longer agree.');
        }
    }

    private function reservation(Order $order, bool $replay): array
    {
        return ['order_uuid' => $order->uuid, 'status' => $order->status, 'receipt_number' => $order->receipt_number,
            'temp_reference' => $order->temp_reference, 'charge_amount_baisas' => (int) $order->charge_amount_baisas,
            'charge_claimed_at' => $order->charge_claimed_at->toIso8601String(),
            'charge_deadline_at' => $order->charge_deadline_at->toIso8601String(), 'already_claimed_by_this_device' => $replay];
    }

    private function enforceFence(Device $device, mixed $gps): void
    {
        $branch = Branch::whereKey($device->branch_id)->where('company_id', $device->company_id)->first();
        // LAUNCH-P1 2a — a live checkout is judged by the device's mode now.
        $requirement = $this->geofence->requirement($device, $branch);
        if ($requirement === GeofenceGuard::BRANCH_LOCATION_MISSING) {
            throw new QrChargeException(GeofenceGuard::BRANCH_LOCATION_MISSING, 409, ucfirst(GeofenceGuard::BRANCH_LOCATION_MISSING_MESSAGE).'.');
        }
        if ($requirement !== GeofenceGuard::ENFORCE) {
            return;
        }
        if (! is_array($gps)) {
            throw new QrChargeException('geofence_fix_required', 409, 'A GPS fix is required at this branch.');
        }
        try {
            $this->geofence->assertWithin($branch, (float) $gps['lat'], (float) $gps['lng']);
        } catch (RuntimeException $exception) {
            throw new QrChargeException('geofence_outside', 409, $exception->getMessage());
        }
    }

    private function refusal(string $message = 'This staff bill has no safe canonical checkout.'): QrChargeException
    {
        return new QrChargeException('qr_order_not_settleable', 409, $message);
    }
}
