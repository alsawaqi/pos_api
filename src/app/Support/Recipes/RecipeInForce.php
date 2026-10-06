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
 * Fix order 1 M2 — the moment has a floor, because a device clock running
 * behind (a real-time-clock reset, a wrong manual time or zone) must not
 * copy a recipe from before the sale could have happened — typically the
 * "[]" (no recipe) state every product has before its first recipe save:
 *  - the device's credential epoch (saleMoment()'s $notBefore: the device's
 *    assignment_activated_at, else token_issued_at): P0 makes a device send
 *    its unsent sales before it is moved, so no sale it pushes predates it;
 *  - the product's created_at: a device cannot sell a product before it
 *    exists. When the product's FIRST version is "[]" dated within
 *    CREATION_SNAPSHOT_SECONDS of created_at, the product was created with
 *    its recipe in one save (the portal wizard writes both in one
 *    transaction), so that version's date is the floor too.
 * The floor never moves a moment that is already later.
 *
 * Lines come back as {ingredient_id, quantity (base unit, decimal string),
 * unit (the base unit set with the line, or null), order_types}, in recipe
 * order.
 *
 * LAUNCH packaging add-on — order_types is the line's "Used for" mask
 * (pos_product_recipes.order_types; in a version's recipe_json the optional
 * per-line `order_types`, absent = 15 = all four), so an offline sale synced
 * after a tick change keeps the ticks in force when it was sold.
 */
final class RecipeInForce
{
    /** A first "[]" version this close to the product's creation is the creation save itself. */
    public const CREATION_SNAPSHOT_SECONDS = 60;

    /** @var array<int, list<array{ingredient_id: int, quantity: string, unit: string|null, order_types: int}>> */
    private array $memo = [];

    private readonly ?string $at;

    public function __construct(?CarbonInterface $at = null)
    {
        // The ledger stores UTC wall time at whole seconds.
        $this->at = $at?->copy()->utc()->format('Y-m-d H:i:s');
    }

    /**
     * The device sale moment: the client timestamp, never later than now
     * (a device clock running ahead cannot pick a future recipe) and — fix
     * order 1 M2 — never earlier than $notBefore (the device's credential
     * epoch; a clock running behind cannot pick a recipe from before the
     * device could sell). The product's own floor is applied per product by
     * {@see lines()}.
     */
    public static function saleMoment(?CarbonInterface $clientTimestamp, ?CarbonInterface $now = null, ?CarbonInterface $notBefore = null): CarbonImmutable
    {
        $now = CarbonImmutable::instance($now ?? now())->utc();
        if ($clientTimestamp === null) {
            return $now;
        }
        $client = CarbonImmutable::instance($clientTimestamp)->utc();
        $moment = $client->greaterThan($now) ? $now : $client;

        if ($notBefore !== null) {
            $floor = CarbonImmutable::instance($notBefore)->utc();
            if ($floor->greaterThan($now)) {
                $floor = $now;
            }
            if ($moment->lessThan($floor)) {
                self::log('LAUNCH-P3: a sale moment before the device activation was moved up to it', [
                    'client_timestamp' => $client->toIso8601String(),
                    'floor' => $floor->toIso8601String(),
                ]);
                $moment = $floor;
            }
        }

        return $moment;
    }

    /**
     * @return list<array{ingredient_id: int, quantity: string, unit: string|null, order_types: int}>
     */
    public function lines(int $productId): array
    {
        return $this->memo[$productId] ??= $this->resolve($productId);
    }

    /**
     * @return list<array{ingredient_id: int, quantity: string, unit: string|null, order_types: int}>
     */
    private function resolve(int $productId): array
    {
        if ($this->at !== null) {
            $version = DB::table('pos_product_recipe_versions')
                ->where('product_id', $productId)
                ->where('edited_at', '>', $this->momentFor($productId))
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
                'order_types' => OrderTypes::normalize($row->order_types ?? null),
            ])
            ->values()
            ->all();
    }

    /**
     * The moment, floored per product (fix order 1 M2): never before the
     * product existed, nor before its creation save when the product was
     * created with its recipe. UTC wall time at whole seconds.
     */
    private function momentFor(int $productId): string
    {
        $moment = (string) $this->at;

        $createdAt = self::utcSecond(DB::table('pos_products')->where('id', $productId)->value('created_at'));
        if ($createdAt === null) {
            return $moment;
        }
        $floor = $createdAt;

        $first = DB::table('pos_product_recipe_versions')
            ->where('product_id', $productId)
            ->orderBy('edited_at')
            ->orderBy('id')
            ->first();
        $firstEditedAt = self::utcSecond($first?->edited_at ?? null);
        if ($first !== null && $firstEditedAt !== null && $this->decode($first->recipe_json ?? null) === []
            && $firstEditedAt >= $createdAt
            && $firstEditedAt <= CarbonImmutable::parse($createdAt, 'UTC')->addSeconds(self::CREATION_SNAPSHOT_SECONDS)->format('Y-m-d H:i:s')) {
            $floor = $firstEditedAt;
        }
        // A portal clock ahead of this server never lifts a moment past now.
        $floor = min($floor, now()->utc()->format('Y-m-d H:i:s'));

        if ($moment >= $floor) {
            return $moment;
        }
        self::log('LAUNCH-P3: a sale moment before the product existed was moved up to its creation', [
            'product_id' => $productId,
            'moment' => $moment,
            'floor' => $floor,
        ]);

        return $floor;
    }

    /** A database timestamp as UTC wall time at whole seconds ("Y-m-d H:i:s"); null when absent. */
    private static function utcSecond(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value, 'UTC')->utc()->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function log(string $message, array $context): void
    {
        try {
            logger()->warning($message, $context);
        } catch (\Throwable) {
            // Best-effort; a sale never fails over logging.
        }
    }

    /**
     * A version's recipe_json ([{ingredient_id, quantity, unit, ...}], in the
     * base unit; "[]" = there was no recipe). null = unreadable.
     *
     * @return list<array{ingredient_id: int, quantity: string, unit: string|null, order_types: int}>|null
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
                // LAUNCH packaging add-on — absent or invalid = all four types.
                'order_types' => OrderTypes::normalize($entry['order_types'] ?? null),
            ];
        }

        return $lines;
    }
}
