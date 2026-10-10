<?php

declare(strict_types=1);

namespace App\Kitchen;

use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class Compatibility
{
    public static function ownsBranch(int $company, int $branch): bool
    {
        return Schema::hasTable('pos_kv2_branches') && DB::table('pos_kv2_branches')->where('company_id', $company)->where('branch_id', $branch)->where('mode', '!=', 'legacy')->exists();
    }

    public static function settings(int $company, int $branch): array
    {
        $row = Schema::hasTable('pos_kv2_branches') ? DB::table('pos_kv2_branches')->where('company_id', $company)->where('branch_id', $branch)->first() : null;

        $minutes = $row && $row->mode !== 'legacy' && Schema::hasColumn('pos_products', 'cooking_minutes') ? Catalogue::query($company, $branch)->pluck('cooking_minutes', 'id')->map(fn ($v) => $v === null ? null : (int) $v)->all() : [];

        return ['setup_enabled' => (bool) config('kitchen.enabled') && Schema::hasTable('pos_kv2_branches'), 'cooking_minutes' => (object) $minutes, 'mode' => $row?->mode ?? 'legacy', 'protocol_version' => 1,
            'coordinator_id' => $row?->coordinator_id === null ? null : (int) $row->coordinator_id,
            'epoch' => (int) ($row?->epoch ?? 0), 'applied_version' => (int) ($row?->applied_version ?? 0),
            'desired_version' => (int) ($row?->desired_version ?? 0)];
    }

    public static function released(Order $order): bool
    {
        return Schema::hasTable('pos_kv2_submissions') && DB::table('pos_kv2_submissions')->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)->where('order_uuid', $order->uuid)->whereNotNull('released_at')->exists();
    }

    /** Evidence adapter only. Financial/waste writes stay in the existing cancellation service (K4). */
    public static function preparation(int $company, int $branch, string $orderUuid): array
    {
        $out = [];
        foreach (DB::table('pos_kv2_submissions')->where('company_id', $company)->where('branch_id', $branch)->where('order_uuid', $orderUuid)->get() as $s) {
            $printed = DB::table('pos_kv2_deliveries')->where('submission_id', $s->id)->whereIn('state', ['sending', 'sent_unconfirmed', 'uncertain', 'confirmed'])->exists();
            foreach (DB::table('pos_kv2_work')->where('submission_id', $s->id)->get() as $w) {
                $out[] = ['submission_uuid' => $s->uuid, 'revision' => (int) $w->revision, 'line_uuid' => $w->line_uuid, 'area_uuid' => $w->area_uuid, 'quantity' => $w->quantity, 'line' => Wire::read($w->line), 'evidence' => $w->done_at ? 'done' : ($s->released_at || $printed ? 'review_required' : 'not_released'), 'done_at' => $w->done_at];
            }
        }

        return $out;
    }
}
