<?php

declare(strict_types=1);

namespace App\Support\Staff;

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
 *   comps[i] (not a gift)                         comp, ref "comp:i"
 *   comps[i] with is_gift                         gift, ref "gift:i" (a block
 *                                                 with ref "comp:i" matches too)
 *   any other block it carries (e.g. loyalty.redeem) is checked as sent
 * order.pay gated items: a gift tender (gift, ref "tender:i", i = its index
 *   in payments; amount = the tender's amount_baisas), a loyalty redeem
 *   (loyalty.redeem, ref "loyalty:0") — satisfied by a row of the same order
 *   and action (a gift tender: the same ref) already written at order.create,
 *   else checked from order.pay's own blocks, else `missing`. A gift tender's
 *   block may ride order.create with ref "tender:i" and an EMPTY amount.
 *
 * Blocks are matched to items by action and ref first; a gated item left
 * without one then takes an unused block of its action whose ref is empty or
 * of the item's kind ("tender:…" never goes to a gift line).
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
        $p5 = AuthorizationGate::isP5($payload) || AuthorizationGate::isP5($order);
        $actor = isset($order['staff_id']) ? (int) $order['staff_id'] : null;
        $position = $actor === null ? null
            : DB::table('pos_staff')->where('company_id', $device->company_id)->where('id', $actor)->value('position');
        $companyId = (int) $device->company_id;
        $subtotal = (int) ($order['subtotal_baisas'] ?? 0);

        // ---- the gated (and optional) items of the sale ----
        $items = [];
        $amounts = ['discount.manual' => [], 'comp' => [], 'gift' => []];
        foreach ((array) ($order['discounts'] ?? []) as $d) {
            $amounts['discount.manual'][] = (int) ($d['amount_baisas'] ?? 0);
        }
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
            $amounts[$isGift ? 'gift' : 'comp'][] = (int) ($c['amount_baisas'] ?? 0);
            $items[] = ['action' => $isGift ? 'gift' : 'comp', 'refs' => $isGift ? ['gift:'.$i, 'comp:'.$i] : ['comp:'.$i],
                'amount' => (int) ($c['amount_baisas'] ?? 0), 'required' => true, 'comp_index' => $i,
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
                'percent' => $item['percent'] ?? null, 'candidate_amounts' => $amounts[$item['action']],
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
            // Blocks for things the event cannot show (a gift TENDER or a
            // loyalty redeem settle at order.pay): the proof is accepted with
            // no amount, the whole bill, the subtotal or any amount of the sale.
            $known = array_values(array_unique([(int) ($order['grand_total_baisas'] ?? 0), $subtotal,
                ...$amounts['discount.manual'], ...$amounts['comp'], ...$amounts['gift']]));
            foreach ($unused as $block) {
                if ($block['action'] === null) {
                    continue;
                }
                $outcome = $this->gate->evaluate($device, $base + [
                    'action' => $block['action'], 'ref' => $block['ref'], 'amount_baisas' => null,
                    'candidate_amounts' => $known,
                ], $block, true);
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
        $p5 = AuthorizationGate::isP5($payload);
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
        if ($items === []) {
            return [];
        }

        [$assigned] = $this->assign(AuthorizationGate::blocks($payload), $items);
        $base = ['subject_type' => 'order', 'subject_uuid' => $orderUuid, 'actor_staff_id' => $actor,
            'staff_token' => $payload['staff_token'] ?? null,
            'client_event_id' => (string) $event->client_event_id, 'at' => $event->client_timestamp ?? now()];
        $summary = [];
        foreach ($items as $k => $item) {
            $block = $assigned[$k] ?? null;
            $ref = $block['ref'] ?? $item['refs'][0];
            if ($block === null && $p5 && $this->recordedForOrder($device, $orderUuid, $item['action'],
                $item['action'] === 'gift' ? $item['refs'][0] : null)) {
                $summary[] = ['action' => $item['action'], 'ref' => $ref, 'result' => 'at_create'];

                continue;
            }
            $outcome = $this->gate->evaluate($device, $base + [
                'action' => $item['action'], 'ref' => $ref, 'amount_baisas' => $item['amount'],
            ], $block, $p5);
            $summary[] = ['action' => $item['action'], 'ref' => $ref, 'result' => $outcome->result];
        }

        return $summary;
    }

    /** A row already written for this order and action (a gift tender: for that tender's ref). */
    private function recordedForOrder(Device $device, string $orderUuid, string $action, ?string $ref): bool
    {
        return DB::table('pos_approvals')->where('company_id', $device->company_id)
            ->where('subject_type', 'order')->where('subject_uuid', $orderUuid)->where('action', $action)
            ->when($ref !== null, fn ($q) => $q->where('ref', $ref))->exists();
    }

    /**
     * Match blocks to items: by action and ref first, then a REQUIRED item
     * still without a block takes the first unused block of its action.
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
        foreach ($items as $k => $item) {
            if (isset($assigned[$k]) || ! ($item['required'] ?? true)) {
                continue;
            }
            // A block's ref kind ("tender", "gift", "comp", "discount", ...)
            // must suit the item: a gift line never takes a gift TENDER's block.
            $kinds = array_map(static fn (string $ref): string => strtok($ref, ':'), $item['refs']);
            foreach ($blocks as $b => $block) {
                if (! isset($used[$b]) && $block['action'] === $item['action']
                    && ($block['ref'] === null || in_array(strtok($block['ref'], ':'), $kinds, true))) {
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
