<?php

declare(strict_types=1);

namespace App\Support\Inventory;

use App\Support\StockDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LAUNCH review add-on (owner decision D4, tester calls 7 and 9) — the stock
 * breakdown by container at a branch: pos_stock_container_balances (pieces
 * per LEAF container: 2 crates of 12 bottles are 24 bottles) and its
 * append-only ledger pos_stock_container_movements.
 *
 * The breakdown is shown next to the live total and is NEVER used to compute
 * stock. In pos_api only a device stock.count writes it (reason
 * 'device_count'); sales, production and cancellation waste never do.
 */
final class ContainerBreakdown
{
    public const REASON_DEVICE_COUNT = 'device_count';

    private const MAX_DEPTH = 5;

    public function __construct(private readonly int $companyId) {}

    /** A container of this ingredient, by uuid (company-scoped; a deleted one still counts). */
    public function container(int $ingredientId, string $uuid): ?object
    {
        return DB::table('pos_ingredient_units')
            ->where('company_id', $this->companyId)
            ->where('ingredient_id', $ingredientId)
            ->where('uuid', $uuid)
            ->first();
    }

    /** The ingredient's live leaf containers (holding no other container). @return list<int> */
    public function liveLeafIds(int $ingredientId): array
    {
        return DB::table('pos_ingredient_units')
            ->where('company_id', $this->companyId)
            ->where('ingredient_id', $ingredientId)
            ->whereNull('deleted_at')
            ->whereNull('contains_unit_id')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * $pieces of $container as leaf pieces: nesting multiplied out down to the
     * container that holds nothing ("2 crates" of 12 × bottle = 24 bottles).
     *
     * @return array<int, BigDecimal> leaf container id => pieces
     */
    public function leafPieces(object $container, string $pieces): array
    {
        $current = $container;
        $count = BigDecimal::of(StockDecimal::exact($pieces));
        for ($depth = 0; $current->contains_unit_id !== null; $depth++) {
            if ($depth >= self::MAX_DEPTH) {
                throw new RuntimeException('container '.$container->id.' nests too deep');
            }
            $inner = DB::table('pos_ingredient_units')
                ->where('company_id', $this->companyId)
                ->where('ingredient_id', (int) $current->ingredient_id)
                ->where('id', (int) $current->contains_unit_id)
                ->first();
            if ($inner === null) {
                throw new RuntimeException('container '.$current->id.' holds an unknown container');
            }
            $count = $count->multipliedBy(BigDecimal::of(StockDecimal::exact($current->contains_quantity)));
            $current = $inner;
        }

        return [(int) $current->id => $count];
    }

    /**
     * Set a branch's breakdown of an ingredient to exactly $leafPieces: every
     * other container it holds there goes to 0. Each change appends a ledger
     * row (balance = Σ delta_pieces).
     *
     * @param  array<int, BigDecimal>  $leafPieces  leaf container id => pieces
     * @param  array{stock_movement_id?: int|null, reference_type?: string|null, reference_id?: int|null, pos_staff_id?: int|null}  $source
     */
    public function setBranch(int $branchId, int $ingredientId, array $leafPieces, string $reason, CarbonInterface $at, array $source = []): void
    {
        $existing = DB::table('pos_stock_container_balances')
            ->where('branch_id', $branchId)
            ->where('ingredient_id', $ingredientId)
            ->lockForUpdate()
            ->get()
            ->keyBy(static fn (object $row): int => (int) $row->container_id);

        $targets = [];
        foreach ($existing as $containerId => $row) {
            $targets[$containerId] = BigDecimal::zero();
        }
        foreach ($leafPieces as $containerId => $pieces) {
            $targets[(int) $containerId] = $pieces;
        }
        ksort($targets);

        $now = now();
        foreach ($targets as $containerId => $target) {
            $new = $target->toScale(StockDecimal::QUANTITY_SCALE, RoundingMode::HALF_UP);
            if ($new->isNegative()) {
                $new = BigDecimal::zero()->toScale(StockDecimal::QUANTITY_SCALE);
            }
            $row = $existing->get($containerId);
            $old = BigDecimal::of(StockDecimal::exact($row?->pieces ?? 0))->toScale(StockDecimal::QUANTITY_SCALE, RoundingMode::HALF_UP);
            $delta = $new->minus($old);
            if ($delta->isZero()) {
                continue;
            }

            if ($row !== null) {
                DB::table('pos_stock_container_balances')->where('id', $row->id)
                    ->update(['pieces' => (string) $new, 'updated_at' => $now]);
            } else {
                DB::table('pos_stock_container_balances')->insert([
                    'company_id' => $this->companyId, 'branch_id' => $branchId, 'ingredient_id' => $ingredientId,
                    'container_id' => $containerId, 'pieces' => (string) $new, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            DB::table('pos_stock_container_movements')->insert([
                'company_id' => $this->companyId,
                'branch_id' => $branchId,
                'ingredient_id' => $ingredientId,
                'container_id' => $containerId,
                'delta_pieces' => (string) $delta,
                'pieces_after' => (string) $new,
                'reason' => $reason,
                'stock_movement_id' => $source['stock_movement_id'] ?? null,
                'reference_type' => $source['reference_type'] ?? null,
                'reference_id' => $source['reference_id'] ?? null,
                'recorded_by_pos_staff_id' => $source['pos_staff_id'] ?? null,
                'occurred_at' => $at,
                'created_at' => $now,
            ]);
        }
    }
}
