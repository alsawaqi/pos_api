<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * P-G1.5 — batch expiry + day-end disposition of expired cooked pieces.
 *
 *   POST /device/productions/{uuid}/finish   carries the chef's expires_at
 *                                            (or derives it from the
 *                                            product's shelf_life_days)
 *   GET  /device/disposition                 FIFO virtual lots -> what is
 *                                            expired at the branch
 *   POST /device/disposition                 waste (free) / give-away
 *                                            (manager PIN + comment) /
 *                                            carry-over (PIN, audit row)
 *
 * Catalogue: company 100 / branch 10 — cooked Cake (recipe 0.5 kg Flour,
 * shelf life 1 day), Flour 50 kg on the shelf. Chef Sami (7, PIN 1111),
 * manager Mona (8, PIN 4321).
 */
class DeviceDispositionTest extends TestCase
{
    use RefreshDatabase;

    private const CAKE = 1;

    private const FLOUR = 1;

    private const CHEF = 7;

    private function device(string $token = 'mdev_disp', int $company = 100, int $branch = 10): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => $company, 'branch_id' => $branch]);
    }

    private function seedDisposition(?int $shelfLifeDays = 1): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];

        DB::table('pos_products')->insert([
            ['id' => self::CAKE, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Cake', 'name_ar' => null, 'base_price' => 5.000, 'status' => 'active', 'stock_mode' => 'cooked', 'shelf_life_days' => $shelfLifeDays] + $t,
        ]);
        DB::table('pos_ingredients')->insert([
            ['id' => self::FLOUR, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Flour', 'unit' => 'kg', 'default_unit_cost' => 0.300, 'status' => 'active'] + $t,
        ]);
        DB::table('pos_product_recipes')->insert([
            ['product_id' => self::CAKE, 'ingredient_id' => self::FLOUR, 'quantity' => 0.500, 'unit_at_set' => 'kg', 'sort_order' => 1] + $t,
        ]);
        DB::table('pos_branch_stock')->insert([
            ['branch_id' => 10, 'ingredient_id' => self::FLOUR, 'quantity' => 50.000] + $t,
        ]);
        DB::table('pos_staff')->insert([
            ['id' => self::CHEF, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'name' => 'Sami', 'pin_hash' => Hash::make('1111'), 'position' => 'kitchen', 'status' => 'active'] + $t,
            ['id' => 8, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'name' => 'Mona', 'pin_hash' => Hash::make('4321'), 'position' => 'manager', 'status' => 'active'] + $t,
        ]);
    }

    /** Start + finish a batch; returns the finish response. */
    private function produce(int $qty, array $finishPayload = []): TestResponse
    {
        $uuid = $this->withToken('mdev_disp')->postJson('/api/v1/device/productions', [
            'product_id' => self::CAKE,
            'quantity' => $qty,
            'staff_id' => self::CHEF,
        ])->assertCreated()->json('data.production.uuid');

        return $this->withToken('mdev_disp')
            ->postJson("/api/v1/device/productions/{$uuid}/finish", $finishPayload + ['staff_id' => self::CHEF])
            ->assertOk();
    }

    private function shelfQty(): float
    {
        return (float) DB::table('pos_branch_product')
            ->where('branch_id', 10)->where('product_id', self::CAKE)
            ->value('stock_qty');
    }

    // ------------------------------------------------ batch expiry

    public function test_finish_stores_the_chefs_explicit_expiry(): void
    {
        $this->seedDisposition();
        $this->device();

        $expiry = now()->addDays(2)->startOfHour();
        $res = $this->produce(4, ['expires_at' => $expiry->toIso8601String()]);

        $this->assertNotNull($res->json('data.production.expires_at'));
        $stored = DB::table('pos_productions')->value('expires_at');
        $this->assertSame($expiry->format('Y-m-d H'), substr((string) $stored, 0, 13));
    }

    public function test_finish_defaults_the_expiry_from_the_product_shelf_life(): void
    {
        $this->seedDisposition(shelfLifeDays: 1);
        $this->device();

        $this->produce(4); // no expires_at in the payload

        $stored = DB::table('pos_productions')->value('expires_at');
        $this->assertNotNull($stored);
        // finished now + 1 day, end of day.
        $this->assertSame(now()->addDay()->toDateString(), substr((string) $stored, 0, 10));
    }

    public function test_finish_accepts_an_explicit_never_expires(): void
    {
        $this->seedDisposition(shelfLifeDays: 1);
        $this->device();

        // Explicit null beats the product default ("this batch keeps").
        $this->produce(4, ['expires_at' => null]);

        $this->assertNull(DB::table('pos_productions')->value('expires_at'));
    }

    public function test_finish_without_shelf_life_defaults_to_no_expiry(): void
    {
        $this->seedDisposition(shelfLifeDays: null);
        $this->device();

        $this->produce(4);

        $this->assertNull(DB::table('pos_productions')->value('expires_at'));
    }

    // ------------------------------------------------ GET /device/disposition

    public function test_disposition_lists_expired_pieces_fifo(): void
    {
        $this->seedDisposition();
        $this->device();

        // Batch A: 4 pieces, already expired. Batch B: 6 pieces, next week.
        $this->produce(4, ['expires_at' => now()->subHours(2)->toIso8601String()]);
        $this->produce(6, ['expires_at' => now()->addWeek()->toIso8601String()]);

        // 3 pieces sold since — FIFO eats batch A first: 1 expired left.
        DB::table('pos_product_stock_movements')->insert([
            'company_id' => 100, 'product_id' => self::CAKE, 'branch_id' => 10,
            'movement_type' => 'sale_consumption', 'quantity' => -3.000,
            'occurred_at' => now(), 'created_at' => now(),
        ]);
        DB::table('pos_branch_product')->where('branch_id', 10)->where('product_id', self::CAKE)
            ->update(['stock_qty' => 7.000]);

        $res = $this->withToken('mdev_disp')->getJson('/api/v1/device/disposition')->assertOk();

        $items = $res->json('data.items');
        $this->assertCount(1, $items);
        $this->assertSame('Cake', $items[0]['name']);
        $this->assertEqualsWithDelta(7.0, $items[0]['branch_stock_qty'], 0.001);
        $this->assertEqualsWithDelta(1.0, $items[0]['expired_qty'], 0.001);
    }

    public function test_disposition_is_empty_when_nothing_expired(): void
    {
        $this->seedDisposition();
        $this->device();
        $this->produce(4, ['expires_at' => now()->addWeek()->toIso8601String()]);

        $this->assertSame([], $this->withToken('mdev_disp')->getJson('/api/v1/device/disposition')->assertOk()->json('data.items'));
    }

    public function test_disposition_never_forces_undated_stock(): void
    {
        $this->seedDisposition();
        $this->device();

        // Balance seeded directly (merchant form), no ledger rows: the
        // stock can't be aged, so it never expires.
        DB::table('pos_branch_product')->insert([
            'branch_id' => 10, 'product_id' => self::CAKE,
            'is_available' => true, 'stock_qty' => 9.000,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame([], $this->withToken('mdev_disp')->getJson('/api/v1/device/disposition')->assertOk()->json('data.items'));
    }

    // ------------------------------------------------ POST /device/disposition

    public function test_waste_needs_no_approval_and_moves_the_shelf(): void
    {
        $this->seedDisposition();
        $this->device();
        $this->produce(4, ['expires_at' => now()->subHour()->toIso8601String()]);

        $this->withToken('mdev_disp')->postJson('/api/v1/device/disposition', [
            'staff_id' => self::CHEF,
            'items' => [['product_id' => self::CAKE, 'waste_qty' => 4]],
        ])->assertOk();

        $this->assertEqualsWithDelta(0.0, $this->shelfQty(), 0.001);
        $row = DB::table('pos_product_stock_movements')->where('movement_type', 'waste')->first();
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(-4.0, (float) $row->quantity, 0.001);
        $this->assertSame(self::CHEF, (int) $row->recorded_by_pos_staff_id);
    }

    public function test_give_away_requires_a_manager_pin_and_a_comment(): void
    {
        $this->seedDisposition();
        $this->device();
        $this->produce(4, ['expires_at' => now()->subHour()->toIso8601String()]);

        // No PIN -> 401.
        $this->withToken('mdev_disp')->postJson('/api/v1/device/disposition', [
            'items' => [['product_id' => self::CAKE, 'give_away_qty' => 2, 'give_away_comment' => 'staff meal - Sami']],
        ])->assertStatus(401)->assertJsonPath('errors.0.code', 'invalid_pin');

        // PIN but no comment -> 422.
        $this->withToken('mdev_disp')->postJson('/api/v1/device/disposition', [
            'pin' => '4321',
            'items' => [['product_id' => self::CAKE, 'give_away_qty' => 2]],
        ])->assertStatus(422);

        // Nothing moved yet.
        $this->assertEqualsWithDelta(4.0, $this->shelfQty(), 0.001);

        // PIN + comment -> moves, with the approver recorded in the note.
        $this->withToken('mdev_disp')->postJson('/api/v1/device/disposition', [
            'pin' => '4321',
            'staff_id' => self::CHEF,
            'items' => [['product_id' => self::CAKE, 'give_away_qty' => 2, 'give_away_comment' => 'staff meal - Sami']],
        ])->assertOk();

        $this->assertEqualsWithDelta(2.0, $this->shelfQty(), 0.001);
        $row = DB::table('pos_product_stock_movements')->where('movement_type', 'give_away')->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('approved by Mona', (string) $row->note);
        $this->assertStringContainsString('staff meal - Sami', (string) $row->note);
    }

    public function test_carry_over_writes_an_audit_row_without_moving_stock(): void
    {
        $this->seedDisposition();
        $this->device();
        $this->produce(4, ['expires_at' => now()->subHour()->toIso8601String()]);

        $this->withToken('mdev_disp')->postJson('/api/v1/device/disposition', [
            'pin' => '4321',
            'staff_id' => self::CHEF,
            'items' => [['product_id' => self::CAKE, 'carry_over_qty' => 4]],
        ])->assertOk();

        $this->assertEqualsWithDelta(4.0, $this->shelfQty(), 0.001);
        $row = DB::table('pos_product_stock_movements')->where('movement_type', 'carry_over')->first();
        $this->assertNotNull($row);
        $this->assertEqualsWithDelta(0.0, (float) $row->quantity, 0.001);
        $this->assertStringContainsString('approved by Mona', (string) $row->note);
        $this->assertStringContainsString('carried over 4', (string) $row->note);
    }

    public function test_disposition_never_overdraws_the_shelf(): void
    {
        $this->seedDisposition();
        $this->device();
        $this->produce(4, ['expires_at' => now()->subHour()->toIso8601String()]);

        $this->withToken('mdev_disp')->postJson('/api/v1/device/disposition', [
            'staff_id' => self::CHEF,
            'items' => [['product_id' => self::CAKE, 'waste_qty' => 9]],
        ])->assertStatus(422)->assertJsonPath('errors.0.code', 'disposition_rejected');

        // Nothing changed.
        $this->assertEqualsWithDelta(4.0, $this->shelfQty(), 0.001);
        $this->assertSame(0, DB::table('pos_product_stock_movements')->where('movement_type', 'waste')->count());
    }

    public function test_disposition_refuses_a_foreign_product(): void
    {
        $this->seedDisposition();
        $this->device();
        DB::table('pos_products')->insert([
            'id' => 99, 'uuid' => (string) Str::uuid(), 'company_id' => 200, 'name' => 'Foreign',
            'base_price' => 1, 'status' => 'active', 'stock_mode' => 'cooked',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->withToken('mdev_disp')->postJson('/api/v1/device/disposition', [
            'items' => [['product_id' => 99, 'waste_qty' => 1]],
        ])->assertStatus(422);
    }

    // ------------------------------------------------ config emission

    public function test_config_emits_the_product_shelf_life(): void
    {
        $this->seedDisposition(shelfLifeDays: 2);
        $this->device();

        $data = $this->withToken('mdev_disp')->getJson('/api/v1/device/config')->assertOk()->json('data');
        $cake = collect($data['products'])->firstWhere('id', self::CAKE);
        $this->assertSame(2, $cake['shelf_life_days']);

        // And the kitchen screen carries it too (prefills the Finish dialog).
        $kitchen = $this->withToken('mdev_disp')->getJson('/api/v1/device/kitchen')->assertOk();
        $this->assertSame(2, $kitchen->json('data.products.0.shelf_life_days'));
    }
}
