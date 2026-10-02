<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Device\Sync\ConsumeInventoryAction;
use App\Models\BranchStock;
use App\Models\Order;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\TableSession;
use App\Models\TableSessionEvent;
use App\Models\WasteRecord;
use App\Support\StockDecimal;
use Illuminate\Support\Str;

/** Domain balances are locked before the journal; linked movements are inserted at its flush. */
final class BookTableCancellationWasteAction
{
    public function __construct(private readonly ConsumeInventoryAction $inventory) {}

    public function plan(array $cancelledItems, bool $prepared): array
    {
        $rows = [];
        foreach ($prepared ? $cancelledItems : [] as [$item, $qty]) {
            // A recipe-less made-to-order line can still have ingredient
            // add-ons. Read only its tracking mode, never its live recipe/cost.
            // Shelf stock remains owned by the existing product.waste proof.
            if (! is_array($item->recipe_snapshot_json)
                && Product::withTrashed()->whereKey($item->product_id)->value('stock_mode') !== 'ingredient') {
                continue;
            }
            foreach ($this->inventory->frozenIngredients($item) as $part) {
                $amount = round($part['qty'] * $qty, StockDecimal::QUANTITY_SCALE);
                if ($amount <= 0) {
                    continue;
                }
                // Keep distinct frozen cost/unit tranches rather than reprice
                // an older round or lose cost precision by averaging.
                $key = json_encode([$part['ingredient_id'], $part['unit'], $part['unit_cost']]);
                $rows[$key] ??= array_replace($part, ['qty' => 0]);
                $rows[$key]['qty'] = round($rows[$key]['qty'] + $amount, StockDecimal::QUANTITY_SCALE);
            }
        }
        $rows = array_values($rows);
        usort($rows, static fn ($a, $b): int => $a['ingredient_id'] <=> $b['ingredient_id']);

        return ['booked' => $rows !== [],
            'cost_baisas' => (int) round(array_sum(array_map(static fn ($r): float => $r['qty'] * $r['unit_cost'] * 1000, $rows))),
            'ingredients' => $rows];
    }

    /** Acquire/move stock before journal locks. No live recipe or cost reads. */
    public function book(Order $order, TableSession $seat, array $payload, array $waste): \Closure
    {
        $movements = [];
        foreach ($waste['ingredients'] as $row) {
            $at = now();
            WasteRecord::query()->create([
                'uuid' => (string) Str::uuid(), 'branch_id' => $order->branch_id,
                'ingredient_id' => $row['ingredient_id'], 'quantity' => $row['qty'],
                'reason' => 'other', 'unit_at_set' => $row['unit'], 'unit_cost_at_time' => $row['unit_cost'],
                'notes' => 'cancelled after preparation — table '.$seat->table->label.' — '.$payload['client_request_id'],
                'occurred_at' => $at,
            ]);
            $stock = BranchStock::query()->firstOrCreate(
                ['branch_id' => $order->branch_id, 'ingredient_id' => $row['ingredient_id']],
                ['quantity' => 0, 'last_movement_at' => $at],
            );
            BranchStock::query()->whereKey($stock->id)->toBase()->increment('quantity', -$row['qty'],
                ['last_movement_at' => $at, 'updated_at' => $at]);
            $movements[] = ['branch_id' => $order->branch_id, 'ingredient_id' => $row['ingredient_id'],
                'movement_type' => StockMovement::TYPE_WASTE, 'quantity' => -$row['qty'],
                'unit_cost_at_time' => $row['unit_cost'], 'reference_type' => 'table_session_event',
                'recorded_by_pos_staff_id' => $payload['staff_id'] ?? null, 'occurred_at' => $at, 'created_at' => $at];
        }

        return static function (TableSessionEvent $event) use ($movements): void {
            foreach ($movements as $attributes) {
                StockMovement::query()->create($attributes + ['reference_id' => $event->id]);
            }
        };
    }
}
