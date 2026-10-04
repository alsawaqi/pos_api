<?php

declare(strict_types=1);

namespace App\Support\Staff;

use App\Models\CompReason;
use App\Models\Device;
use App\Models\Discount;
use App\Models\Payment;
use App\Models\SyncEvent;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P5 — the gated actions of a sale (order.create and order.pay).
 * Paid sales are NEVER rejected here: every check only writes pos_approvals
 * rows (and decides who the comp approver is).
 *
 * order.create gated items (subject = the order uuid):
 *   discounts[i] without discount_id / offer_id   discount.manual, ref "discount:i",
 *       amount = that row's amount_baisas; gated when the cashier's position
 *       lacks the tick or the discount is above its maximum (as a % of the
 *       order subtotal, 1 baisa tolerance)
 *   discounts[i] of a rule marked "needs manager" discount.manual, ref
 *       "discount:i"; always needs an approver (M7)
 *   comps[i] (not a gift)                         comp, ref "comp:i"; the comp
 *                                                 tick covers it only within its
 *                                                 reason's cap (above: missing
 *                                                 `above_cap`, F9)
 *   comps[i] with is_gift                         gift, ref "gift:i" (or "comp:i")
 *   a block for what settles at order.pay rides order.create too: a gift
 *       TENDER (gift, ref "tender:i", EMPTY amount — the one documented
 *       empty amount) or a loyalty redeem (loyalty.redeem, ref "loyalty:0",
 *       empty amount)
 * order.pay gated items: a gift tender (gift, ref "tender:i", i = its index
 *   in payments; amount = the tender's amount_baisas), a loyalty redeem
 *   (loyalty.redeem, ref "loyalty:0") — satisfied by a row of the same order,
 *   action and ref already written at order.create, else checked from
 *   order.pay's own blocks, else `missing`.
 *
 * Fix order 1 F4 (review M2): a block belongs to the item whose action and
 * ref it names, and its proof is checked against that item's own subject,
 * amount and ref only. A block naming no item of its event is recorded
 * failed `ref_mismatch`; the item it may have been meant for is `missing`.
 */
final class SaleAuthorizations
{
    public function __construct(
        private readonly AuthorizationGate $gate,
        private readonly PositionPermissions $permissions,
    ) {}

    /**
     * @param  array<string, mixed>  $order  the order.create `order` object
     * @return array{comp_approvers: array<int, ?int>, summary: list<array<string, mixed>>}
     */
    public function forCreate(SyncEvent $event, Device $device, array $order): array
    {
        $payload = (array) $event->payload_json;
        $p5 = AuthorizationGate::isP5($payload, $device) || AuthorizationGate::marked($order);
        $actor = isset($order['staff_id']) ? (int) $order['staff_id'] : null;
        $position = $actor === null ? null
            : DB::table('pos_staff')->where('company_id', $device->company_id)->where('id', $actor)->value('position');
        $companyId = (int) $device->company_id;
        $subtotal = (int) ($order['subtotal_baisas'] ?? 0);

        // ---- the gated (and optional) items of the sale ----
        $items = [];
        foreach ((array) ($order['discounts'] ?? []) as $i => $d) {
            if (! is_array($d) || isset($d['offer_id'])) {
                continue;
            }
            $amount = (int) ($d['amount_baisas'] ?? 0);
            $needsManager = false;
            if (isset($d['discount_id'])) {
                $rule = Discount::withTrashed()->where('company_id', $companyId)->find((int) $d['discount_id']);
                if ($rule === null || ! $rule->requires_manager_approval) {
                    continue; // an automatic / plain rule discount is not gated
                }
                $needsManager = true;
            }
            // 1 baisa tolerance: a 10 % device rounding never counts as 10.01 %.
            $percent = $subtotal > 0 ? max(0, $amount - 1) * 100 / $subtotal : ($amount > 0 ? 100.0 : 0.0);
            $within = ! $needsManager && $position !== null
                && $this->permissions->allows($companyId, (string) $position, 'discount.manual')
                && $percent <= $this->permissions->discountMaxPercent($companyId, (string) $position);
            $items[] = ['action' => 'discount.manual', 'refs' => ['discount:'.$i], 'amount' => $amount,
                'required' => ! $within, 'needs_approval' => $needsManager, 'percent' => $percent];
        }
        foreach ((array) ($order['comps'] ?? []) as $i => $c) {
            if (! is_array($c)) {
                continue;
            }
            $isGift = ($c['is_gift'] ?? false) === true;
            // F9 (review M7) — the comp tick covers a comp within its
            // reason's cap only; above it the comp needs an approval.
            $cap = null;
            if (! $isGift && isset($c['comp_reason_id'])) {
                $max = CompReason::withTrashed()->where('company_id', $companyId)->whereKey((int) $c['comp_reason_id'])->value('max_amount');
                $cap = $max === null ? null : (int) round(((float) $max) * 1000);
            }
            $items[] = ['action' => $isGift ? 'gift' : 'comp', 'refs' => $isGift ? ['gift:'.$i, 'comp:'.$i] : ['comp:'.$i],
                'amount' => (int) ($c['amount_baisas'] ?? 0), 'required' => true, 'comp_index' => $i, 'cap' => $cap,
                'actor' => isset($c['staff_id']) ? (int) $c['staff_id'] : $actor,
                'legacy_approver' => isset($c['approved_by_staff_id']) ? (int) $c['approved_by_staff_id'] : null];
        }

        [$assigned, $unused] = $this->assign(AuthorizationGate::blocks($payload), $items);

        // F1 — the event's actor is the order's staff member, bound by the
        // event's signed staff token (on the payload, or inside `order`).
        $base = ['subject_type' => 'order', 'subject_uuid' => (string) ($order['uuid'] ?? ''), 'actor_staff_id' => $actor,
            'staff_token' => $payload['staff_token'] ?? ($order['staff_token'] ?? null),
            'client_event_id' => (string) $event->client_event_id, 'at' => $event->client_timestamp ?? now()];
        $approvers = [];
        $summary = [];
        foreach ($items as $k => $item) {
            $block = $assigned[$k] ?? null;
            if (! $item['required'] && $block === null) {
                continue;
            }
            $ref = $block['ref'] ?? $item['refs'][0];
            $outcome = $this->gate->evaluate($device, array_merge($base, [
                'action' => $item['action'], 'ref' => $ref, 'amount_baisas' => $item['amount'],
                'required' => $item['required'], 'needs_approval' => $item['needs_approval'] ?? false,
                'percent' => $item['percent'] ?? null, 'cap_baisas' => $item['cap'] ?? null,
                // An old build's row keeps the comp's own staff member.
                'actor_staff_id' => $p5 ? $actor : ($item['actor'] ?? $actor),
                'legacy_approver_staff_id' => $item['legacy_approver'] ?? null,
            ]), $block, $p5);
            if (isset($item['comp_index'])) {
                // B1 — a P5 build: the verified approver, or the actor whose
                // own position allowed it; never the cashier as a guess. An
                // old build keeps today's behaviour (its sent approver, else
                // the comp's staff member) — its `legacy` row says so.
                $approvers[$item['comp_index']] = $outcome->result === AuthorizationOutcome::LEGACY
                    ? ($item['legacy_approver'] ?? $item['actor'] ?? null) : $outcome->approvedBy();
            }
            $summary[] = ['action' => $item['action'], 'ref' => $ref, 'result' => $outcome->result];
        }
        if ($p5) {
            foreach ($unused as $block) {
                if ($block['action'] === null) {
                    continue;
                }
                $ctx = $base + ['action' => $block['action'], 'ref' => $block['ref'], 'amount_baisas' => null];
                // What settles at order.pay may be approved here, with an
                // EMPTY amount: a gift tender ("tender:i") or a loyalty redeem.
                $settlesAtPay = ($block['action'] === 'gift' && preg_match('/^tender:\d+$/', (string) $block['ref']) === 1)
                    || ($block['action'] === 'loyalty.redeem' && $block['ref'] === 'loyalty:0');
                $outcome = $settlesAtPay ? $this->gate->evaluate($device, $ctx, $block, true)
                    : $this->gate->refuse($device, $ctx, $block, 'ref_mismatch');
                $summary[] = ['action' => $block['action'], 'ref' => $block['ref'], 'result' => $outcome->result];
            }
        }

        return ['comp_approvers' => $approvers, 'summary' => $summary];
    }

    /**
     * @param  list<mixed>  $payments
     * @return list<array<string, mixed>>
     */
    public function forPay(SyncEvent $event, Device $device, string $orderUuid, array $payments, bool $loyaltyRedeem): array
    {
        $payload = (array) $event->payload_json;
        $p5 = AuthorizationGate::isP5($payload, $device);
        $actor = isset($payload['staff_id']) ? (int) $payload['staff_id'] : null;

        $items = [];
        foreach ($payments as $i => $tender) {
            if (is_array($tender) && ($tender['method'] ?? null) === Payment::METHOD_GIFT
                && ($tender['status'] ?? Payment::STATUS_SUCCESS) !== Payment::STATUS_FAILED) {
                $items[] = ['action' => 'gift', 'refs' => ['tender:'.$i], 'amount' => (int) ($tender['amount_baisas'] ?? 0)];
            }
        }
        if ($loyaltyRedeem) {
            $items[] = ['action' => 'loyalty.redeem', 'refs' => ['loyalty:0'], 'amount' => null];
        }
        $blocks = AuthorizationGate::blocks($payload);
        if ($items === [] && $blocks === []) {
            return [];
        }

        [$assigned, $unused] = $this->assign($blocks, $items);
        $base = ['subject_type' => 'order', 'subject_uuid' => $orderUuid, 'actor_staff_id' => $actor,
            'staff_token' => $payload['staff_token'] ?? null,
            'client_event_id' => (string) $event->client_event_id, 'at' => $event->client_timestamp ?? now()];
        $summary = [];
        foreach ($items as $k => $item) {
            $block = $assigned[$k] ?? null;
            $ref = $block['ref'] ?? $item['refs'][0];
            if ($block === null && $p5 && $this->recordedForOrder($device, $orderUuid, $item['action'], $item['refs'][0])) {
                $summary[] = ['action' => $item['action'], 'ref' => $ref, 'result' => 'at_create'];

                continue;
            }
            $outcome = $this->gate->evaluate($device, $base + [
                'action' => $item['action'], 'ref' => $ref, 'amount_baisas' => $item['amount'],
            ], $block, $p5);
            $summary[] = ['action' => $item['action'], 'ref' => $ref, 'result' => $outcome->result];
        }
        if ($p5) {
            foreach ($unused as $block) {
                if ($block['action'] !== null) {
                    $outcome = $this->gate->refuse($device, $base + ['action' => $block['action'], 'ref' => $block['ref']],
                        $block, 'ref_mismatch');
                    $summary[] = ['action' => $block['action'], 'ref' => $block['ref'], 'result' => $outcome->result];
                }
            }
        }

        return $summary;
    }

    /** A row already written for this order, action and ref (at order.create). */
    private function recordedForOrder(Device $device, string $orderUuid, string $action, string $ref): bool
    {
        return DB::table('pos_approvals')->where('company_id', $device->company_id)
            ->where('subject_type', 'order')->where('subject_uuid', $orderUuid)->where('action', $action)
            ->where('ref', $ref)->exists();
    }

    /**
     * Match blocks to items by action and ref, exactly (F4): no block of
     * another ref, or without one, ever stands in for an item.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @param  list<array<string, mixed>>  $items
     * @return array{0: array<int, array<string, mixed>>, 1: list<array<string, mixed>>}
     */
    private function assign(array $blocks, array $items): array
    {
        $assigned = [];
        $used = [];
        foreach ($items as $k => $item) {
            foreach ($blocks as $b => $block) {
                if (! isset($used[$b]) && $block['action'] === $item['action'] && in_array($block['ref'], $item['refs'], true)) {
                    $assigned[$k] = $block;
                    $used[$b] = true;
                    break;
                }
            }
        }
        $unused = [];
        foreach ($blocks as $b => $block) {
            if (! isset($used[$b])) {
                $unused[] = $block;
            }
        }

        return [$assigned, $unused];
    }
}
