<?php

declare(strict_types=1);

namespace App\Support\Recipes;

use App\Support\StockDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\BigRational;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P3 P3-4 — a prep item (a sauce, a dough: pos_ingredients.is_prep)
 * has its own recipe (pos_ingredient_recipes) and yield, and no stock of its
 * own. Wherever the device API copies or evaluates a recipe it explodes the
 * prep lines into the raw ingredients behind them, so every copy keeps its
 * shape — raw ingredient lines only — and a prep item never gets a stock row.
 *
 * Explode rule (LAUNCH-P3 data contract): a line of prep P with quantity q
 * (P's base units) becomes, for each component c of P,
 *
 *     q × c.quantity ÷ P.prep_yield_quantity   of c
 *
 * repeated until only raw ingredients remain; lines of the same raw
 * ingredient merge. A prep may use another prep, at most MAX_LEVELS deep.
 * Arithmetic is exact (rationals) until the end: quantities are then rounded
 * half up to 4 decimals (8 for a per-unit amount that is multiplied later,
 * {@see PER_UNIT_SCALE}), costs to 6. The cost of P per base unit is
 * Σ(c.quantity × cost(c)) ÷ P.prep_yield_quantity, recursively — the same
 * number as the exploded raw lines priced at their own costs.
 *
 * Defensive guards. pos_merchant refuses a cycle, a 4th level, a prep without
 * a yield and a component of another company when a prep recipe is saved,
 * but a sale or a batch must never fail or loop on bad data: such a part is
 * skipped (it deducts and costs nothing), recorded in problems() and logged.
 *
 * Reads go through the query builder, so soft-deleted ingredients still
 * resolve (an old recipe keeps its meaning) and a database without the P3
 * columns simply has no prep items. One instance caches what it loaded; use
 * one per request or transaction, never a long-lived singleton.
 */
final class PrepExploder
{
    /** Prep levels allowed: a dish's prep (1) may use a prep (2) that uses a prep (3). */
    public const MAX_LEVELS = 3;

    /**
     * LAUNCH-P3 fix order 1 (M1-b, L6) — decimals kept on a PER-UNIT amount
     * that is multiplied later: an order line's copy (× the line qty at pay,
     * and by the portal's cost of goods) and the kitchen's per-piece lines
     * (× the pieces). 15 ml of a saffron syrup holds 0.00003 kg of saffron;
     * at the ledger's 4 decimals that copy would read 0 (no cost of goods).
     */
    public const PER_UNIT_SCALE = 8;

    public const PROBLEM_CYCLE = 'cycle';

    public const PROBLEM_TOO_DEEP = 'too_deep';

    public const PROBLEM_NO_YIELD = 'no_yield';

    public const PROBLEM_OTHER_COMPANY = 'other_company';

    /** @var array<int, object|null> pos_ingredients rows by id; null = no such row */
    private array $ingredients = [];

    /** @var array<int, list<object>> pos_ingredient_recipes rows by prep id */
    private array $components = [];

    /** @var list<array{prep_ingredient_id: int, reason: string, path: list<int>}> */
    private array $problems = [];

    public function __construct(private readonly int $companyId) {}

    /**
     * Explode recipe lines into merged raw-ingredient lines.
     *
     * Each line: ingredient_id, quantity (in that ingredient's base unit),
     * optional unit (the recipe's unit_at_set; an exploded line takes the raw
     * ingredient's unit) and optional group: lines merge per (group, raw
     * ingredient), e.g. an add-on option's add and remove lines stay apart.
     * Output keeps first-appearance order; `line` is the index of the input
     * line that first produced an output line.
     *
     * @param  iterable<array{ingredient_id: int|string, quantity: mixed, unit?: string|null, group?: string}>  $lines
     * @param  int|string  $multiplier  applied before rounding (a batch of N pieces)
     * @param  int  $scale  decimals of the result: the ledger's 4 for an amount that is
     *                      written as is, {@see PER_UNIT_SCALE} for a per-unit amount
     *                      that is multiplied later
     * @return list<array{ingredient_id: int, quantity: string, unit: string|null, unit_cost: string, group: string, line: int}>
     */
    public function explode(iterable $lines, int|string $multiplier = 1, int $scale = StockDecimal::QUANTITY_SCALE): array
    {
        $rows = [];
        foreach ($this->explodeExact($lines, $multiplier) as $line) {
            $rows[] = [
                'ingredient_id' => $line['ingredient_id'],
                'quantity' => (string) $line['quantity']->toScale($scale, RoundingMode::HALF_UP),
                'unit' => $line['unit'],
                'unit_cost' => $line['unit_cost'],
                'group' => $line['group'],
                'line' => $line['line'],
            ];
        }

        return $rows;
    }

    /**
     * The exploded raw lines with EXACT quantities (rationals, never rounded),
     * for arithmetic that must not round first — fix order 1 L6: the
     * kitchen's "can make up to N" divides a balance by the exact per-piece
     * amount.
     *
     * @param  iterable<array{ingredient_id: int|string, quantity: mixed, unit?: string|null, group?: string}>  $lines
     * @return list<array{ingredient_id: int, quantity: BigRational, unit: string|null, unit_cost: string, group: string, line: int}>
     */
    public function explodeRational(iterable $lines, int|string $multiplier = 1): array
    {
        return $this->explodeExact($lines, $multiplier);
    }

    /**
     * The cost of the lines, prep items costed through their recipes:
     * Σ(exact raw quantity × raw unit cost), rounded once to 6 decimals.
     *
     * @param  iterable<array{ingredient_id: int|string, quantity: mixed, unit?: string|null}>  $lines
     */
    public function cost(iterable $lines, int|string $multiplier = 1): string
    {
        $total = BigRational::zero();
        foreach ($this->explodeExact($lines, $multiplier) as $line) {
            $total = $total->plus($line['quantity']->multipliedBy($line['unit_cost']));
        }

        return (string) $total->toScale(StockDecimal::UNIT_COST_SCALE, RoundingMode::HALF_UP);
    }

    /** Whether this ingredient is a prep item (no stock of its own). */
    public function isPrep(int $ingredientId): bool
    {
        $this->preload([$ingredientId]);

        return self::isPrepRow($this->ingredients[$ingredientId] ?? null);
    }

    /** The pos_ingredients row (soft-deleted included), once loaded; null = none. */
    public function ingredient(int $ingredientId): ?object
    {
        $this->preload([$ingredientId]);

        return $this->ingredients[$ingredientId] ?? null;
    }

    /**
     * What was skipped so far (cycle, too deep, no yield, other company).
     *
     * @return list<array{prep_ingredient_id: int, reason: string, path: list<int>}>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    /**
     * Load the ingredient rows and prep components reachable from these ids,
     * batched per level (a few queries however many lines) and cached.
     *
     * @param  iterable<int|string>  $ingredientIds
     */
    public function preload(iterable $ingredientIds): void
    {
        $pending = $this->unloaded($ingredientIds);
        // The graph is finite and each round loads only unseen ids, so this
        // ends on its own; the cap is a belt-and-braces bound for bad data.
        for ($round = 0; $pending !== [] && $round < 16; $round++) {
            $rows = DB::table('pos_ingredients')
                ->whereIn('id', $pending)
                ->get()
                ->keyBy(static fn (object $row): int => (int) $row->id);

            $preps = [];
            foreach ($pending as $id) {
                $row = $rows->get($id);
                $this->ingredients[$id] = $row;
                if (self::isPrepRow($row)) {
                    $preps[] = $id;
                    $this->components[$id] = [];
                }
            }
            if ($preps === []) {
                return;
            }

            $next = [];
            $components = DB::table('pos_ingredient_recipes')
                ->whereIn('prep_ingredient_id', $preps)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();
            foreach ($components as $component) {
                $this->components[(int) $component->prep_ingredient_id][] = $component;
                $next[] = (int) $component->ingredient_id;
            }
            $pending = $this->unloaded($next);
        }
    }

    /**
     * @param  iterable<array{ingredient_id: int|string, quantity: mixed, unit?: string|null, group?: string}>  $lines
     * @return list<array{ingredient_id: int, quantity: BigRational, unit: string|null, unit_cost: string, group: string, line: int}>
     */
    private function explodeExact(iterable $lines, int|string $multiplier): array
    {
        $lines = is_array($lines) ? array_values($lines) : iterator_to_array($lines, false);
        $this->preload(array_map(static fn (array $line): int => (int) $line['ingredient_id'], $lines));

        $factor = BigRational::of(StockDecimal::exact($multiplier));
        $seen = count($this->problems);
        $out = [];
        foreach ($lines as $index => $line) {
            $this->walk(
                (int) $line['ingredient_id'],
                BigRational::of(StockDecimal::exact($line['quantity'] ?? 0))->multipliedBy($factor),
                isset($line['unit']) && $line['unit'] !== '' ? (string) $line['unit'] : null,
                (string) ($line['group'] ?? ''),
                $index,
                [],
                $out,
            );
        }
        if (count($this->problems) > $seen) {
            $this->report(array_slice($this->problems, $seen));
        }

        return array_values($out);
    }

    /**
     * @param  int  $line  the input line being exploded (first-appearance tag)
     * @param  list<int>  $path  the prep items above this line (cycle + depth guard)
     * @param  array<string, array{ingredient_id: int, quantity: BigRational, unit: string|null, unit_cost: string, group: string, line: int}>  $out
     */
    private function walk(int $ingredientId, BigRational $quantity, ?string $unit, string $group, int $line, array $path, array &$out): void
    {
        $row = $this->ingredients[$ingredientId] ?? null;

        if (! self::isPrepRow($row)) {
            $key = $group.'|'.$ingredientId;
            $out[$key] ??= [
                'ingredient_id' => $ingredientId,
                'quantity' => BigRational::zero(),
                'unit' => $unit ?? ($row !== null && isset($row->unit) ? (string) $row->unit : null),
                // The live per-base-unit cost, at its 6 decimals whatever the driver returned.
                'unit_cost' => (string) BigDecimal::of(StockDecimal::exact($row->default_unit_cost ?? 0))
                    ->toScale(StockDecimal::UNIT_COST_SCALE, RoundingMode::HALF_UP),
                'group' => $group,
                'line' => $line,
            ];
            $out[$key]['quantity'] = $out[$key]['quantity']->plus($quantity);

            return;
        }

        $problem = match (true) {
            in_array($ingredientId, $path, true) => self::PROBLEM_CYCLE,
            count($path) >= self::MAX_LEVELS => self::PROBLEM_TOO_DEEP,
            (int) $row->company_id !== $this->companyId => self::PROBLEM_OTHER_COMPANY,
            BigRational::of(StockDecimal::exact($row->prep_yield_quantity ?? null))->isNegativeOrZero() => self::PROBLEM_NO_YIELD,
            default => null,
        };
        if ($problem !== null) {
            $this->problems[] = ['prep_ingredient_id' => $ingredientId, 'reason' => $problem, 'path' => $path];

            return;
        }

        $yield = BigRational::of(StockDecimal::exact($row->prep_yield_quantity));
        $path[] = $ingredientId;
        foreach ($this->components[$ingredientId] ?? [] as $component) {
            $componentId = (int) $component->ingredient_id;
            $componentRow = $this->ingredients[$componentId] ?? null;
            if ($componentRow !== null && (int) $componentRow->company_id !== $this->companyId) {
                $this->problems[] = ['prep_ingredient_id' => $ingredientId, 'reason' => self::PROBLEM_OTHER_COMPANY, 'path' => $path];

                continue;
            }

            $this->walk(
                $componentId,
                $quantity->multipliedBy(StockDecimal::exact($component->quantity))->dividedBy($yield),
                null,
                $group,
                $line,
                $path,
                $out,
            );
        }
    }

    private static function isPrepRow(?object $row): bool
    {
        return $row !== null && (bool) ($row->is_prep ?? false);
    }

    /**
     * @param  iterable<int|string>  $ids
     * @return list<int>
     */
    private function unloaded(iterable $ids): array
    {
        $unloaded = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && ! array_key_exists($id, $this->ingredients)) {
                $unloaded[$id] = $id;
            }
        }

        return array_values($unloaded);
    }

    /**
     * @param  list<array{prep_ingredient_id: int, reason: string, path: list<int>}>  $problems
     */
    private function report(array $problems): void
    {
        try {
            logger()->warning('LAUNCH-P3: part of a recipe could not be exploded and was skipped', [
                'company_id' => $this->companyId,
                'problems' => $problems,
            ]);
        } catch (\Throwable) {
            // Logging is best-effort; never fail a sale or a batch over it.
        }
    }
}
