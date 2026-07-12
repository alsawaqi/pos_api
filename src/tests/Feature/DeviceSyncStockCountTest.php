<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase A (Additions §2.8) — the stock.count sync handler: a device
 * submits the day-end physical count; the server reconciles it
 * against the running balance exactly like pos_merchant's portal
 * flow (shortfall → reconciliation_variance waste, overage →
 * positive adjustment, exact → line only).
 */
class DeviceSyncStockCountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Phase 4 — the stock.count recorder (staff 7) must exist in the tenant.
        $this->seedPosStaff([7]);
    }

    private function device(string $token = 'mdev_x', int $company = 100, int $branch = 10): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => $company, 'branch_id' => $branch]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seedIngredient(int $id = 1, array $overrides = []): void
    {
        DB::table('pos_ingredients')->insert(array_merge([
            'id' => $id, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'Milk', 'unit' => 'l',
            'piece_unit_label' => 'bottle', 'units_per_piece' => 1.0,
            'default_unit_cost' => 1.500, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ], $overrides));
    }

    private function seedBalance(int $ingredientId, string $qty): void
    {
        DB::table('pos_branch_stock')->insert([
            'branch_id' => 10, 'ingredient_id' => $ingredientId, 'quantity' => $qty,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function countEvent(array $payload): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'stock.count',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => $payload,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(string $token, array $events): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }

    public function test_shortfall_writes_reconciliation_variance_waste_and_fixes_the_balance(): void
    {
        $this->device();
        $this->seedIngredient();
        $this->seedBalance(1, '7.000');

        // The doc's milk example: balance 7 L, staff count 6 bottles.
        $res = $this->push('mdev_x', [$this->countEvent([
            'lines' => [['ingredient_id' => 1, 'counted_pieces' => 6]],
            'staff_id' => 7,
        ])])->assertOk();
        $r = $res->json('data.results.0');

        $this->assertSame('processed', $r['status']);
        $this->assertSame(1, $r['result']['lines_with_variance']);

        $this->assertDatabaseHas('pos_stock_counts', [
            'company_id' => 100, 'branch_id' => 10, 'recorded_by_pos_staff_id' => 7,
        ]);
        $this->assertDatabaseHas('pos_stock_count_lines', [
            'ingredient_id' => 1,
            'counted_pieces' => '6.000',
            'counted_units' => '6.000',
            'expected_units' => '7.000',
            'variance_units' => '-1.000',
        ]);
        $this->assertDatabaseHas('pos_waste_records', [
            'branch_id' => 10, 'ingredient_id' => 1,
            'reason' => 'reconciliation_variance', 'quantity' => '1.000',
        ]);
        $this->assertDatabaseHas('pos_stock_movements', [
            'branch_id' => 10, 'ingredient_id' => 1,
            'movement_type' => 'waste', 'quantity' => '-1.000',
            'recorded_by_pos_staff_id' => 7,
        ]);
        $this->assertSame((float) '6.000', (float) DB::table('pos_branch_stock')
            ->where('branch_id', 10)->where('ingredient_id', 1)->value('quantity'));
    }

    public function test_overage_writes_a_positive_adjustment(): void
    {
        $this->device();
        $this->seedIngredient(1, ['name' => 'Flour', 'unit' => 'kg', 'piece_unit_label' => null, 'units_per_piece' => null]);
        $this->seedBalance(1, '5.000');

        $res = $this->push('mdev_x', [$this->countEvent([
            'lines' => [['ingredient_id' => 1, 'counted_units' => 6.5]],
        ])])->assertOk();

        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertDatabaseHas('pos_stock_movements', [
            'movement_type' => 'adjustment', 'quantity' => '1.500', 'ingredient_id' => 1,
        ]);
        $this->assertDatabaseCount('pos_waste_records', 0);
        $this->assertSame((float) '6.500', (float) DB::table('pos_branch_stock')
            ->where('branch_id', 10)->where('ingredient_id', 1)->value('quantity'));
    }

    public function test_exact_count_records_the_line_with_no_movement(): void
    {
        $this->device();
        $this->seedIngredient();
        $this->seedBalance(1, '4.000');

        $this->push('mdev_x', [$this->countEvent([
            'lines' => [['ingredient_id' => 1, 'counted_pieces' => 4]],
        ])])->assertOk();

        $this->assertDatabaseHas('pos_stock_count_lines', [
            'ingredient_id' => 1, 'variance_units' => '0.000', 'stock_movement_id' => null,
        ]);
        $this->assertDatabaseCount('pos_stock_movements', 0);
    }

    public function test_pieces_convert_through_units_per_piece(): void
    {
        $this->device();
        // Tomato crate-style ratio: 1428.5714 g per piece.
        $this->seedIngredient(1, [
            'name' => 'Tomato', 'unit' => 'g',
            'piece_unit_label' => 'piece', 'units_per_piece' => 1428.5714,
        ]);
        $this->seedBalance(1, '6342.860');

        $this->push('mdev_x', [$this->countEvent([
            'lines' => [['ingredient_id' => 1, 'counted_pieces' => 4]],
        ])])->assertOk();

        // 4 × 1428.5714 = 5714.286 → variance −628.574.
        $this->assertDatabaseHas('pos_stock_count_lines', [
            'ingredient_id' => 1,
            'counted_units' => '5714.286',
            'variance_units' => '-628.574',
        ]);
    }

    public function test_fractional_pieces_rejected_when_forbidden_and_event_is_atomic(): void
    {
        $this->device();
        $this->seedIngredient(1, [
            'name' => 'Eggs', 'unit' => 'piece',
            'piece_unit_label' => null, 'units_per_piece' => null,
            'allow_fractional_pieces' => false,
        ]);
        $this->seedIngredient(2, ['name' => 'Milk2']);
        $this->seedBalance(1, '30.000');
        $this->seedBalance(2, '5.000');

        $res = $this->push('mdev_x', [$this->countEvent([
            'lines' => [
                ['ingredient_id' => 2, 'counted_pieces' => 3],
                ['ingredient_id' => 1, 'counted_pieces' => 4.7],
            ],
        ])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('whole pieces', $res->json('data.results.0.result.error'));
        // NOTHING landed — the whole event is atomic.
        $this->assertDatabaseCount('pos_stock_counts', 0);
        $this->assertDatabaseCount('pos_stock_movements', 0);
        $this->assertSame((float) '5.000', (float) DB::table('pos_branch_stock')
            ->where('ingredient_id', 2)->value('quantity'));
    }

    public function test_cross_tenant_ingredient_fails_the_event(): void
    {
        $this->device();
        $this->seedIngredient(1, ['company_id' => 999]);

        $res = $this->push('mdev_x', [$this->countEvent([
            'lines' => [['ingredient_id' => 1, 'counted_units' => 5]],
        ])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('unknown ingredient', $res->json('data.results.0.result.error'));
    }

    public function test_duplicate_client_event_id_is_idempotent(): void
    {
        $this->device();
        $this->seedIngredient();
        $this->seedBalance(1, '7.000');

        $event = $this->countEvent([
            'lines' => [['ingredient_id' => 1, 'counted_pieces' => 6]],
        ]);

        $this->push('mdev_x', [$event])->assertOk();
        $this->push('mdev_x', [$event])->assertOk();

        // One count, one waste movement — the replay was a no-op.
        $this->assertDatabaseCount('pos_stock_counts', 1);
        $this->assertDatabaseCount('pos_stock_movements', 1);
        $this->assertSame((float) '6.000', (float) DB::table('pos_branch_stock')
            ->where('ingredient_id', 1)->value('quantity'));
    }

    public function test_config_emits_the_piece_fields(): void
    {
        $this->device();
        $this->seedIngredient(1, ['allow_fractional_pieces' => false]);

        $res = $this->withToken('mdev_x')->getJson('/api/v1/device/config')->assertOk();
        $ingredient = collect($res->json('data.ingredients'))->firstWhere('id', 1);

        $this->assertSame('bottle', $ingredient['piece_unit_label']);
        $this->assertSame(1.0, (float) $ingredient['units_per_piece']);
        $this->assertFalse($ingredient['allow_fractional_pieces']);
    }
}
