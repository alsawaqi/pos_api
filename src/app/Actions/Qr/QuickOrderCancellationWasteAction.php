<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Actions\Device\Sync\Handlers\ProductWasteHandler;
use App\Actions\Tables\BookTableCancellationWasteAction;
use App\Models\BranchStock;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\QrOrderRound;
use App\Models\StockMovement;
use App\Models\SyncEvent;
use App\Models\WasteRecord;
use Illuminate\Support\Str;
use RuntimeException;

/** Uses the existing frozen-ingredient plan and shelf-waste domain rules. */
final class QuickOrderCancellationWasteAction
{
    public function __construct(private readonly BookTableCancellationWasteAction $ingredients, private readonly ProductWasteHandler $shelf) {}

    public function preparedIds(Order $order): array
    {
        $ids = [];
        foreach (QrOrderRound::query()->where('order_id', $order->id)->where('status', QrOrderRound::STATUS_ACCEPTED)
            ->whereNotNull('kitchen_printed_at')->orderBy('id')->get() as $round) {
            foreach ($round->priced_lines ?? [] as $line) {
                if (! isset($line['held_reason']) && isset($line['order_item_id'])) {
                    $ids[] = (int) $line['order_item_id'];
                }
            }
        }
        // LAUNCH-P4 — a prepared combo line's chosen items were prepared too:
        // their own recipes and shelves are what is wasted.
        if ($ids !== []) {
            array_push($ids, ...OrderItem::query()->where('order_id', $order->id)
                ->whereIn('parent_order_item_id', $ids)->pluck('id')->map(static fn ($id): int => (int) $id)->all());
        }
        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    public function handle(Device $device, Order $order, array $preparedIds, int $staff, string $request): array
    {
        $items = $order->items->filter(fn ($item): bool => in_array((int) $item->id, $preparedIds, true)
            && $item->status !== 'void' && (float) $item->qty > 0);
        $plan = $this->ingredients->plan($items->map(fn ($item): array => [$item, (float) $item->qty])->all(), true);
        $note = 'cancelled expired QR order '.$order->uuid.' — '.$request;
        $records = [];
        foreach ($plan['ingredients'] as $row) {
            $at = now();
            $waste = WasteRecord::query()->create(['uuid' => (string) Str::uuid(), 'branch_id' => $order->branch_id,
                'ingredient_id' => $row['ingredient_id'], 'quantity' => $row['qty'], 'reason' => 'other',
                'unit_at_set' => $row['unit'], 'unit_cost_at_time' => $row['unit_cost'], 'notes' => $note, 'occurred_at' => $at]);
            $stock = BranchStock::query()->firstOrCreate(['branch_id' => $order->branch_id, 'ingredient_id' => $row['ingredient_id']],
                ['quantity' => 0, 'last_movement_at' => $at]);
            BranchStock::query()->whereKey($stock->id)->toBase()->increment('quantity', -$row['qty'], ['last_movement_at' => $at, 'updated_at' => $at]);
            StockMovement::query()->create(['branch_id' => $order->branch_id, 'ingredient_id' => $row['ingredient_id'],
                'movement_type' => StockMovement::TYPE_WASTE, 'quantity' => -$row['qty'], 'unit_cost_at_time' => $row['unit_cost'],
                'reference_type' => 'order', 'reference_id' => $order->id, 'recorded_by_pos_staff_id' => $staff,
                'occurred_at' => $at, 'created_at' => $at]);
            $records[] = (int) $waste->id;
        }
        $shelfLines = [];
        foreach ($items as $item) {
            $product = Product::withTrashed()->whereKey($item->product_id)->where('company_id', $device->company_id)->first();
            if ($product !== null && in_array($product->stock_mode, ['unit', 'cooked'], true)) {
                $shelfLines[] = ['product_id' => (int) $product->id, 'qty' => (float) $item->qty, 'reason' => 'other'];
            }
        }
        if ($shelfLines !== []) {
            $event = new SyncEvent(['client_timestamp' => now(), 'payload_json' => [
                'lines' => $shelfLines, 'staff_id' => $staff, 'note' => $note,
            ]]);
            try {
                $result = $this->shelf->handle($event, $device, preparedQuickCancellation: [
                    'order_uuid' => $order->uuid, 'client_request_id' => $request, 'lines' => $shelfLines,
                ]);
            } catch (RuntimeException $e) {
                throw new QrChargeException('qr_waste_review_required', 409, 'Prepared stock needs review before cancellation: '.$e->getMessage());
            }
        }

        return ['ingredient_waste_ids' => $records, 'ingredient_cost_baisas' => $plan['cost_baisas'],
            'shelf_lines' => $result['wasted_lines'] ?? 0, 'prepared_item_ids' => $items->pluck('id')->all()];
    }
}
