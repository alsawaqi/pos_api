<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\PresentQrPendingOrderAction;
use App\Actions\Qr\QrDineInException;
use App\Actions\Qr\RefreshQrOrderTotalsAction;
use App\Models\CompReason;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Discount;
use App\Models\Order;
use App\Models\OrderComp;
use App\Models\OrderDiscount;
use App\Models\QrOrderRound;
use App\Models\TableSession;
use App\Models\TableSessionEvent;
use App\Support\CustomerIdentity;
use App\Support\Money;
use App\Support\Pricing\Applicability;
use App\Support\Pricing\DiscountRule;
use App\Support\Staff\AuthorizationGate;
use App\Support\Staff\PositionPermissions;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class AdjustTableBillAction
{
    public function __construct(
        private readonly ResolveStaffSeatingAction $resolver,
        private readonly RefreshQrOrderTotalsAction $totals,
        private readonly AppendTableSessionEventAction $journal,
        private readonly PresentQrPendingOrderAction $present,
        private readonly EnsureLegacyTableBillBaselineAction $baseline,
        private readonly TableAuthorization $authorization,
        private readonly PositionPermissions $permissions,
    ) {}

    public function handle(Device $device, array $payload, CarbonInterface $clientAt, CarbonInterface $receivedAt, ?string $uuid = null): array
    {
        Validator::make($payload, ResolveStaffSeatingAction::rules('adjust'))->validate();

        return DB::transaction(function () use ($device, $payload, $uuid): array {
            // Match the merge's customer-before-order lock order. Preserve the
            // original payload for the immutable request hash and replay.
            $adjustment = $payload['adjustment'];
            $customer = $adjustment['kind'] === 'customer' && $adjustment['mode'] === 'attach'
                ? CustomerIdentity::lockedSurvivor((int) $device->company_id, (int) $adjustment['customer_id'])
                : null;

            return $this->adjust($device, $payload, $uuid, $customer);
        });
    }

    private function adjust(Device $device, array $payload, ?string $uuid, ?Customer $customer): array
    {
        return $this->resolver->locked($device, $payload, 'adjust', function ($device, $tables, $orders, $sessions, $seatings) use ($payload, $uuid, $customer): array {
            $resolved = $this->resolver->resolve($seatings, $payload['seating_key'], $uuid);
            $seat = $resolved['primary'];
            $order = $seat === null ? null : $orders->get((int) $seat->order_id);
            if ($order === null || (int) $order->table_session_id !== (int) $seat->id) {
                throw self::refusal('bill_missing', 'Open a table bill before adjusting it.');
            }
            $hash = hash('sha256', json_encode(Arr::sortRecursive(Arr::except($payload, ['client_timestamp'])), JSON_THROW_ON_ERROR));
            $event = TableSessionEvent::query()->where('company_id', $device->company_id)->where('branch_id', $device->branch_id)
                ->where('table_session_id', $seat->id)->where('event_type', 'adjusted')
                ->where('payload->client_request_id', $payload['client_request_id'])->first();
            $result = fn (string $outcome): array => $this->resolver->result($outcome, $payload, $resolved['row'], $seat, $order);
            if ($event !== null) {
                if (($event->payload['request_hash'] ?? null) !== $hash) {
                    throw self::refusal('adjust_request_conflict', 'This adjustment reference was already used for a different request.');
                }

                return $result('replayed') + $event->payload['result'];
            }
            if ($order->status !== Order::STATUS_OPEN || $seat->status !== TableSession::STATUS_OPEN
                || $seat->billing_at !== null || $this->present->charge($order, now()) !== 'none') {
                throw self::refusal('bill_reserved', 'Reopen the bill and resolve its payment result before adjusting it.');
            }
            // Read-only: adjustment may not invent legacy rounds or repair an
            // inconsistent header. The normal round path owns legacy baseline.
            $this->baseline->assertReadyForCharge($order);
            $a = $this->totals->amounts($order);
            $net = RefreshQrOrderTotalsAction::net($a);
            if (! $this->totals->matchesHeader($order, $a) || $net < 1) {
                throw self::refusal('adjustment_exceeds_bill', 'The frozen bill must be consistent and have a positive balance.');
            }
            $adjustment = $payload['adjustment'];
            $kind = $adjustment['kind'];
            $mode = $adjustment['mode'];
            $proof = ['action' => 'bill_adjusted', 'kind' => $kind, 'mode' => $mode,
                'client_request_id' => $payload['client_request_id'], 'order_uuid' => $order->uuid];
            $staff = $adjustment['approved_by_staff_id'] ?? null;
            if ($staff !== null && ! DB::table('pos_staff')->where('id', $staff)->where('company_id', $device->company_id)->exists()) {
                throw self::refusal('approval_required', 'Choose an approving manager from this merchant.');
            }
            if ($kind === 'customer') {
                if ($mode === 'attach' && $customer === null) {
                    throw self::refusal('customer_not_found', 'The customer was not found for this merchant.');
                }
                if ((int) $order->customer_id !== (int) $customer?->id) {
                    TableLoyaltyDiscount::clear($order);
                }
                $order->update(['customer_id' => $customer?->id]);
                $proof['customer_id'] = $customer === null ? null : (int) $customer->id;
            } elseif ($kind === 'loyalty') {
                if ($mode === 'clear') {
                    TableLoyaltyDiscount::clear($order);
                    $proof['amount_baisas'] = 0;
                } else {
                    // LAUNCH-P5 — loyalty.redeem: a P5 build's block names the
                    // approver; an old build keeps the authorized_by text and
                    // approver id the redemption itself requires.
                    $approver = $this->authorize($device, $payload, 'loyalty.redeem', [], legacyText: false);
                    if ($approver !== null) {
                        $staff = $approver;
                        $adjustment['approved_by_staff_id'] = $approver;
                        $adjustment['authorized_by'] = (string) DB::table('pos_staff')->where('id', $approver)->value('name');
                    }
                    $proof += TableLoyaltyDiscount::redeem($order, $adjustment, $net - $a['manual'] - $a['comp']);
                }
            } elseif ($kind === 'discount') {
                $amount = 0;
                $rule = null;
                $label = $adjustment['label'] ?? null;
                if ($mode === 'rule') {
                    $rule = $this->rule($device, (int) $adjustment['discount_id']);
                    $label = $rule->name;
                    $amount = $rule->amount_type === 'percent' ? (int) round($net * (float) $rule->amount / 100) : Money::toBaisas($rule->amount);
                    if ($rule->amount_type === 'percent') {
                        $proof['percent_bp'] = (int) round((float) $rule->amount * 100);
                    }
                    if ($rule->requires_manager_approval) {
                        // M7 — a rule marked "needs manager" always needs an approver.
                        $staff = $this->authorize($device, $payload, 'discount.manual',
                            ['amount_baisas' => $amount, 'needs_approval' => true], legacyText: true) ?? $staff;
                    }
                } elseif ($mode === 'percent') {
                    $proof['percent_bp'] = (int) $adjustment['percent_bp'];
                    $amount = (int) round($net * $proof['percent_bp'] / 10000);
                } elseif ($mode === 'fixed') {
                    $amount = (int) $adjustment['amount_baisas'];
                }
                if ($mode !== 'clear' && ($amount < 1 || $amount + $a['comp'] + $a['loyalty'] >= $net)) {
                    throw self::refusal('adjustment_exceeds_bill', 'The discount must leave a positive amount to pay.');
                }
                if (in_array($mode, ['percent', 'fixed'], true)) {
                    // LAUNCH-P5 — a manual discount above the position's maximum
                    // (as a % of the bill, 1 baisa tolerance) needs an approver.
                    $percent = $mode === 'percent' ? $proof['percent_bp'] / 100 : max(0, $amount - 1) * 100 / max(1, $net);
                    $staff = $this->authorize($device, $payload, 'discount.manual', ['amount_baisas' => $amount,
                        'percent' => $percent, 'required' => ! $this->withinMax($device, $payload, $percent)], legacyText: false) ?? $staff;
                }
                $this->totals->reverseDiscount($order, $a['manual']);
                if ($amount > 0) {
                    OrderDiscount::query()->create(['company_id' => $device->company_id, 'branch_id' => $device->branch_id,
                        'order_id' => $order->id, 'order_item_id' => null, 'discount_id' => $rule?->id,
                        'offer_id' => null, 'name_snapshot' => $label, 'amount_type_snapshot' => 'table_manual_'.$mode,
                        'amount' => Money::toOmr($amount), 'reason' => $adjustment['reason'] ?? null, 'applied_at' => now()]);
                }
                $proof += ['amount_baisas' => $amount, 'basis_baisas' => $net];
            } else {
                $amount = 0;
                if ($mode === 'apply') {
                    // LAUNCH-P5 — comp (P5: the block; old build: the text).
                    $staff = $this->authorize($device, $payload, 'comp', [], legacyText: true) ?? $staff;
                    if ($adjustment['target'] === 'bill') {
                        throw self::refusal('full_comp_not_supported', 'A whole-bill complimentary payment is not supported. Leave an amount to pay.');
                    }
                    $reason = CompReason::query()->whereKey($adjustment['comp_reason_id'])
                        ->where('company_id', $device->company_id)->where('is_active', true)->first();
                    if ($reason === null) {
                        throw self::refusal('comp_cap_exceeded', 'Choose an active complimentary reason for this merchant.');
                    }
                    $target = $adjustment['target'];
                    $amount = self::lineNet($order, (int) $target['order_item_id'], (int) $target['qty']);
                    if ($reason->max_amount !== null && $amount > Money::toBaisas($reason->max_amount)) {
                        throw self::refusal('comp_cap_exceeded', 'The selected quantity exceeds this complimentary reason limit.');
                    }
                    if ($amount < 1 || $amount + $a['manual'] + $a['loyalty'] >= $net) {
                        throw self::refusal('adjustment_exceeds_bill', 'The complimentary amount must leave a positive amount to pay.');
                    }
                    $proof += ['comp_reason_id' => (int) $reason->id, 'order_item_id' => (int) $target['order_item_id'], 'qty' => (int) $target['qty']];
                }
                $this->totals->reverseComp($order, $a['comp']);
                if ($amount > 0) {
                    OrderComp::query()->create(['company_id' => $device->company_id, 'branch_id' => $device->branch_id,
                        'order_id' => $order->id, 'order_item_id' => $target['order_item_id'], 'qty' => $target['qty'],
                        'comp_reason_id' => $reason->id, 'reason_code_snapshot' => $reason->code,
                        'reason_name_snapshot' => $reason->name, 'amount' => Money::toOmr($amount),
                        'approved_by_pos_staff_id' => $staff, 'note' => $adjustment['note'] ?? null, 'applied_at' => now()]);
                }
                $proof += ['amount_baisas' => $amount, 'basis_baisas' => $net];
            }
            $this->totals->handle($order);
            if (isset($adjustment['authorized_by'])) {
                $proof['authorized_by'] = $adjustment['authorized_by'];
            }
            if ($staff !== null) {
                $proof['approved_by_staff_id'] = (int) $staff;
            }
            $ack = ['client_request_id' => $payload['client_request_id'], 'kind' => $kind, 'mode' => $mode,
                'grand_total_baisas' => Money::toBaisas($order->grand_total)];
            $this->journal->handle($seat, 'adjusted', $proof + ['request_hash' => $hash, 'result' => $ack], (int) $device->id);

            return $result('adjusted') + $ack;
        });
    }

    private function approve(array $adjustment): void
    {
        if (trim((string) ($adjustment['authorized_by'] ?? '')) === '') {
            throw self::refusal('approval_required', 'Manager approval is required for this adjustment.');
        }
    }

    /**
     * LAUNCH-P5 — check a gated adjustment. A P5 build's block must be
     * position_ok or verified (else 403 approval_required / approval_invalid)
     * and its approver is returned; an old build gets a `legacy` row and,
     * when $legacyText, today's authorized_by text rule.
     *
     * @param  array<string, mixed>  $extra
     */
    private function authorize(Device $device, array $payload, string $action, array $extra, bool $legacyText): ?int
    {
        $required = ($extra['required'] ?? true) === true;
        if (! AuthorizationGate::isP5($payload) && ! $required) {
            return null;
        }
        $outcome = $this->authorization->check($device, $payload, $action, $extra);
        if ($outcome === null) {
            if ($legacyText) {
                $this->approve($payload['adjustment']);
            }

            return null;
        }

        return $outcome->approvedBy();
    }

    /** The actor's position allows a manual discount of this size without approval. */
    private function withinMax(Device $device, array $payload, float $percent): bool
    {
        $position = isset($payload['staff_id'])
            ? DB::table('pos_staff')->where('company_id', $device->company_id)->where('id', (int) $payload['staff_id'])->value('position')
            : null;

        return $position !== null
            && $this->permissions->allows((int) $device->company_id, (string) $position, 'discount.manual')
            && $percent <= $this->permissions->discountMaxPercent((int) $device->company_id, (string) $position);
    }

    private function rule(Device $device, int $id): Discount
    {
        $rule = Discount::query()->whereKey($id)->where('company_id', $device->company_id)
            ->where('scope', 'order')->where('status', 'active')->first();
        if ($rule !== null) {
            $spec = new DiscountRule(id: (int) $rule->id, name: $rule->name, scope: $rule->scope, amountType: $rule->amount_type,
                validityStart: $rule->validity_start?->toDateTimeImmutable(), validityEnd: $rule->validity_end?->toDateTimeImmutable(),
                dayOfWeekMask: $rule->dayofweek_mask, timeStart: $rule->time_start, timeEnd: $rule->time_end,
                branchScope: array_map('intval', $rule->branch_scope_json ?? []));
            if (in_array($rule->amount_type, ['percent', 'fixed'], true)
                && Applicability::ruleAppliesAt($spec, now()->toDateTimeImmutable(), (int) $device->branch_id)) {
                return $rule;
            }
        }
        throw self::refusal('discount_rule_not_applicable', 'This discount rule is not available for this branch now.');
    }

    public static function lineNet(Order $order, int $id, int $qty, bool $capToRemaining = false): int
    {
        $matches = [];
        foreach (QrOrderRound::query()->where('order_id', $order->id)->where('status', QrOrderRound::STATUS_ACCEPTED)->get() as $round) {
            foreach ($round->priced_lines ?? [] as $line) {
                if (($line['order_item_id'] ?? null) === $id && ! isset($line['held_reason'])) {
                    $matches[] = $line;
                }
            }
        }
        if ($capToRemaining && $matches === []) {
            return 0;
        }
        if (count($matches) !== 1 || $qty < 1) {
            throw self::refusal('adjustment_exceeds_bill', 'The selected bill line or quantity is no longer available.');
        }
        $line = $matches[0];
        $remaining = (int) $line['qty'] - (int) ($line['cancelled_qty'] ?? 0);
        if ($capToRemaining) {
            $qty = min($qty, max(0, $remaining));
            if ($qty === 0) {
                return 0;
            }
        } elseif ($qty > $remaining) {
            throw self::refusal('adjustment_exceeds_bill', 'The selected bill line or quantity is no longer available.');
        }
        $discount = intdiv((int) ($line['line_discount_baisas'] ?? 0) * $remaining, (int) $line['qty']);

        return (int) $line['unit_price_baisas'] * $qty - intdiv($discount * $qty, $remaining);
    }

    public static function refusal(string $code, string $message): QrDineInException
    {
        return new QrDineInException($code, 409, $message);
    }
}
