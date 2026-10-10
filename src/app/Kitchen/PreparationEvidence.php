<?php

declare(strict_types=1);

namespace App\Kitchen;

use App\Actions\Qr\QrDineInException;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Maps immutable kitchen work onto existing financial item IDs. No stock writes. */
final class PreparationEvidence
{
    public static function forOrder(Order $order): array
    {
        $done = [];
        $review = [];
        $evidence = [];
        if (! Schema::hasTable('pos_kv2_submissions')) {
            return ['done' => [], 'review' => [], 'evidence' => []];
        }
        $submissions = DB::table('pos_kv2_submissions')->where('company_id', $order->company_id)->where('branch_id', $order->branch_id)
            ->where(function ($q) use ($order) {
                $q->where('order_id', $order->id)->orWhere('order_uuid', $order->uuid)
                    ->orWhereIn('round_id', DB::table('pos_qr_order_rounds')->select('id')->where('order_id', $order->id));
            })->get();
        foreach ($submissions as $submission) {
            $first = DB::table('pos_kv2_revisions')->where('submission_id', $submission->id)->where('revision', 1)->first();
            if (! $first) {
                continue;
            }
            $intent = Wire::read($first->snapshot)['intent'];
            $parents = array_values(array_filter($intent['lines'], fn ($l) => ! isset($l['parent_line_uuid'])));
            $round = $submission->round_id ? DB::table('pos_qr_order_rounds')->where('id', $submission->round_id)->first() : null;
            // Locally admitted revision1 retains dropped parents. Cloud-first
            // accepted subsets have only the filtered parents. Match that frozen
            // representation before resolving financial item IDs.
            $raw = $round ? Wire::read($round->priced_lines) : [];
            $priced = $round ? (count($parents) === count($raw) ? $raw : RoundAdmission::lines($round)) : [];
            $roots = [];
            if ($round) {
                foreach ($parents as $index => $parent) {
                    if (isset($priced[$index]['order_item_id']) && (int) $priced[$index]['product_id'] === $parent['product_id']) {
                        $roots[$parent['line_uuid']] = (int) $priced[$index]['order_item_id'];
                    }
                }
            } else {
                $items = OrderItem::query()->where('order_id', $order->id)->whereNull('parent_order_item_id')->orderBy('id')->get();
                foreach ($parents as $index => $parent) {
                    $item = $items->get($index);
                    if ($item && ($item->product_id === null || (int) $item->product_id === $parent['product_id'])) {
                        $roots[$parent['line_uuid']] = (int) $item->id;
                    }
                }
            }
            $possibleSend = DB::table('pos_kv2_deliveries')->where('submission_id', $submission->id)
                ->whereIn('state', ['sending', 'sent_unconfirmed', 'uncertain', 'confirmed'])->exists();
            foreach (DB::table('pos_kv2_work')->where('submission_id', $submission->id)->get() as $work) {
                $line = Wire::read($work->line);
                $root = $roots[$line['parent_line_uuid'] ?? $line['line_uuid']] ?? null;
                $items = $root ? OrderItem::query()->where('order_id', $order->id)->where('product_id', $line['product_id'])
                    ->where(fn ($q) => $q->where('id', $root)->orWhere('parent_order_item_id', $root))->get() : collect();
                $kind = $work->done_at ? 'done' : ($submission->released_at || $possibleSend ? 'review_required' : 'not_released');
                if ($items->count() !== 1) {
                    if ($kind !== 'not_released') {
                        $review = array_merge($review, OrderItem::query()->where('order_id', $order->id)->where('product_id', $line['product_id'])->pluck('id')->all());
                    }

                    continue; // Ambiguous mapping never guesses that nothing was prepared.
                }
                $id = (int) $items->sole()->id;
                if ($kind === 'done') {
                    $done[] = $id;
                } elseif ($kind === 'review_required') {
                    $review[] = $id;
                }
                $evidence[] = ['item_id' => $id, 'submission_uuid' => $submission->uuid, 'line_uuid' => $line['line_uuid'],
                    'revision' => (int) $work->revision, 'quantity' => $work->quantity, 'evidence' => $kind];
            }
        }
        $done = array_values(array_unique($done));
        sort($done);
        $review = array_values(array_diff(array_unique($review), $done));
        sort($review);

        return compact('done', 'review', 'evidence');
    }

    /** Existing explicit prepared yes/no choice is retained, but cannot deny Done. */
    public static function assertChoice(Order $order, array $cancelled, bool $prepared): array
    {
        $state = self::forOrder($order);
        $ids = array_map(fn ($pair) => (int) $pair[0]->id, $cancelled);
        if (! $prepared && array_intersect($ids, $state['done']) !== []) {
            throw new QrDineInException('kitchen_preparation_review_required', 409,
                'These items have completed kitchen work. Review them as prepared before cancelling.');
        }

        return array_values(array_filter($state['evidence'], fn ($e) => in_array($e['item_id'],$ids,true)));
    }
}
