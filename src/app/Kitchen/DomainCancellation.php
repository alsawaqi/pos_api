<?php

declare(strict_types=1);

namespace App\Kitchen;

use App\Models\Device;
use App\Models\Order;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** Invoked only by an already-authorized financial cancellation transaction. */
final class DomainCancellation
{
    public static function reconcile(Order $order, string $request, bool $whole = false, ?int $reviewedRound = null): void
    {
        if (! Compatibility::ownsBranch((int) $order->company_id, (int) $order->branch_id)) {
            return;
        }
        KitchenFault::require(DB::transactionLevel() > 0, 'domain_transaction_required', 500);
        $branch = DB::table('pos_kv2_branches')->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)->lockForUpdate()->first();
        $device = Device::query()->whereKey($branch->coordinator_id)->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)->first();
        $binding = DB::table('pos_kv2_devices')->where('device_id', $device?->id)->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)->where('enabled', true)->first();
        KitchenFault::require($device && $binding && $binding->assignment === $branch->coordinator_assignment, 'coordinator_unavailable');
        // This is a system consequence of the original audited domain action;
        // no staff identity or new financial command is invented here.
        $access = new Access($device, $branch, $binding);
        $submissions = DB::table('pos_kv2_submissions')->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)
            ->where(fn ($q) => $q->where('order_id', $order->id)->orWhere('order_uuid', $order->uuid)
                ->orWhereIn('round_id', DB::table('pos_qr_order_rounds')->select('id')->where('order_id', $order->id)))
            ->when($reviewedRound !== null, fn ($q) => $q->where('round_id', $reviewedRound)->where('link_state', 'review_required'))
            ->whereNotIn('state', ['cancelled', 'rejected', 'served', 'collected'])->orderBy('id')->get();
        foreach ($submissions as $s) {
            $snapshot = Wire::read(DB::table('pos_kv2_revisions')->where('submission_id', $s->id)->where('revision', $s->revision)->value('snapshot'));
            $lines = $snapshot['intent']['lines'];
            $next = [];
            if (! $whole) {
                if (! $s->round_id) {
                    continue;
                }
                $round = DB::table('pos_qr_order_rounds')->where('id', $s->round_id)->where('order_id', $order->id)->first();
                if (! $round) {
                    continue;
                }
                $first = Wire::read(DB::table('pos_kv2_revisions')->where('submission_id', $s->id)->where('revision', 1)->value('snapshot'));
                $parents = array_values(array_filter($first['intent']['lines'], fn ($l) => ! isset($l['parent_line_uuid'])));
                $priced = Wire::read($round->priced_lines);
                if (count($parents) !== count($priced)) {
                    $priced = RoundAdmission::lines($round);
                }
                $quantities = [];
                foreach ($parents as $index => $parent) {
                    $line = $priced[$index] ?? null;
                    KitchenFault::require($line && (int) $line['product_id'] === $parent['product_id'], 'kitchen_cancellation_mapping_required');
                    $remaining = ($line['held_disposition'] ?? null) === 'dropped_at_review' ? BigDecimal::zero() : BigDecimal::of((string) $line['qty'])->minus((string) ($line['cancelled_qty'] ?? 0));
                    $quantities[$parent['line_uuid']] = [$remaining, BigDecimal::of($parent['quantity'])];
                }
                foreach ($lines as $line) {
                    $root = $line['parent_line_uuid'] ?? $line['line_uuid'];
                    KitchenFault::require(isset($quantities[$root]), 'kitchen_cancellation_mapping_required');
                    [$remaining, $original] = $quantities[$root];
                    if ($remaining->isZero()) {
                        continue;
                    }
                    $originalLine = collect($first['intent']['lines'])->firstWhere('line_uuid', $line['line_uuid']);
                    KitchenFault::require($originalLine !== null, 'kitchen_cancellation_mapping_required');
                    $quantity = isset($line['parent_line_uuid'])
                        ? BigDecimal::of($originalLine['quantity'])->multipliedBy($remaining)->dividedBy($original, 6)
                        : $remaining->toScale(6);
                    // Financial cancellation cannot add quantity or replace edits.
                    KitchenFault::require($quantity->isLessThanOrEqualTo(BigDecimal::of($line['quantity'])), 'kitchen_cancellation_mapping_required');
                    $next[] = [...$line, 'quantity' => (string) $quantity];
                }
                if ($reviewedRound === null && Wire::hash($next) === Wire::hash($lines)) {
                    continue;
                }
            }
            $action = $next === [] ? 'cancel' : 'amend';
            $event = ['protocol_version' => 1, 'event_id' => Ids::stable('domain-cancel:'.$request.':'.$s->uuid.':'.$s->revision),
                'epoch' => (int) $branch->epoch, 'occurred_at' => now()->toIso8601String(), 'action' => $action,
                'submission_uuid' => $s->uuid, 'expected_revision' => (int) $s->revision];
            if ($action === 'amend') {
                $event['replacement'] = ['lines' => $next];
            }
            if ($reviewedRound !== null) {
                DB::table('pos_kv2_submissions')->where('id', $s->id)->update(['link_state' => 'linked', 'updated_at' => now()]);
            }
            app(Journal::class)->apply($access, $event, fn ($state) => app(Submissions::class)->event($access, $state, $event, domainResolved: true));
        }
    }
}
