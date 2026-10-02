<?php

declare(strict_types=1);

namespace App\Support\Recipes;

use App\Support\StockDecimal;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P3 P3-6 — the recipe of the moment of sale.
 *
 * pos_product_recipe_versions is append-only: the merchant portal writes one
 * row per recipe edit holding the recipe as it was BEFORE that edit, dated at
 * the edit (edited_at). So the recipe in force at a moment T is:
 *
 *   - the recipe_json of the FIRST version edited strictly after T (the state
 *     the next edit replaced), when there is one;
 *   - otherwise the current pos_product_recipes rows (nothing changed since).
 *
 * An edit stamped in the same second as T counts as already in force.
 * A device order copies its recipes at the order's client timestamp clamped
 * to now ({@see saleMoment()}): an offline sale synced after a recipe edit
 * keeps the recipe that was in force when it was sold. Without a moment
 * (QR orders, kitchen batches, the device config: all live) it is the
 * current recipe. Only product recipe lines are versioned; prep items explode
 * through their current recipe and costs are the live ones, as before.
 *
 * Lines come back as {ingredient_id, quantity (base unit, decimal string),
 * unit (the base unit set with the line, or null)}, in recipe order.
 */
final class RecipeInForce
{
    /** @var array<int, list<array{ingredient_id: int, quantity: string, unit: string|null}>> */
    private array $memo = [];

    private readonly ?string $at;

    public function __construct(?CarbonInterface $at = null)
    {
        // The ledger stores UTC wall time at whole seconds.
        $this->at = $at?->copy()->utc()->format('Y-m-d H:i:s');
    }

    /**
     * The device sale moment: the client timestamp, never later than now
     * (a device clock running ahead cannot pick a future recipe).
     */
    public static function saleMoment(?CarbonInterface $clientTimestamp, ?CarbonInterface $now = null): CarbonImmutable
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        if ($clientTimestamp === null) {
            return $now;
        }
        $client = CarbonImmutable::instance($clientTimestamp)->utc();

        return $client->greaterThan($now) ? $now : $client;
    }

    /**
     * @return list<array{ingredient_id: int, quantity: string, unit: string|null}>
     */
    public function lines(int $productId): array
    {
        return $this->memo[$productId] ??= $this->resolve($productId);
    }

    /**
     * @return list<array{ingredient_id: int, quantity: string, unit: string|null}>
     */
    private function resolve(int $productId): array
    {
        if ($this->at !== null) {
            $version = DB::table('pos_product_recipe_versions')
                ->where('product_id', $productId)
                ->where('edited_at', '>', $this->at)
                ->orderBy('edited_at')
                ->orderBy('id')
                ->first();
            if ($version !== null) {
                $lines = $this->decode($version->recipe_json ?? null);
                if ($lines !== null) {
                    return $lines;
                }
                try {
                    logger()->warning('LAUNCH-P3: unreadable recipe version, the current recipe was copied', [
                        'product_id' => $productId,
                        'version_id' => (int) $version->id,
                    ]);
                } catch (\Throwable) {
                    // Best-effort; the sale goes on with the current recipe.
                }
            }
        }

        return DB::table('pos_product_recipes')
            ->where('product_id', $productId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => [
                'ingredient_id' => (int) $row->ingredient_id,
                'quantity' => StockDecimal::exact($row->quantity),
                'unit' => $row->unit_at_set !== null && $row->unit_at_set !== '' ? (string) $row->unit_at_set : null,
            ])
            ->values()
            ->all();
    }

    /**
     * A version's recipe_json ([{ingredient_id, quantity, unit, ...}], in the
     * base unit; "[]" = there was no recipe). null = unreadable.
     *
     * @return list<array{ingredient_id: int, quantity: string, unit: string|null}>|null
     */
    private function decode(mixed $json): ?array
    {
        $decoded = is_string($json) ? json_decode($json, true) : $json;
        if (! is_array($decoded) || ! array_is_list($decoded)) {
            return null;
        }

        $lines = [];
        foreach ($decoded as $entry) {
            if (! is_array($entry)
                || ! is_numeric($entry['ingredient_id'] ?? null)
                || ! is_numeric($entry['quantity'] ?? null)) {
                return null;
            }
            $unit = $entry['unit'] ?? null;
            $lines[] = [
                'ingredient_id' => (int) $entry['ingredient_id'],
                'quantity' => StockDecimal::exact($entry['quantity']),
                'unit' => is_string($unit) && $unit !== '' ? $unit : null,
            ];
        }

        return $lines;
    }
}
