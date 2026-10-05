<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchReview;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * LAUNCH review add-on — device counts and the stock breakdown by container
 * (work order §4.3; tester calls 7 and 9; owner decision D4).
 *
 * Milk is stored in ml and counted on today's tills in "bottle" (1 l, its
 * count container). Its containers: bottle 1 l, bottle 500 ml and crate
 * (12 × bottle 1 l). The branch holds 5 l, shown as 5 × bottle 1 l.
 *
 *  - A future app counts by container (lines.*.containers): the breakdown
 *    becomes exactly what was counted, in leaf pieces (a crate = 12 bottles),
 *    and the count line keeps the containers.
 *  - Today's apps follow the legacy rule: (a) counted_pieces while the count
 *    container is the only leaf container sets the breakdown to it; (b) a
 *    total of 0 clears it; (c) anything else leaves it and stamps
 *    containers_total_count_at.
 *  - Every change is in the container ledger; a sale never writes it.
 */
final class StockCountContainersReviewTest extends TestCase
{
    use RefreshDatabase;

    private int $litre;

    private int $half;

    private int $crate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-06 09:00:00', 'UTC'));
        $this->seedPosStaff([7]);
        Device::factory()->paired('mdev_rv_count')->create(['company_id' => 100, 'branch_id' => 10]);
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_ingredients')->insert(['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Milk',
            'unit' => 'ml', 'piece_unit_label' => 'bottle', 'units_per_piece' => '1000', 'allow_fractional_pieces' => true,
            'default_unit_cost' => '0.001000', 'status' => 'active'] + $t);
        $this->litre = $this->container(1, 'bottle', '1000');
        DB::table('pos_ingredients')->where('id', 1)->update(['count_container_id' => $this->litre]);
        DB::table('pos_branch_stock')->insert(['branch_id' => 10, 'ingredient_id' => 1, 'quantity' => '5000'] + $t);
        DB::table('pos_stock_container_balances')->insert(['company_id' => 100, 'branch_id' => 10, 'ingredient_id' => 1,
            'container_id' => $this->litre, 'pieces' => '5'] + $t);
    }

    private function container(int $ingredientId, string $name, string $factor, array $extra = [], int $companyId = 100): int
    {
        return (int) DB::table('pos_ingredient_units')->insertGetId($extra + [
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'ingredient_id' => $ingredientId, 'name' => $name,
            'factor' => $factor, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function moreContainers(): void
    {
        $this->half = $this->container(1, 'bottle', '500');
        $this->crate = $this->container(1, 'crate', '12000', ['contains_unit_id' => $this->litre, 'contains_quantity' => '12']);
    }

    private function uuid(int $containerId): string
    {
        return (string) DB::table('pos_ingredient_units')->where('id', $containerId)->value('uuid');
    }

    /** @param array<string, mixed> $line */
    private function pushCount(array $line): TestResponse
    {
        return $this->withToken('mdev_rv_count')->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'stock.count', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['staff_id' => 7, 'counted_at' => now()->toIso8601String(), 'lines' => [['ingredient_id' => 1] + $line]],
        ]]])->assertOk();
    }

    /** @return array<int, float> container id => pieces (zeros left out) */
    private function breakdown(): array
    {
        return DB::table('pos_stock_container_balances')->where('branch_id', 10)->where('ingredient_id', 1)
            ->where('pieces', '>', 0)->orderBy('container_id')->get()
            ->mapWithKeys(static fn (object $row): array => [(int) $row->container_id => (float) $row->pieces])->all();
    }

    /** @return list<array{int, float, float, string}> [container, delta, after, reason] */
    private function ledger(): array
    {
        return DB::table('pos_stock_container_movements')->orderBy('id')->get()
            ->map(static fn (object $row): array => [(int) $row->container_id, (float) $row->delta_pieces, (float) $row->pieces_after, (string) $row->reason])
            ->all();
    }

    /** @return array{0: string|null, 1: string|null} [containers_counted_at, containers_total_count_at] */
    private function stamps(): array
    {
        $row = DB::table('pos_branch_stock')->where('branch_id', 10)->where('ingredient_id', 1)->first();

        return [$row->containers_counted_at === null ? null : Carbon::parse($row->containers_counted_at)->toIso8601String(),
            $row->containers_total_count_at === null ? null : Carbon::parse($row->containers_total_count_at)->toIso8601String()];
    }

    private function total(): float
    {
        return (float) DB::table('pos_branch_stock')->where('branch_id', 10)->where('ingredient_id', 1)->value('quantity');
    }

    public function test_a_legacy_count_in_pieces_sets_the_breakdown_when_the_count_container_is_the_only_leaf_container(): void
    {
        $this->assertSame('processed', $this->pushCount(['counted_pieces' => 3])->json('data.results.0.status'));

        $this->assertSame(3000.0, $this->total());
        $this->assertSame([$this->litre => 3.0], $this->breakdown());
        $this->assertSame([[$this->litre, -2.0, 3.0, 'device_count']], $this->ledger());
        $this->assertSame([now()->toIso8601String(), null], $this->stamps());
        $movement = DB::table('pos_stock_container_movements')->sole();
        $this->assertSame(['pos_stock_counts', (int) DB::table('pos_stock_counts')->value('id'), 7],
            [$movement->reference_type, (int) $movement->reference_id, (int) $movement->recorded_by_pos_staff_id]);
        $this->assertSame((int) DB::table('pos_stock_count_lines')->value('stock_movement_id'), (int) $movement->stock_movement_id);
    }

    public function test_a_legacy_count_of_zero_clears_the_breakdown(): void
    {
        $this->moreContainers();
        DB::table('pos_stock_container_balances')->insert(['company_id' => 100, 'branch_id' => 10, 'ingredient_id' => 1,
            'container_id' => $this->half, 'pieces' => '2', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame('processed', $this->pushCount(['counted_units' => 0])->json('data.results.0.status'));

        $this->assertSame(0.0, $this->total());
        $this->assertSame([], $this->breakdown());
        $this->assertSame([[$this->litre, -5.0, 0.0, 'device_count'], [$this->half, -2.0, 0.0, 'device_count']], $this->ledger());
        $this->assertSame([now()->toIso8601String(), null], $this->stamps());
    }

    public function test_any_other_legacy_count_leaves_the_breakdown_and_stamps_a_total_only_count(): void
    {
        // (c) a count in ml, and a count in pieces once the item has a second leaf container.
        $this->assertSame('processed', $this->pushCount(['counted_units' => 4200])->json('data.results.0.status'));
        $this->assertSame(4200.0, $this->total());
        $this->assertSame([$this->litre => 5.0], $this->breakdown());
        $this->assertSame([], $this->ledger());
        $this->assertSame([null, now()->toIso8601String()], $this->stamps());

        $this->moreContainers();
        $this->travel(1)->minutes();
        $this->assertSame('processed', $this->pushCount(['counted_pieces' => 4])->json('data.results.0.status'));
        $this->assertSame(4000.0, $this->total());
        $this->assertSame([$this->litre => 5.0], $this->breakdown());
        $this->assertSame([null, now()->toIso8601String()], $this->stamps());
    }

    public function test_a_count_by_container_sets_the_breakdown_in_leaf_pieces_and_keeps_the_containers_on_the_line(): void
    {
        $this->moreContainers();
        $response = $this->pushCount(['containers' => [
            ['container_uuid' => $this->uuid($this->crate), 'pieces' => 2],
            ['container_uuid' => $this->uuid($this->half), 'pieces' => 3],
        ]]);
        $this->assertSame('processed', $response->json('data.results.0.status'), (string) json_encode($response->json()));

        // 2 crates = 24 l, plus 3 × 500 ml: 25.5 l counted (the total fills in).
        $this->assertSame(25500.0, $this->total());
        $this->assertSame(25500.0, (float) DB::table('pos_stock_count_lines')->value('counted_units'));
        $this->assertSame([$this->litre => 24.0, $this->half => 3.0], $this->breakdown());
        $this->assertSame([[$this->litre, 19.0, 24.0, 'device_count'], [$this->half, 3.0, 3.0, 'device_count']], $this->ledger());
        $this->assertSame([now()->toIso8601String(), null], $this->stamps());
        $this->assertSame([['crate', 12000.0, 2.0], ['bottle', 500.0, 3.0]], DB::table('pos_stock_count_line_containers')->orderBy('id')->get()
            ->map(static fn (object $row): array => [$row->container_label, (float) $row->container_factor, (float) $row->pieces])->all());

        // A typed total wins over the containers' sum (a part-used bottle); the breakdown is still set.
        $this->travel(1)->minutes();
        $this->pushCount(['counted_units' => 1200, 'containers' => [['container_uuid' => $this->uuid($this->litre), 'pieces' => 2]]]);
        $this->assertSame(1200.0, $this->total());
        $this->assertSame([$this->litre => 2.0], $this->breakdown());
    }

    public function test_a_container_of_another_item_fails_the_whole_count(): void
    {
        DB::table('pos_ingredients')->insert(['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Cream',
            'unit' => 'ml', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $other = $this->container(2, 'carton', '1000');

        $response = $this->pushCount(['counted_units' => 3000, 'containers' => [['container_uuid' => $this->uuid($other), 'pieces' => 3]]]);

        $this->assertSame('failed', $response->json('data.results.0.status'));
        $this->assertStringContainsString('container of another item', $response->json('data.results.0.result.error'));
        $this->assertSame(5000.0, $this->total());
        $this->assertSame([], $this->ledger());
        $this->assertFalse(DB::table('pos_stock_counts')->exists());
    }

    public function test_a_sale_moves_the_total_and_never_the_breakdown(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_products')->insert(['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Latte',
            'base_price' => '1.500', 'stock_mode' => 'ingredient', 'status' => 'active'] + $t);
        DB::table('pos_product_recipes')->insert(['product_id' => 1, 'ingredient_id' => 1, 'quantity' => '200', 'unit_at_set' => 'ml', 'sort_order' => 1] + $t);
        $uuid = (string) Str::uuid();
        $at = now()->subMinute()->toIso8601String();
        $this->withToken('mdev_rv_count')->postJson('/api/v1/device/sync/push', ['events' => [
            ['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.create', 'client_timestamp' => $at, 'payload' => ['order' => [
                'uuid' => $uuid, 'order_type' => 'quick', 'source' => 'main_pos', 'staff_id' => 7, 'opened_at' => $at,
                'subtotal_baisas' => 1500, 'discount_total_baisas' => 0, 'tax_total_baisas' => 0, 'grand_total_baisas' => 1500,
                'lines' => [['product_id' => 1, 'qty' => 1, 'unit_price_baisas' => 1500, 'line_total_baisas' => 1500]],
            ]]],
            ['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => $at, 'payload' => [
                'order_uuid' => $uuid, 'paid_at' => $at, 'payments' => [['method' => 'cash', 'amount_baisas' => 1500, 'change_given_baisas' => 0]]]],
        ]])->assertOk()->assertJsonPath('data.results.1.status', 'processed');

        $this->assertSame(4800.0, $this->total());
        $this->assertSame([$this->litre => 5.0], $this->breakdown());
        $this->assertSame([], $this->ledger());
        $this->assertSame([null, null], $this->stamps());
    }
}
