<?php

declare(strict_types=1);

namespace App\Kitchen;

use App\Models\Device;
use App\Models\Order;
use App\Models\SyncEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Maps existing financial writes to kitchen evidence; never creates sales or deliveries. */
final class DomainLinkage
{
    public static function forEvent(SyncEvent $event, Device $device, array $result): void
    {
        if (! Schema::hasTable('pos_kv2_submissions') || ! $device->isAttended()) {
            return;
        }
        $orderId = $result['order_id'] ?? null;
        $roundId = $result['round_id'] ?? null;
        if (! $orderId && isset($result['order_uuid'])) {
            $orderId = Order::query()->where('company_id', $device->company_id)->where('branch_id', $device->branch_id)
                ->where('uuid', $result['order_uuid'])->value('id');
        }
        if (! $orderId) {
            return;
        }
        $order = Order::query()->whereKey($orderId)->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)->first();
        if (! $order) {
            return;
        }
        // Device/assignment ownership is immutable. A station paying or another
        // till processing the same bill cannot adopt or redispatch this intent.
        $source = $device->device_type === 'handheld' ? 'handheld' : 'main_pos';
        $query = DB::table('pos_kv2_submissions')->where('company_id', $device->company_id)
            ->where('branch_id', $device->branch_id)->where('origin_device_id', $device->id)
            ->where('origin_assignment', $device->assignment_activated_at?->toIso8601String())
            ->where('source', $source)->where('source_key', 'local:'.$event->client_event_id);
        foreach ($query->get() as $submission) {
            $linkId = Ids::stable('financial-link:'.$event->client_event_id.':'.$submission->uuid);
            if (DB::table('pos_kv2_events')->where('company_id', $device->company_id)
                ->where('branch_id', $device->branch_id)->where('uuid', $linkId)->exists()) {
                continue; // A replay cannot undo the later authorized review.
            }

            if ($submission->order_id !== null && (int) $submission->order_id !== (int) $order->id) {
                continue; // Preserve a conflict as pending evidence; never relabel it.
            }
            $round = $roundId ? DB::table('pos_qr_order_rounds')->where('id', $roundId)->where('order_id', $order->id)->first() : null;
            $eligible = ! in_array($order->status, ['void', 'refunded', 'combined', 'held'], true)
                && ($roundId === null || ($round && $round->status === 'accepted' && ! $round->needs_review));
            if ($round) {
                foreach (Wire::read($round->priced_lines) as $line) {
                    $eligible = $eligible && ! ($line['accounting_only'] ?? false) && ! isset($line['held_reason']);
                }
            }
            DB::table('pos_kv2_submissions')->where('id', $submission->id)->update([
                'order_id' => $order->id, 'round_id' => $round?->id,
                'link_state' => $eligible ? 'linked' : 'review_required', 'updated_at' => now(),
            ]);
            if (! $eligible) {
                // Stop only known-unsent work. Possible output/Done history is
                // retained for the explicit domain review and its later delta.
                if (! in_array($submission->state, ['served', 'collected', 'cancelled', 'rejected'], true)) {
                    DB::table('pos_kv2_submissions')->where('id', $submission->id)->update(['state' => 'needs_review', 'ready_at' => null, 'ready_cycle' => null]);
                }
                $unsent = DB::table('pos_kv2_deliveries')->where('submission_id', $submission->id)->whereIn('state', ['queued', 'paused', 'claimed', 'failed_before_send'])->pluck('id');
                DB::table('pos_kv2_attempts')->whereIn('delivery_id', $unsent)->where('state', 'claimed')->update(['state' => 'cancelled', 'updated_at' => now()]);
                DB::table('pos_kv2_deliveries')->whereIn('id', $unsent)->update(['state' => 'cancelled', 'updated_at' => now()]);
            }
            $branch = DB::table('pos_kv2_branches')->where('company_id', $device->company_id)->where('branch_id', $device->branch_id)->first();
            $host = $branch ? Device::query()->whereKey($branch->coordinator_id)->where('company_id', $device->company_id)->where('branch_id', $device->branch_id)->first() : null;
            $binding = $host ? DB::table('pos_kv2_devices')->where('device_id', $host->id)->where('company_id', $device->company_id)->where('branch_id', $device->branch_id)->where('enabled', true)->first() : null;
            if ($branch && in_array($branch->mode, ['active', 'pending'], true) && $host && $binding && $binding->assignment === $branch->coordinator_assignment) {
                $linkId = Ids::stable('financial-link:'.$event->client_event_id.':'.$submission->uuid);
                // The original financial result is immutable. A later retry must
                // not rewrite its event time/revision or conflict after Done.
                if (DB::table('pos_kv2_events')->where('company_id', $device->company_id)
                    ->where('branch_id', $device->branch_id)->where('uuid', $linkId)->exists()) {
                    continue;
                }
                $access = new Access($host, $branch, $binding);
                $link = ['protocol_version' => 1, 'event_id' => $linkId,
                    'epoch' => (int) $branch->epoch, 'occurred_at' => now()->toIso8601String(), 'action' => 'link',
                    'submission_uuid' => $submission->uuid, 'expected_revision' => (int) $submission->revision, 'order_uuid' => $submission->order_uuid];
                app(Journal::class)->apply($access, $link, fn () => app(Submissions::class)->receipt(app(Submissions::class)->find($access, $submission->uuid)));
            }

        }
    }
}
