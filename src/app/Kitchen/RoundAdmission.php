<?php

declare(strict_types=1);

namespace App\Kitchen;

use Illuminate\Support\Facades\DB;

/** Original source and accepted food survive the independent domain review. */
final class RoundAdmission
{
    public static function lines(object $round): array
    {
        KitchenFault::require(! ($round->is_accounting_only ?? false), 'accounting_only_round', 422);
        KitchenFault::require(! ($round->needs_review ?? false) ||
            ($round->status === 'accepted' && $round->resolved_at !== null && $round->accepted_seq !== null), 'domain_hold', 422);
        $priced = is_array($round->priced_lines) ? $round->priced_lines : Wire::read($round->priced_lines);
        $accepted = [];
        foreach ($priced as $line) {
            KitchenFault::require(! ($line['accounting_only'] ?? false), 'accounting_only_round', 422);
            if (isset($line['held_reason'])) {
                KitchenFault::require($round->status === 'accepted' && ($line['held_disposition'] ?? null) === 'dropped_at_review', 'domain_hold', 422);
                continue;
            }
            KitchenFault::require(isset($line['line_total_baisas']), 'invalid_price', 422);
            $accepted[] = $line;
        }
        KitchenFault::require($accepted !== [], 'domain_hold', 422);

        return $accepted;
    }

    public static function source(object $order, object $round): string
    {
        if (DB::table('pos_tablet_orders')->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)->where('round_id', $round->id)->exists()) {
            return 'customer_tablet';
        }
        if ($round->qr_session_id !== null) {
            return 'qr_web';
        }
        // The reviewer may be a different device. The first append/pending
        // audit identifies the submitting device, unlike resolved_by_device_id.
        $original = DB::table('pos_table_session_events')->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)
            ->whereIn('event_type', ['round_appended', 'round_pending'])->where('payload->round_id', (int) $round->id)->orderBy('id')->value('device_id');
        $deviceId = $original ?? ((! ($round->needs_review ?? false)) ? $round->resolved_by_device_id : null);
        $device = $deviceId ? DB::table('pos_devices')->where('id', $deviceId)->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)->first() : null;
        if (! $device && in_array($order->source, ['qr_web', 'customer_tablet'], true)) {
            return $order->source;
        }
        KitchenFault::require($device && in_array($device->device_type, ['fixed_pos', 'handheld'], true), 'round_source_unknown', 422);

        return $device->device_type === 'handheld' ? 'handheld' : 'main_pos';
    }
}
