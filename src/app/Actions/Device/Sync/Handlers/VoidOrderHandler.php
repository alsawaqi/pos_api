<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\ConsumeInventoryAction;
use App\Actions\Device\Sync\SyncEventHandler;
use App\Actions\Device\Sync\TenantReferenceGuard;
use App\Actions\Orders\VoidOrderCoreAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\SyncEvent;
use App\Models\VoidReason;
use App\Support\Staff\AuthorizationGate;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Phase 8.3 / v2 #14 — processes an `order.void` sync event: cancels the order
 * and its lines, and — if the order had already been PAID — fully unwinds every
 * accounting side effect that order.pay produced so a voided sale nets to zero:
 *
 *   1. inventory       — reverse stock consumption (recipe + unit), restoring
 *                        each branch balance ({@see ConsumeInventoryAction}).
 *   2. loyalty         — for every earn/redeem the sale wrote, append an inverse
 *                        `adjust` ledger row so the customer's balance returns to
 *                        where it was (a clawed-back earn is clamped to the
 *                        available balance — if the points were already spent we
 *                        take back only what's left, never forcing the ledger
 *                        negative and never failing the void).
 *   3. round-up        — flip the charity round-up donation row to `void` and
 *                        clear the card payment's roundup breadcrumbs. The
 *                        donation already forwarded to the charity app is NOT
 *                        reversed here (a settled external charity_transaction is
 *                        out of scope — refunding it is a manual charity-side op).
 *   4. commission      — delete the per-party commission breakdown so the voided
 *                        sale drops out of every settlement/payout total.
 *
 * The whole unwind runs inside ONE transaction with the status→VOID flip, and
 * the "already void" guard is the SOLE idempotency mechanism — a replayed
 * order.void throws (never re-reverses). A payment REFUND record (negative
 * payment / a `refunded` status) is intentionally NOT written here: the void +
 * these reversals ARE the books, and a real card refund needs a Soft POS
 * terminal reversal, which is a separate flow. Void is scoped to the device's
 * company + branch.
 */
class VoidOrderHandler implements SyncEventHandler
{
    public function __construct(
        private readonly VoidOrderCoreAction $core,
        private readonly AuthorizationGate $gate,
    ) {}

    public function handle(SyncEvent $event, Device $device): array
    {
        $payload = (array) $event->payload_json;
        $orderUuid = $payload['order_uuid'] ?? null;

        if (! is_string($orderUuid)) {
            throw new RuntimeException('invalid order.void payload: order_uuid required');
        }

        $order = Order::query()
            ->where('uuid', $orderUuid)
            ->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)
            ->first();

        if ($order === null) {
            throw new RuntimeException('order not found for void: '.$orderUuid);
        }
        if ($order->status === Order::STATUS_VOID) {
            throw new RuntimeException('order already void: '.$orderUuid);
        }

        $voidedAt = isset($payload['voided_at']) ? Carbon::parse((string) $payload['voided_at']) : now();
        $reason = isset($payload['reason']) ? (string) $payload['reason'] : null;

        // Phase B (Additions §1.2) — resolve the picked void reason code,
        // tenant-scoped. affects_inventory = TRUE means the food was actually
        // made: the recipe ingredients STAY consumed (no inventory reverse)
        // and the loss surfaces in the Loss/Waste voids breakdown. No / an
        // unknown reason keeps the legacy behaviour (full reverse).
        $voidReason = null;
        if (isset($payload['void_reason_id'])) {
            // LAUNCH-P4 H10 — a reason deleted after the device cached it still
            // resolves and is flagged; only another company's id refuses.
            $voidReason = VoidReason::withTrashed()
                ->where('company_id', $device->company_id)
                ->find((int) $payload['void_reason_id']);
            if ($voidReason === null) {
                throw new RuntimeException('void reason not found for this company: '.$payload['void_reason_id']);
            }
            if ($voidReason->trashed()) {
                $device->syncIntegrityFlags[] = 'void_reason_deleted:'.$voidReason->id;
            }
        }

        // LAUNCH-P5 (B1, M7) — who voided it (staff_id) and who approved it.
        // A P5 build sends the voider and an authorization block for
        // order.void_unpaid or order.void_paid; a reason marked "needs manager"
        // always needs an approver. The void is never refused over the check
        // (it is an offline sync event; the device already voided): the
        // verdict goes to pos_approvals and void_approved_by_staff_id holds
        // the checked approver only.
        $voidedBy = isset($payload['staff_id']) ? (int) $payload['staff_id'] : null;
        try {
            TenantReferenceGuard::assertStaffInTenant($device, $voidedBy, 'unknown voider');
        } catch (RuntimeException) {
            // Today a void ignores staff_id; an unknown one never fails it.
            $device->syncIntegrityFlags[] = 'void_staff_unknown:'.$voidedBy;
            $voidedBy = null;
        }
        // Fix order 1 F5 (review M3) — the action comes from the order's paid
        // state on the server at processing time, never from the block. A
        // block naming the other void action is checked against the stricter
        // one (order.void_paid) and, if that does not hold, recorded failed
        // `action_mismatch`.
        $wasPaid = in_array($order->status, [Order::STATUS_PAID, Order::STATUS_PENDING_VERIFICATION], true);
        $block = AuthorizationGate::block($payload['authorization'] ?? null);
        $action = $wasPaid ? 'order.void_paid' : 'order.void_unpaid';
        $mismatch = $block !== null && in_array($block['action'], ['order.void_unpaid', 'order.void_paid'], true)
            && $block['action'] !== $action;
        $outcome = $this->gate->evaluate($device, [
            'action' => $mismatch ? 'order.void_paid' : $action,
            'fail_reason' => $mismatch ? 'action_mismatch' : null,
            'subject_type' => 'order', 'subject_uuid' => $orderUuid,
            'actor_staff_id' => $voidedBy, 'staff_token' => $payload['staff_token'] ?? null,
            'client_event_id' => (string) $event->client_event_id,
            'at' => $event->client_timestamp ?? now(), 'needs_approval' => $voidReason !== null && (bool) $voidReason->requires_manager,
        ], $block, AuthorizationGate::isP5($payload, $device));

        // The ACK stays the void core's result (the verdict is in pos_approvals).
        return $this->core->handle($order, $device, $voidedAt, $reason, $voidReason, $voidedBy, $outcome->approvedBy());
    }
}
