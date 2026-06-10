<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\SyncEventHandler;
use App\Models\BranchStock;
use App\Models\Device;
use App\Models\Ingredient;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockMovement;
use App\Models\SyncEvent;
use App\Models\WasteRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Phase A (Additions §2.8) — processes a `stock.count` sync event:
 * the device's day-end physical count of branch ingredients.
 *
 * Mirrors pos_merchant's SubmitStockCountAction exactly, so a count
 * reconciles identically no matter which surface entered it:
 *
 *   counted_pieces × units_per_piece → counted primary units
 *     (ratio = ingredient's piece config, or 1 when the base unit is
 *      itself 'piece'; fractional pieces rejected when the ingredient
 *      forbids them)
 *   expected = current pos_branch_stock balance
 *   variance = counted − expected
 *     < 0 → pos_waste_records row (reason reconciliation_variance,
 *           POSITIVE qty) + signed-negative waste movement — the
 *           merchant Loss/Waste report picks it up with no extra wiring
 *     > 0 → positive adjustment movement (found more than booked)
 *     = 0 → line only, no movement
 *
 * All lines land in one transaction with the pos_stock_counts header
 * (recorded_by_pos_staff_id — the device plane's actor), keeping the
 * ledger invariant Σ(movements) == branch_stock.quantity intact.
 * client_timestamp ordering + per-device client_event_id idempotency
 * come from the surrounding sync pipeline.
 */
class StockCountHandler implements SyncEventHandler
{
    public function handle(SyncEvent $event, Device $device): array
    {
        $payload = (array) $event->payload_json;

        $validator = Validator::make($payload, [
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.ingredient_id' => ['required', 'integer', 'distinct'],
            'lines.*.counted_pieces' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'lines.*.counted_units' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'note' => ['sometimes', 'nullable', 'string'],
            'staff_id' => ['sometimes', 'nullable', 'integer'],
            'counted_at' => ['sometimes', 'nullable', 'string'],
        ]);
        if ($validator->fails()) {
            throw new RuntimeException('invalid stock.count payload: '.implode('; ', $validator->errors()->all()));
        }

        $countedAt = isset($payload['counted_at'])
            ? Carbon::parse((string) $payload['counted_at'])
            : ($event->client_timestamp ?? now());
        $staffId = isset($payload['staff_id']) ? (int) $payload['staff_id'] : null;
        $note = isset($payload['note']) && trim((string) $payload['note']) !== '' ? trim((string) $payload['note']) : null;

        // Resolve + convert every line BEFORE writing anything, so a
        // bad line fails the whole event (atomic, like the merchant flow).
        $resolved = [];
        foreach ($payload['lines'] as $line) {
            $ingredient = Ingredient::query()
                ->where('company_id', $device->company_id)
                ->find((int) $line['ingredient_id']);
            if ($ingredient === null) {
                throw new RuntimeException('unknown ingredient in stock.count: '.$line['ingredient_id']);
            }

            $countedPieces = isset($line['counted_pieces']) && $line['counted_pieces'] !== null
                ? (float) $line['counted_pieces']
                : null;
            $countedUnits = isset($line['counted_units']) && $line['counted_units'] !== null
                ? (float) $line['counted_units']
                : null;
            if ($countedPieces === null && $countedUnits === null) {
                throw new RuntimeException('stock.count line for ingredient '.$ingredient->id.' has no counted amount');
            }

            if ($countedPieces !== null) {
                if (! (bool) ($ingredient->allow_fractional_pieces ?? true)
                    && abs($countedPieces - round($countedPieces)) > 0.0000001) {
                    throw new RuntimeException('ingredient '.$ingredient->id.' is counted in whole pieces');
                }
                $ratio = $this->unitsPerPiece($ingredient);
                if ($ratio === null) {
                    throw new RuntimeException('ingredient '.$ingredient->id.' has no units-per-piece ratio — count it in its base unit');
                }
                // Pieces are authoritative when both were sent.
                $countedUnits = $countedPieces * $ratio;
            }

            $resolved[] = [
                'ingredient' => $ingredient,
                'counted_pieces' => $countedPieces,
                'counted_units' => round((float) $countedUnits, 3),
            ];
        }

        return DB::transaction(function () use ($resolved, $device, $staffId, $note, $countedAt): array {
            $count = StockCount::create([
                'uuid' => (string) Str::uuid(),
                'company_id' => $device->company_id,
                'branch_id' => $device->branch_id,
                'note' => $note,
                'recorded_by_pos_staff_id' => $staffId,
                'counted_at' => $countedAt,
            ]);

            $linesWithVariance = 0;

            foreach ($resolved as $line) {
                /** @var Ingredient $ingredient */
                $ingredient = $line['ingredient'];

                $expected = (float) (BranchStock::query()
                    ->where('branch_id', $device->branch_id)
                    ->where('ingredient_id', $ingredient->id)
                    ->value('quantity') ?? 0.0);
                $variance = round($line['counted_units'] - $expected, 3);
                $unitCost = (float) ($ingredient->default_unit_cost ?? 0);

                $movementId = null;
                if ($variance < 0) {
                    // Shortfall → waste record + negative waste movement.
                    $waste = WasteRecord::create([
                        'uuid' => (string) Str::uuid(),
                        'branch_id' => $device->branch_id,
                        'ingredient_id' => $ingredient->id,
                        'quantity' => number_format(abs($variance), 3, '.', ''),
                        'reason' => WasteRecord::REASON_RECONCILIATION_VARIANCE,
                        'unit_at_set' => (string) $ingredient->unit,
                        'unit_cost_at_time' => number_format($unitCost, 3, '.', ''),
                        'notes' => $this->lineNote($line, $expected, $note),
                        'occurred_at' => $countedAt,
                    ]);
                    $movementId = $this->move(
                        $device,
                        $ingredient,
                        $variance,
                        $unitCost,
                        StockMovement::TYPE_WASTE,
                        'pos_waste_records',
                        (int) $waste->id,
                        $staffId,
                        $countedAt,
                        $this->lineNote($line, $expected, $note),
                    );
                    $linesWithVariance++;
                } elseif ($variance > 0) {
                    // Overage → positive adjustment.
                    $movementId = $this->move(
                        $device,
                        $ingredient,
                        $variance,
                        $unitCost,
                        StockMovement::TYPE_ADJUSTMENT,
                        'pos_stock_counts',
                        (int) $count->id,
                        $staffId,
                        $countedAt,
                        $this->lineNote($line, $expected, $note),
                    );
                    $linesWithVariance++;
                }

                StockCountLine::create([
                    'stock_count_id' => $count->id,
                    'ingredient_id' => $ingredient->id,
                    'counted_pieces' => $line['counted_pieces'] !== null
                        ? number_format($line['counted_pieces'], 3, '.', '')
                        : null,
                    'counted_units' => number_format($line['counted_units'], 3, '.', ''),
                    'expected_units' => number_format($expected, 3, '.', ''),
                    'variance_units' => number_format($variance, 3, '.', ''),
                    'unit_cost_at_time' => number_format($unitCost, 3, '.', ''),
                    'stock_movement_id' => $movementId,
                ]);
            }

            return [
                'stock_count_id' => (int) $count->id,
                'lines' => count($resolved),
                'lines_with_variance' => $linesWithVariance,
            ];
        });
    }

    /**
     * Primary units per ONE piece — the device-plane mirror of
     * Ingredient::unitsPerPiece() in pos_merchant.
     */
    private function unitsPerPiece(Ingredient $ingredient): ?float
    {
        if ($ingredient->piece_unit_label !== null && $ingredient->units_per_piece !== null) {
            return (float) $ingredient->units_per_piece;
        }

        return (string) $ingredient->unit === 'piece' ? 1.0 : null;
    }

    /**
     * Append the signed movement + move the balance (the same pair
     * ConsumeInventoryAction writes). Returns the movement id.
     */
    private function move(
        Device $device,
        Ingredient $ingredient,
        float $signedQty,
        float $unitCost,
        string $type,
        string $referenceType,
        int $referenceId,
        ?int $staffId,
        Carbon $at,
        string $note,
    ): int {
        $movement = StockMovement::create([
            'branch_id' => $device->branch_id,
            'ingredient_id' => $ingredient->id,
            'movement_type' => $type,
            'quantity' => number_format($signedQty, 3, '.', ''),
            'unit_cost_at_time' => number_format($unitCost, 3, '.', ''),
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'recorded_by_pos_staff_id' => $staffId,
            'note' => $note,
            'occurred_at' => $at,
            'created_at' => now(),
        ]);

        $stock = BranchStock::firstOrNew([
            'branch_id' => $device->branch_id,
            'ingredient_id' => $ingredient->id,
        ]);
        $stock->quantity = (float) $stock->quantity + $signedQty;
        $stock->last_movement_at = now();
        $stock->save();

        return (int) $movement->id;
    }

    /**
     * @param  array{ingredient: Ingredient, counted_pieces: float|null, counted_units: float}  $line
     */
    private function lineNote(array $line, float $expected, ?string $note): string
    {
        $ingredient = $line['ingredient'];
        $counted = $line['counted_pieces'] !== null
            ? sprintf(
                '%s %s (= %s %s)',
                rtrim(rtrim(number_format($line['counted_pieces'], 3, '.', ''), '0'), '.'),
                $ingredient->piece_unit_label ?? 'piece(s)',
                number_format($line['counted_units'], 3, '.', ''),
                (string) $ingredient->unit,
            )
            : sprintf('%s %s', number_format($line['counted_units'], 3, '.', ''), (string) $ingredient->unit);

        $text = sprintf('Day-end stock count: counted %s, expected %s.', $counted, number_format($expected, 3, '.', ''));

        return $note !== null ? $text.' '.$note : $text;
    }
}
