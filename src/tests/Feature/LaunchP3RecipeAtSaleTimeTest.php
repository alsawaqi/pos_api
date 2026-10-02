<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\OrderItem;
use App\Support\Recipes\RecipeInForce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * LAUNCH-P3 P3-6 — the recipe of the moment of sale.
 *
 * Rule: a device order copies, for each line, the recipe in force at the
 * order event's client timestamp clamped to now. pos_product_recipe_versions
 * holds the recipe BEFORE each edit, dated at the edit, so the recipe in
 * force at T is the recipe_json of the first version edited after T, else
 * the current recipe. A re-sent open order (re-hold, finalize, transfer)
 * keeps the copy each unchanged line already had (same product, qty and
 * add-on set); a new or changed line copies at the re-send's moment.
 *
 * Latte (made-to-order): milk 0.250 l until an edit at 10:00, 0.300 l since.
 */
class LaunchP3RecipeAtSaleTimeTest extends TestCase
{
    use RefreshDatabase;

    private const LATTE = 1;

    private const MILK = 1;

    private Carbon $editedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPosStaff([7]);
        $t = ['created_at' => now(), 'updated_at' => now()];

        DB::table('pos_products')->insert([
            'id' => self::LATTE, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Latte',
            'base_price' => 1.500, 'status' => 'active', 'stock_mode' => 'ingredient',
        ] + $t);
        DB::table('pos_ingredients')->insert([
            'id' => self::MILK, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Milk',
            'unit' => 'l', 'default_unit_cost' => '0.400000', 'status' => 'active',
        ] + $t);
        DB::table('pos_branch_stock')->insert(['branch_id' => 10, 'ingredient_id' => self::MILK, 'quantity' => '10'] + $t);
        Device::factory()->paired('mdev_p36')->create(['company_id' => 100, 'branch_id' => 10]);

        // Today's recipe (0.300 l), set by an edit 2 hours ago that replaced 0.250 l.
        $this->editedAt = now()->subHours(2)->startOfSecond();
        $this->setRecipe('0.3000');
        $this->version($this->editedAt, [['ingredient_id' => self::MILK, 'ingredient_name' => 'Milk', 'quantity' => '0.2500', 'unit' => 'l', 'unit_cost_at_time' => '0.400000']]);
    }

    private function setRecipe(string $quantity): void
    {
        DB::table('pos_product_recipes')->where('product_id', self::LATTE)->delete();
        DB::table('pos_product_recipes')->insert([
            'product_id' => self::LATTE, 'ingredient_id' => self::MILK, 'quantity' => $quantity, 'unit_at_set' => 'l',
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * A version row exactly as pos_merchant writes it: the recipe BEFORE the edit.
     *
     * @param  list<array<string, mixed>>|string  $recipe
     */
    private function version(Carbon $editedAt, array|string $recipe): void
    {
        DB::table('pos_product_recipe_versions')->insert([
            'product_id' => self::LATTE,
            'recipe_json' => is_string($recipe) ? $recipe : json_encode($recipe),
            'edited_by_user_id' => 1,
            'note' => null,
            'edited_at' => $editedAt->toDateTimeString(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    private function orderEvent(string $type, string $uuid, Carbon $clientAt, array $lines): array
    {
        $total = array_sum(array_column($lines, 'line_total_baisas'));

        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => $type,
            'client_timestamp' => $clientAt->toIso8601String(),
            'payload' => ['order' => [
                'uuid' => $uuid, 'order_type' => 'dine_in', 'source' => 'main_pos', 'staff_id' => 7,
                'opened_at' => $clientAt->toIso8601String(),
                'subtotal_baisas' => $total, 'discount_total_baisas' => 0, 'tax_total_baisas' => 0, 'grand_total_baisas' => $total,
                'lines' => $lines,
            ]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function latte(int $qty = 1, ?string $note = null): array
    {
        return ['product_id' => self::LATTE, 'qty' => $qty, 'unit_price_baisas' => 1500, 'line_total_baisas' => 1500 * $qty]
            + ($note !== null ? ['notes' => $note] : []);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(array $events): TestResponse
    {
        $response = $this->withToken('mdev_p36')->postJson('/api/v1/device/sync/push', ['events' => $events])->assertOk();
        foreach ($response->json('data.results') as $result) {
            $this->assertSame('processed', $result['status'], (string) json_encode($result));
        }

        return $response;
    }

    /**
     * @return list<float|null> the milk per unit each line of the order copied, in line order
     */
    private function copiedMilk(string $uuid): array
    {
        return OrderItem::query()
            ->whereHas('order', static fn ($q) => $q->where('uuid', $uuid))
            ->orderBy('id')
            ->get()
            ->map(static fn (OrderItem $item): ?float => $item->recipe_snapshot_json === null ? null : (float) $item->recipe_snapshot_json[0]['qty'])
            ->all();
    }

    public function test_an_offline_sale_synced_after_a_recipe_edit_keeps_the_recipe_it_was_sold_with(): void
    {
        $uuid = (string) Str::uuid();
        $soldAt = $this->editedAt->copy()->subMinutes(30);
        $this->push([
            $this->orderEvent('order.create', $uuid, $soldAt, [$this->latte(2)]),
            [
                'client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => $soldAt->toIso8601String(),
                'payload' => ['order_uuid' => $uuid, 'paid_at' => $soldAt->toIso8601String(),
                    'payments' => [['method' => 'cash', 'amount_baisas' => 3000, 'change_given_baisas' => 0]]],
            ],
        ]);

        $this->assertSame([0.25], $this->copiedMilk($uuid));
        $this->assertSame(-0.5, (float) DB::table('pos_stock_movements')->where('ingredient_id', self::MILK)->sum('quantity'));
    }

    public function test_a_sale_after_the_edit_copies_the_current_recipe(): void
    {
        $uuid = (string) Str::uuid();
        $this->push([$this->orderEvent('order.create', $uuid, $this->editedAt->copy()->addMinute(), [$this->latte()])]);

        $this->assertSame([0.3], $this->copiedMilk($uuid));
    }

    public function test_the_first_edit_after_the_sale_holds_the_recipe_in_force(): void
    {
        // Earlier history: 0.200 until an edit 5 hours ago, then 0.250 until
        // the edit 2 hours ago, then 0.300.
        $this->version(now()->subHours(5)->startOfSecond(), [['ingredient_id' => self::MILK, 'quantity' => '0.2000', 'unit' => 'l']]);

        $before = (string) Str::uuid();
        $between = (string) Str::uuid();
        $this->push([
            $this->orderEvent('order.create', $before, now()->subHours(6), [$this->latte()]),
            $this->orderEvent('order.create', $between, now()->subHours(3), [$this->latte()]),
        ]);

        $this->assertSame([0.2], $this->copiedMilk($before));
        $this->assertSame([0.25], $this->copiedMilk($between));
    }

    public function test_a_recipe_added_after_the_sale_deducts_nothing_for_it(): void
    {
        // The product had no recipe until an edit 3 hours ago ("[]" before it).
        DB::table('pos_product_recipe_versions')->delete();
        $this->version(now()->subHours(3)->startOfSecond(), '[]');

        $uuid = (string) Str::uuid();
        $this->push([$this->orderEvent('order.create', $uuid, now()->subHours(4), [$this->latte()])]);

        $this->assertSame([null], $this->copiedMilk($uuid));
    }

    public function test_the_client_timestamp_is_clamped_to_now(): void
    {
        // A device clock running a day ahead cannot pick a recipe "from the
        // future": here the portal stamped an edit a minute ahead of this
        // server's clock; clamped to now, the sale still precedes that edit.
        $this->version(now()->addMinute()->startOfSecond(), [['ingredient_id' => self::MILK, 'quantity' => '0.2750', 'unit' => 'l']]);

        $uuid = (string) Str::uuid();
        $this->push([$this->orderEvent('order.create', $uuid, now()->addDay(), [$this->latte()])]);

        $this->assertSame([0.275], $this->copiedMilk($uuid));

        $now = Carbon::parse('2026-10-02 12:00:00', 'UTC');
        $this->assertSame('2026-10-02 12:00:00', RecipeInForce::saleMoment($now->copy()->addHour(), $now)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-02 11:00:00', RecipeInForce::saleMoment($now->copy()->subHour(), $now)->format('Y-m-d H:i:s'));
        // An offset client time is compared in UTC.
        $this->assertSame('2026-10-02 07:00:00', RecipeInForce::saleMoment(Carbon::parse('2026-10-02T11:00:00+04:00'), $now)->format('Y-m-d H:i:s'));
    }

    public function test_a_re_sent_open_order_keeps_the_copy_of_its_unchanged_lines(): void
    {
        $uuid = (string) Str::uuid();
        $heldAt = $this->editedAt->copy()->subMinutes(30);

        // Held before the edit with two lattes on two lines.
        $this->push([$this->orderEvent('order.hold', $uuid, $heldAt, [$this->latte(), $this->latte(2)])]);
        $this->assertSame([0.25, 0.25], $this->copiedMilk($uuid));

        // Re-held after the edit: the first line only gets a note (unchanged),
        // the second goes from 2 to 3 (changed), and a third line is new.
        $this->push([$this->orderEvent('order.hold', $uuid, now(), [$this->latte(1, 'extra hot'), $this->latte(3), $this->latte()])]);
        $this->assertSame([0.25, 0.3, 0.3], $this->copiedMilk($uuid));

        // The finalize keeps every copy the held lines now have.
        $this->push([$this->orderEvent('order.create', $uuid, now(), [$this->latte(1, 'extra hot'), $this->latte(3), $this->latte()])]);
        $this->assertSame([0.25, 0.3, 0.3], $this->copiedMilk($uuid));
    }

    public function test_an_unreadable_version_falls_back_to_the_current_recipe(): void
    {
        DB::table('pos_product_recipe_versions')->delete();
        $this->version(now()->subHour()->startOfSecond(), '{"not": "a recipe list"}');

        $uuid = (string) Str::uuid();
        $this->push([$this->orderEvent('order.create', $uuid, now()->subHours(2), [$this->latte()])]);

        $this->assertSame([0.3], $this->copiedMilk($uuid));
    }
}
