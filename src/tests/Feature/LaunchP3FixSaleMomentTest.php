<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Device\Sync\SyncEventDispatcher;
use App\Models\Device;
use App\Support\Recipes\RecipeInForce;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP3SyncEvents;
use Tests\TestCase;

/**
 * LAUNCH-P3 fix order 1 — the sale moment (Part B: M2, K6, L1, L2).
 *
 * Server time is frozen at 2026-10-02 12:00 UTC. The till (identity-tagged,
 * activated 10 days ago) sells:
 *   - Latte (made-to-order), created with its recipe 30 days ago ("[]" first
 *     version at creation): milk 0.250 l until an edit 2 h ago, 0.300 l since;
 *   - Karak (made-to-order), created with its recipe 60 s ago: milk 0.200 l.
 *     The portal wrote its "[]" version 1 s after the product row (one save
 *     that crossed a second).
 */
class LaunchP3FixSaleMomentTest extends TestCase
{
    use LaunchP3SyncEvents;
    use RefreshDatabase;

    private const LATTE = 1;

    private const KARAK = 2;

    private const MILK = 1;

    private Device $device;

    private Carbon $editedAt;

    private Carbon $karakCreatedAt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-02 12:00:00', 'UTC'));
        $this->seedPosStaff([7]);

        $latteCreatedAt = now()->subDays(30)->startOfSecond();
        $this->karakCreatedAt = now()->subSeconds(60)->startOfSecond();
        DB::table('pos_products')->insert([
            ['id' => self::LATTE, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Latte', 'base_price' => 1.500,
                'status' => 'active', 'stock_mode' => 'ingredient', 'created_at' => $latteCreatedAt, 'updated_at' => $latteCreatedAt],
            ['id' => self::KARAK, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Karak', 'base_price' => 0.500,
                'status' => 'active', 'stock_mode' => 'ingredient', 'created_at' => $this->karakCreatedAt, 'updated_at' => $this->karakCreatedAt],
        ]);
        DB::table('pos_ingredients')->insert([
            'id' => self::MILK, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Milk',
            'unit' => 'l', 'default_unit_cost' => '0.400000', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_branch_stock')->insert(['branch_id' => 10, 'ingredient_id' => self::MILK, 'quantity' => '10', 'created_at' => now(), 'updated_at' => now()]);

        $this->editedAt = now()->subHours(2)->startOfSecond();
        $this->recipe(self::LATTE, '0.3000');
        $this->version(self::LATTE, $latteCreatedAt, '[]');
        $this->version(self::LATTE, $this->editedAt, [['ingredient_id' => self::MILK, 'quantity' => '0.2500', 'unit' => 'l']]);
        $this->recipe(self::KARAK, '0.2000');
        $this->version(self::KARAK, $this->karakCreatedAt->copy()->addSecond(), '[]');

        $this->device = Device::factory()->paired('mdev_fx1')->create(['company_id' => 100, 'branch_id' => 10]);
        $this->device->forceFill([
            'assignment_activated_at' => now()->subDays(10)->startOfSecond(),
            'token_issued_at' => now()->subDays(10)->startOfSecond(),
        ])->save();
    }

    private function recipe(int $productId, string $milk): void
    {
        DB::table('pos_product_recipes')->where('product_id', $productId)->delete();
        DB::table('pos_product_recipes')->insert([
            'product_id' => $productId, 'ingredient_id' => self::MILK, 'quantity' => $milk, 'unit_at_set' => 'l',
            'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>|string  $before  the recipe BEFORE the edit
     */
    private function version(int $productId, Carbon $editedAt, array|string $before): void
    {
        DB::table('pos_product_recipe_versions')->insert([
            'product_id' => $productId,
            'recipe_json' => is_string($before) ? $before : json_encode($before),
            'edited_by_user_id' => 1, 'note' => null,
            'edited_at' => $editedAt->copy()->utc()->toDateTimeString(),
        ]);
    }

    /** @return array<string, mixed> */
    private function identity(): array
    {
        return ['identity' => ['company_id' => 100, 'branch_id' => 10, 'device_uuid' => $this->device->uuid]];
    }

    private function milkMoved(): float
    {
        return round((float) DB::table('pos_stock_movements')->where('ingredient_id', self::MILK)->sum('quantity'), 4);
    }

    private function sell(int $productId, Carbon|string $at, int $qty = 1): string
    {
        $uuid = (string) Str::uuid();
        $this->pushProcessed('mdev_fx1', [
            $this->orderEvent('order.create', $uuid, $at, [$this->line($productId, $qty, 500)], null, $this->identity()),
            $this->payEvent($uuid, $at, 500 * $qty, $this->identity()),
        ]);

        return $uuid;
    }

    // ---- M2: the sale moment has a floor ----------------------------------

    public function test_m2_a_till_two_minutes_behind_a_new_products_first_recipe_save_copies_its_recipe(): void
    {
        $uuid = $this->sell(self::KARAK, $this->karakCreatedAt->copy()->addSecond()->subMinutes(2));

        $this->assertSame([0.2], $this->copiedQty($uuid, self::MILK));
        $this->assertSame(-0.2, $this->milkMoved());
    }

    public function test_m2_a_till_clock_reset_to_2000_copies_the_current_recipe_of_a_new_product(): void
    {
        $uuid = $this->sell(self::KARAK, '2000-01-01T00:05:00Z');

        $this->assertSame([0.2], $this->copiedQty($uuid, self::MILK));
        $this->assertSame(-0.2, $this->milkMoved());
    }

    public function test_m2_a_till_clock_reset_to_2000_copies_the_recipe_in_force_at_the_devices_activation(): void
    {
        // Floored at the activation 10 days ago: the Latte then used 0.250 l
        // (not "[]" from its creation 30 days ago, which copied nothing).
        $uuid = $this->sell(self::LATTE, '2000-01-01T00:05:00Z');

        $this->assertSame([0.25], $this->copiedQty($uuid, self::MILK));
        $this->assertSame(-0.25, $this->milkMoved());
    }

    public function test_m2_the_floors_never_move_a_later_moment(): void
    {
        // A real offline sale before the edit keeps the recipe it was sold with.
        $uuid = $this->sell(self::LATTE, $this->editedAt->copy()->subMinutes(30));

        $this->assertSame([0.25], $this->copiedQty($uuid, self::MILK));

        $now = Carbon::parse('2026-10-02 12:00:00', 'UTC');
        $floor = Carbon::parse('2026-09-22 12:00:00', 'UTC');
        $this->assertSame('2026-09-22 12:00:00', RecipeInForce::saleMoment(Carbon::parse('2000-01-01 00:00:00', 'UTC'), $now, $floor)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 09:00:00', RecipeInForce::saleMoment(Carbon::parse('2026-10-01 09:00:00', 'UTC'), $now, $floor)->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-02 12:00:00', RecipeInForce::saleMoment(Carbon::parse('2027-01-01 00:00:00', 'UTC'), $now, $floor)->format('Y-m-d H:i:s'));
    }

    public function test_m2_a_push_stamped_more_than_24_hours_before_it_was_received_is_flagged(): void
    {
        $late = (string) Str::uuid();
        $recent = (string) Str::uuid();
        $response = $this->pushProcessed('mdev_fx1', [
            $this->orderEvent('order.create', $late, now()->subHours(25), [$this->line(self::LATTE)], null, $this->identity()),
            $this->orderEvent('order.create', $recent, now()->subHours(23), [$this->line(self::LATTE)], null, $this->identity()),
        ]);

        $this->assertSame([SyncEventDispatcher::CLIENT_TIME_BEHIND_FLAG], $response->json('data.results.0.result.integrity_flags'));
        $this->assertNull($response->json('data.results.1.result.integrity_flags'));
    }

    // ---- K6: an offset client_timestamp is converted to UTC at ingest ----

    public function test_k6_an_offset_client_timestamp_is_stored_and_compared_in_utc(): void
    {
        // 30 minutes before the edit, written in Muscat time (+04:00).
        $soldAt = $this->editedAt->copy()->subMinutes(30);
        $muscat = $soldAt->copy()->setTimezone('Asia/Muscat')->toIso8601String();
        $this->assertStringEndsWith('+04:00', $muscat);

        $uuid = (string) Str::uuid();
        $this->pushProcessed('mdev_fx1', [$this->orderEvent('order.create', $uuid, $muscat, [$this->line(self::LATTE)], null, $this->identity())]);

        $this->assertSame($soldAt->format('Y-m-d H:i:s'), Carbon::parse(DB::table('pos_sync_events')->value('client_timestamp'))->format('Y-m-d H:i:s'));
        $this->assertSame([0.25], $this->copiedQty($uuid, self::MILK));
    }

    public function test_k6_a_quarantined_event_keeps_its_offset_client_timestamp_in_utc(): void
    {
        $event = $this->orderEvent('order.create', (string) Str::uuid(), '2026-10-02T15:00:00+04:00', [$this->line(self::LATTE)], null,
            ['identity' => ['company_id' => 100, 'branch_id' => 99, 'device_uuid' => $this->device->uuid]]);
        $this->withToken('mdev_fx1')->postJson('/api/v1/device/sync/push', ['events' => [$event]])->assertOk()
            ->assertJsonPath('data.results.0.status', 'needs_review');

        $this->assertSame('2026-10-02 11:00:00', Carbon::parse(DB::table('pos_sync_events')->value('client_timestamp'))->format('Y-m-d H:i:s'));
    }

    // ---- L1: re-sends never copy earlier than the last accepted write -----

    public function test_l1_a_handheld_re_hold_stamped_with_the_open_time_copies_a_new_line_after_the_edit(): void
    {
        // The handheld stamps every order.hold with the order's open time.
        $uuid = (string) Str::uuid();
        $openedAt = now()->subHours(3)->startOfSecond();

        $this->pushProcessed('mdev_fx1', [$this->orderEvent('order.hold', $uuid, $openedAt, [$this->line(self::LATTE)], $openedAt, $this->identity())]);
        $this->assertSame([0.25], $this->copiedQty($uuid, self::MILK));

        // After the edit the waiter adds a line and holds again, still stamped with the open time.
        $this->travel(5)->minutes();
        $this->pushProcessed('mdev_fx1', [$this->orderEvent('order.hold', $uuid, $openedAt, [$this->line(self::LATTE), $this->line(self::LATTE, 2)], $openedAt, $this->identity())]);
        $this->assertSame([0.25, 0.3], $this->copiedQty($uuid, self::MILK));

        $this->pushProcessed('mdev_fx1', [
            $this->orderEvent('order.create', $uuid, now(), [$this->line(self::LATTE), $this->line(self::LATTE, 2)], $openedAt, $this->identity()),
            $this->payEvent($uuid, now(), 4500, $this->identity()),
        ]);
        $this->assertSame([0.25, 0.3], $this->copiedQty($uuid, self::MILK));
        $this->assertSame(-0.85, $this->milkMoved());
    }

    public function test_l1_an_offline_till_re_hold_keeps_its_own_client_time(): void
    {
        // Both holds were made offline before the edit and synced only now.
        $uuid = (string) Str::uuid();
        $this->pushProcessed('mdev_fx1', [
            $this->orderEvent('order.hold', $uuid, $this->editedAt->copy()->subMinutes(60), [$this->line(self::LATTE)], null, $this->identity()),
            $this->orderEvent('order.hold', $uuid, $this->editedAt->copy()->subMinutes(30), [$this->line(self::LATTE), $this->line(self::LATTE, 2)], $this->editedAt->copy()->subMinutes(60), $this->identity()),
        ]);

        $this->assertSame([0.25, 0.25], $this->copiedQty($uuid, self::MILK));
    }

    // ---- L2: kept copies follow the product's type; newest copy wins -------

    public function test_l2_a_held_line_switched_to_cooked_moves_the_shelf_not_the_recipe(): void
    {
        $uuid = (string) Str::uuid();
        $this->pushProcessed('mdev_fx1', [$this->orderEvent('order.hold', $uuid, now()->subMinutes(10), [$this->line(self::LATTE)], null, $this->identity())]);
        $this->assertSame([0.3], $this->copiedQty($uuid, self::MILK));

        DB::table('pos_products')->where('id', self::LATTE)->update(['stock_mode' => 'cooked']);
        DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => self::LATTE, 'stock_qty' => 10, 'created_at' => now(), 'updated_at' => now()]);
        $this->pushProcessed('mdev_fx1', [
            $this->orderEvent('order.create', $uuid, now(), [$this->line(self::LATTE)], null, $this->identity()),
            $this->payEvent($uuid, now(), 1500, $this->identity()),
        ]);

        $this->assertSame([null], $this->copiedQty($uuid, self::MILK));
        $this->assertSame(0.0, $this->milkMoved());
        $this->assertSame(9.0, (float) DB::table('pos_branch_product')->where('product_id', self::LATTE)->value('stock_qty'));
    }

    public function test_l2_a_held_line_switched_to_untracked_deducts_nothing(): void
    {
        $uuid = (string) Str::uuid();
        $this->pushProcessed('mdev_fx1', [$this->orderEvent('order.hold', $uuid, now()->subMinutes(10), [$this->line(self::LATTE)], null, $this->identity())]);

        DB::table('pos_products')->where('id', self::LATTE)->update(['stock_mode' => 'untracked']);
        $this->pushProcessed('mdev_fx1', [
            $this->orderEvent('order.create', $uuid, now(), [$this->line(self::LATTE)], null, $this->identity()),
            $this->payEvent($uuid, now(), 1500, $this->identity()),
        ]);

        $this->assertSame([null], $this->copiedQty($uuid, self::MILK));
        $this->assertSame(0.0, $this->milkMoved());
    }

    public function test_l2_removing_one_of_two_identical_lines_keeps_the_newest_copy(): void
    {
        $uuid = (string) Str::uuid();
        $this->pushProcessed('mdev_fx1', [$this->orderEvent('order.hold', $uuid, $this->editedAt->copy()->subMinutes(30), [$this->line(self::LATTE)], null, $this->identity())]);
        $this->pushProcessed('mdev_fx1', [$this->orderEvent('order.hold', $uuid, now()->subMinute(), [$this->line(self::LATTE), $this->line(self::LATTE)], null, $this->identity())]);
        $this->assertSame([0.25, 0.3], $this->copiedQty($uuid, self::MILK));

        $this->pushProcessed('mdev_fx1', [
            $this->orderEvent('order.create', $uuid, now(), [$this->line(self::LATTE)], null, $this->identity()),
            $this->payEvent($uuid, now(), 1500, $this->identity()),
        ]);

        $this->assertSame([0.3], $this->copiedQty($uuid, self::MILK));
        $this->assertSame(-0.3, $this->milkMoved());
    }

    public function test_l2_a_kept_product_add_on_switched_to_cooked_moves_its_shelf_not_its_recipe(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_products')->insert(['id' => 3, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Cookie',
            'base_price' => 0.500, 'status' => 'active', 'stock_mode' => 'ingredient'] + $t);
        $this->recipe(3, '0.0500');
        DB::table('pos_addon_groups')->insert(['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Extras',
            'selection_mode' => 'multiple', 'is_global' => true, 'display_order' => 0, 'status' => 'active'] + $t);
        DB::table('pos_addons')->insert(['id' => 11, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'add_on_group_id' => 1,
            'name' => 'Cookie on the side', 'price_delta' => 0, 'linked_product_id' => 3, 'status' => 'active'] + $t);

        $uuid = (string) Str::uuid();
        $line = $this->line(self::LATTE, 1, 1500, [11]);
        $this->pushProcessed('mdev_fx1', [$this->orderEvent('order.hold', $uuid, now()->subMinutes(10), [$line], null, $this->identity())]);

        DB::table('pos_products')->where('id', 3)->update(['stock_mode' => 'cooked']);
        DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => 3, 'stock_qty' => 4] + $t);
        $this->pushProcessed('mdev_fx1', [
            $this->orderEvent('order.create', $uuid, now(), [$line], null, $this->identity()),
            $this->payEvent($uuid, now(), 1500, $this->identity()),
        ]);

        $snapshot = json_decode((string) DB::table('pos_order_item_addons')->value('product_snapshot_json'), true);
        $this->assertSame('cooked', $snapshot['stock_mode']);
        $this->assertNull($snapshot['recipe']);
        // Only the Latte's own 0.300 l: the cookie's 0.050 l recipe is not deducted.
        $this->assertSame(-0.3, $this->milkMoved());
        $this->assertSame(3.0, (float) DB::table('pos_branch_product')->where('product_id', 3)->value('stock_qty'));
    }
}
