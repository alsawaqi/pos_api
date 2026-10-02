<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync\Handlers;

use App\Actions\Device\Sync\FoldLateMovementIntoCount;
use App\Actions\Device\Sync\SyncEventHandler;
use App\Actions\Device\Sync\TenantReferenceGuard;
use App\Models\BranchStock;
use App\Models\Device;
use App\Models\Ingredient;
use App\Models\StockCount;
use App\Models\StockCountLine;
use App\Models\StockMovement;
use App\Models\SyncEvent;
use App\Models\WasteRecord;
use App\Support\StockDecimal;
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

        // The count moment is the DEVICE's (when staff counted), never the
        // sync time; whole seconds, the precision the ledger stores.
        $countedAt = (isset($payload['counted_at'])
            ? Carbon::parse((string) $payload['counted_at'])
            : Carbon::instance($event->client_timestamp ?? now()))->startOfSecond();
        $staffId = isset($payload['staff_id']) ? (int) $payload['staff_id'] : null;
        // Phase 4 — the recorded_by staff id (an audit column) must be a staff
        // member of the device's own company; withTrashed keeps offline-queued
        // events by a since-terminated recorder settling.
        TenantReferenceGuard::assertStaffInTenant($device, $staffId, 'stock.count references a staff member outside the device tenant');
        $note = isset($payload['note']) && trim((string) $payload['note']) !== '' ? trim((string) $payload['note']) : null;

        // Resolve + convert every line BEFORE writing anything, so a
        // bad line fails the whole event (atomic, like the merchant flow).
        $resolved = [];
        $skippedPrep = [];
        foreach ($payload['lines'] as $line) {
            $ingredient = Ingredient::query()
                ->where('company_id', $device->company_id)
                ->find((int) $line['ingredient_id']);
            if ($ingredient === null) {
                throw new RuntimeException('unknown ingredient in stock.count: '.$line['ingredient_id']);
            }
            // LAUNCH-P3 — a prep item has no stock of its own (its raw
            // ingredients are counted). The device list leaves prep items
            // out; a line from a stale list is skipped, never booked.
            if ((bool) ($ingredient->is_prep ?? false)) {
                $skippedPrep[] = (int) $ingredient->id;

                continue;
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
                'counted_units' => round((float) $countedUnits, StockDecimal::QUANTITY_SCALE),
            ];
        }
        if ($resolved === []) {
            throw new RuntimeException('stock.count names only prep items, which have no stock of their own');
        }

        return DB::transaction(function () use ($resolved, $device, $staffId, $note, $countedAt, $skippedPrep): array {
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

                // LAUNCH-P2 P2-6 — the book balance AT THE COUNT MOMENT
                // (sale time, not sync time): sales made after the count but
                // synced before this event no longer inflate the variance;
                // sales made before it that sync later are folded in when
                // they arrive (FoldLateMovementIntoCount).
                $expected = $this->bookBalanceAt((int) $device->branch_id, (int) $ingredient->id, $countedAt);
                $variance = round($line['counted_units'] - $expected, StockDecimal::QUANTITY_SCALE);
                $unitCost = (string) StockDecimal::unitCost($ingredient->default_unit_cost ?? 0);

                $movementId = null;
                $wasteId = null;
                if ($variance < 0) {
                    // Shortfall → waste record + negative waste movement.
                    $waste = WasteRecord::create([
                        'uuid' => (string) Str::uuid(),
                        'branch_id' => $device->branch_id,
                        'ingredient_id' => $ingredient->id,
                        'quantity' => StockDecimal::quantity(abs($variance)),
                        'reason' => WasteRecord::REASON_RECONCILIATION_VARIANCE,
                        'unit_at_set' => (string) $ingredient->unit,
                        'unit_cost_at_time' => $unitCost,
                        'notes' => $this->lineNote($line, $expected, $note),
                        'occurred_at' => $countedAt,
                    ]);
                    $wasteId = (int) $waste->id;
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
                        ? StockDecimal::quantity($line['counted_pieces'])
                        : null,
                    'counted_units' => StockDecimal::quantity($line['counted_units']),
                    'expected_units' => StockDecimal::quantity($expected),
                    'variance_units' => StockDecimal::quantity($variance),
                    'unit_cost_at_time' => $unitCost,
                    'stock_movement_id' => $movementId,
                    'waste_record_id' => $wasteId,
                ]);
            }

            return [
                'stock_count_id' => (int) $count->id,
                'lines' => count($resolved),
                'lines_with_variance' => $linesWithVariance,
            ] + ($skippedPrep === [] ? [] : ['skipped_prep_ingredient_ids' => $skippedPrep]);
        });
    }

    /**
     * LAUNCH-P2 P2-6 — the branch's book balance of an ingredient AT $at: the
     * running balance minus every movement dated after $at. A movement in the
     * count's own second already on the books counts as before it.
     */
    private function bookBalanceAt(int $branchId, int $ingredientId, Carbon $at): float
    {
        $balance = (float) (DB::table('pos_branch_stock')
            ->where('branch_id', $branchId)
            ->where('ingredient_id', $ingredientId)
            ->value('quantity') ?? 0.0);
        $after = (float) DB::table('pos_stock_movements')
            ->where('branch_id', $branchId)
            ->where('ingredient_id', $ingredientId)
            ->where('occurred_at', '>', $at)
            ->sum('quantity');

        return round($balance - $after, StockDecimal::QUANTITY_SCALE);
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
    /**
     * Append the signed movement + move the balance (the same pair
     * ConsumeInventoryAction writes, an atomic SQL increment). Returns the
     * movement id. LAUNCH-P2 P2-6 — when a LATER count of this branch and
     * ingredient is already on the books (this device count was taken
     * earlier but synced after it), the variance is folded into that count.
     */
    private function move(
        Device $device,
        Ingredient $ingredient,
        float $signedQty,
        string $unitCost,
        string $type,
        string $referenceType,
        int $referenceId,
        ?int $staffId,
        Carbon $at,
        string $note,
    ): int {
        $quantity = (string) StockDecimal::quantity($signedQty);
        $movement = StockMovement::create([
            'branch_id' => $device->branch_id,
            'ingredient_id' => $ingredient->id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'unit_cost_at_time' => $unitCost,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'recorded_by_pos_staff_id' => $staffId,
            'note' => $note,
            'occurred_at' => $at,
            'created_at' => now(),
        ]);

        $stock = BranchStock::query()->firstOrCreate(
            ['branch_id' => $device->branch_id, 'ingredient_id' => $ingredient->id],
            ['quantity' => 0, 'last_movement_at' => now()],
        );
        BranchStock::query()
            ->whereKey($stock->getKey())
            ->toBase()
            ->increment('quantity', $quantity, [
                'last_movement_at' => now(),
                'updated_at' => now(),
            ]);

        (new FoldLateMovementIntoCount)->handle(
            (int) $device->branch_id,
            (int) $ingredient->id,
            $quantity,
            $unitCost,
            $at,
            null,
            $staffId,
        );

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
                StockDecimal::format($line['counted_pieces'], 0, StockDecimal::QUANTITY_SCALE),
                $ingredient->piece_unit_label ?? 'piece(s)',
                StockDecimal::quantity($line['counted_units']),
                (string) $ingredient->unit,
            )
            : sprintf('%s %s', StockDecimal::quantity($line['counted_units']), (string) $ingredient->unit);

        $text = sprintf('Day-end stock count: counted %s, expected %s.', $counted, StockDecimal::quantity($expected));

        return $note !== null ? $text.' '.$note : $text;
    }
}
