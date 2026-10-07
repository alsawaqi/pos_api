<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP6;

use App\Models\Order;
use App\Models\TabletOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP6Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P6 Part A items 1, 2 and 10 — the customer tablet's own surface:
 * the token allowlist (tester call 1), bootstrap, the customer-safe menu
 * (tester call 2), server prices (tester call 3), the tap-list-only note
 * (tester call 5), the order number (tester call 6), double submit (tester
 * call 16) and a sold-out line refused by name.
 */
final class TabletSurfaceTest extends TestCase
{
    use LaunchP6Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p6Setup();
    }

    public function test_a_tablet_token_is_refused_everywhere_but_its_own_routes_heartbeat_and_identity(): void
    {
        $refused = [
            ['GET', '/api/v1/device/config'],
            ['GET', '/api/v1/device/config/delta'],
            ['GET', '/api/v1/device/customers/search?q=9123'],
            ['GET', '/api/v1/device/customers/1'],
            ['POST', '/api/v1/device/customers'],
            ['POST', '/api/v1/device/sync/push'],
            ['GET', '/api/v1/device/orders/active'],
            ['GET', '/api/v1/device/orders/history'],
            ['GET', '/api/v1/device/qr/pending-orders'],
            ['GET', '/api/v1/device/qr/accepted-rounds'],
            ['POST', '/api/v1/device/qr/claim-settlement'],
            ['GET', '/api/v1/device/tables/board'],
            ['GET', '/api/v1/device/tables/feed'],
            ['POST', '/api/v1/device/tables/open'],
            ['GET', '/api/v1/device/order-attention'],
            ['GET', '/api/v1/device/tablet-orders'],
            ['GET', '/api/v1/device/kitchen'],
            ['GET', '/api/v1/device/reports/branch'],
            ['GET', '/api/v1/device/sold-out'],
            ['GET', '/api/v1/device/payments/reversals'],
            ['POST', '/api/v1/broadcasting/auth'],
        ];
        foreach ($refused as [$method, $uri]) {
            $this->p6As($this->tablet, $method, $uri)->assertForbidden()
                ->assertJsonPath('errors.0.code', 'device_not_allowed_for_tablet');
        }

        $this->p6As($this->tablet, 'POST', '/api/v1/device/heartbeat', ['battery' => 80])->assertOk();
        $this->p6As($this->tablet, 'GET', '/api/v1/device/identity')->assertOk()->assertJsonPath('data.uuid', $this->tablet->uuid);
        $this->p6As($this->tablet, 'GET', '/api/v1/device/tablet/bootstrap')->assertOk();
        // A till keeps everything it had, and may not use the tablet's routes.
        $this->p6As($this->till, 'GET', '/api/v1/device/config')->assertOk();
        $this->p6As($this->till, 'GET', '/api/v1/device/tablet/menu?order_type=quick')->assertForbidden()
            ->assertJsonPath('errors.0.code', 'device_not_tablet');
    }

    public function test_bootstrap_carries_the_branch_tables_order_types_and_no_card_money_or_customer_data(): void
    {
        DB::table('pos_branches')->where('id', 10)->update(['name' => 'Seeb', 'name_ar' => 'السيب']);
        $five = $this->seatingTable('Table 5');
        $retired = $this->seatingTable('Old', attributes: ['status' => 'inactive']);
        $other = $this->seatingTable('Elsewhere', 20, 200);

        $data = $this->p6As($this->tablet, 'GET', '/api/v1/device/tablet/bootstrap')->assertOk()->json('data');

        $this->assertSame(['uuid' => DB::table('pos_branches')->where('id', 10)->value('uuid'), 'name' => 'Seeb', 'name_ar' => 'السيب'], $data['branch']);
        $this->assertSame([['uuid' => $five->uuid, 'name' => 'Table 5', 'area' => 'Seating floor', 'area_ar' => null]], $data['tables']);
        $this->assertSame(['dine_in', 'quick', 'to_go'], $data['order_types']);
        $this->assertFalse($data['card_enabled']);
        $this->assertSame(['code' => 'OMR', 'decimals' => 3], $data['currency']);
        $this->assertArrayHasKey('prices_include_tax', $data['tax']);
        $this->assertStringNotContainsString($retired->uuid, json_encode($data));
        $this->assertStringNotContainsString($other->uuid, json_encode($data));
    }

    public function test_the_menu_shows_only_ticked_products_with_meals_add_ons_by_order_type_and_cooking_time(): void
    {
        $hidden = $this->p4Product('Staff meal', '0.500', ['show_on_customer_tablet' => false]);
        $fries = $this->p4Product('Fries', '0.800', ['cooking_minutes' => 4]);
        // LAUNCH combo add-on — "Make it a meal?" is a meal on the cake's category (+0.700, fries included).
        $cakes = $this->p4Category('Cakes');
        DB::table('pos_products')->where('id', $this->cake)->update(['category_id' => $cakes]);
        $mealId = $this->p4Meal('meal', '0.700', [$cakes]);
        $this->p4FixedLine(['meal_id' => $mealId], $fries);
        $noSugar = $this->p4Addons($this->coffee, ['No sugar' => '0.000'], ['name' => 'Remove', 'kind' => 'remove', 'min_selections' => 0])['No sugar'];
        $large = $this->p4Addons($this->coffee, ['Large' => '0.200'], ['name' => 'Size', 'min_selections' => 1, 'max_selections' => 1])['Large'];
        // Large takes a to-go cup (Quick / To go only) that is sold out.
        $cup = $this->p4Product('Large cup', '0.000', ['stock_mode' => 'unit', 'is_internal' => true]);
        DB::table('pos_addon_consumptions')->insert(['add_on_id' => $large, 'ingredient_id' => null, 'component_product_id' => $cup,
            'direction' => 'add', 'quantity' => '1', 'unit' => null, 'order_types' => 2 | 4, 'display_order' => 0,
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_product_sold_out')->insert(['company_id' => 100, 'branch_id' => 10, 'product_id' => $cup,
            'set_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $menu = fn (string $type): array => $this->p6As($this->tablet, 'GET', '/api/v1/device/tablet/menu?order_type='.$type)
            ->assertOk()->json('data');
        $dineIn = $menu('dine_in');
        $products = collect($dineIn['products'])->keyBy('id');

        $this->assertSame('dine_in', $dineIn['order_type']);
        $this->assertFalse($products->has($hidden));
        $this->assertSame(5, $products[$this->coffee]['cooking_minutes']);
        $this->assertSame(12, $products[$this->cake]['cooking_minutes']);
        $this->assertSame($mealId, $products[$this->cake]['meal_id']);
        $this->assertSame([[$mealId, 700, true]], array_map(static fn (array $m): array => [$m['id'], $m['meal_price_baisas'], $m['available']], $dineIn['meals']));
        $groups = collect($dineIn['addon_groups'])->keyBy('name');
        $this->assertSame('remove', $groups['Remove']['kind']);
        $this->assertSame([1, 1], [$groups['Size']['min_selections'], $groups['Size']['max_selections']]);
        $addon = fn (array $m, int $id): array => collect($m['addon_groups'])->flatMap(fn ($g) => $g['addons'])->firstWhere('id', $id);
        $this->assertTrue($addon($dineIn, $large)['available']);
        $this->assertTrue($addon($dineIn, $noSugar)['available']);
        $this->assertFalse($addon($menu('to_go'), $large)['available']);
        $this->assertFalse($addon($menu('quick'), $large)['available']);

        $this->p6As($this->tablet, 'GET', '/api/v1/device/tablet/menu?order_type=delivery')->assertStatus(422);
        $this->p6As($this->tablet, 'GET', '/api/v1/device/tablet/menu')->assertStatus(422);
    }

    public function test_prices_are_the_servers_and_a_client_price_or_free_text_note_is_refused(): void
    {
        $quote = $this->p6As($this->tablet, 'POST', '/api/v1/device/tablet/quote', [
            'order_type' => 'quick', 'lines' => [$this->p6Line($this->coffee, 2), $this->p6Line($this->cake)],
        ])->assertOk()->json('data.quote');
        $this->assertSame(4000, $quote['grand_total_baisas']);
        $this->assertSame([2000, 2000], array_column($quote['lines'], 'line_total_baisas'));

        $priced = $this->p6Line($this->coffee) + ['unit_price_baisas' => 1];
        foreach (['/api/v1/device/tablet/quote', '/api/v1/device/tablet/orders'] as $uri) {
            $this->p6As($this->tablet, 'POST', $uri, ['client_uuid' => (string) Str::uuid(), 'order_type' => 'quick',
                'payment' => 'cash', 'lines' => [$priced]])->assertStatus(422)->assertJsonPath('errors.0.code', 'client_priced_payload_rejected');
        }
        $this->p6Submit(['grand_total_baisas' => 1])->assertStatus(422)->assertJsonPath('errors.0.code', 'client_priced_payload_rejected');
        // Tester call 5: the tap lists only, no free text.
        $this->p6Submit(['lines' => [['notes' => 'extra hot please'] + $this->p6Line($this->coffee)]])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'validation_failed');
        $this->assertSame(0, Order::query()->count());

        $ok = $this->p6Submit(['lines' => [$this->p6Line($this->coffee, 2)]])->assertCreated();
        $this->assertSame(2000, $ok->json('data.total_baisas'));
        $this->assertSame('2.000', $this->p6Order($ok->json('data.order_uuid'))->grand_total);
    }

    public function test_quick_and_to_go_orders_wait_for_staff_with_an_order_number_and_ready_time(): void
    {
        $quick = $this->p6Submit(['lines' => [$this->p6Line($this->coffee), $this->p6Line($this->cake)]])->assertCreated()->json('data');
        $toGo = $this->p6Submit(['order_type' => 'to_go', 'lines' => [$this->p6Line($this->coffee)]])->assertCreated()->json('data');

        $this->assertSame(['quick', '1', 12, 'waiting_for_staff', null, false], [$quick['order_type'], $quick['order_number'],
            $quick['ready_in_minutes'], $quick['status'], $quick['table'], $quick['replayed']]);
        $this->assertSame(['to_go', '2', 5], [$toGo['order_type'], $toGo['order_number'], $toGo['ready_in_minutes']]);
        foreach ([$quick, $toGo] as $data) {
            $order = $this->p6Order($data['order_uuid']);
            $this->assertSame(['customer_tablet', 'held', null, null, (int) $this->tablet->id], [$order->source, $order->status,
                $order->receipt_number, $order->customer_id, (int) $order->device_id]);
        }
        $this->assertSame('T-1006-002', $this->p6Order($toGo['order_uuid'])->temp_reference);
        $this->assertSame('to_go', $this->p6Order($toGo['order_uuid'])->order_type);
        $this->assertNull($this->p6Submit(['lines' => [['product_id' => $this->p4Product('Water', '0.300'), 'qty' => 1, 'addon_ids' => []]]])
            ->assertCreated()->json('data.ready_in_minutes'));
        // Never a staff or customer field on the tablet's answer.
        $this->assertSame(['tablet_order_uuid', 'order_uuid', 'order_type', 'order_number', 'table', 'ready_in_minutes', 'status',
            'payment', 'total_baisas', 'redeem', 'replayed'], array_keys($quick));
    }

    public function test_a_double_submit_makes_one_order_and_returns_the_first_result(): void
    {
        $key = (string) Str::uuid();
        $first = $this->p6Submit(['client_uuid' => $key])->assertCreated()->json('data');
        $again = $this->p6Submit(['client_uuid' => $key, 'lines' => [$this->p6Line($this->cake, 5)]])->assertOk()->json('data');

        $this->assertTrue($again['replayed']);
        $this->assertSame($first['order_uuid'], $again['order_uuid']);
        $this->assertSame($first['tablet_order_uuid'], $again['tablet_order_uuid']);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, TabletOrder::query()->count());
        $this->assertSame(2000, $again['total_baisas']);
        // Another tablet's same key is its own order.
        $other = $this->p6Device('mdev_p6_tablet2', 'customer_tablet');
        $this->p6Submit(['client_uuid' => $key], $other)->assertCreated();
        $this->assertSame(2, Order::query()->count());
    }

    public function test_a_sold_out_or_unavailable_line_is_refused_naming_every_such_line(): void
    {
        DB::table('pos_product_sold_out')->insert(['company_id' => 100, 'branch_id' => 10, 'product_id' => $this->cake,
            'set_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $hidden = $this->p4Product('Staff meal', '0.500', ['show_on_customer_tablet' => false]);

        $refused = $this->p6Submit(['lines' => [$this->p6Line($this->coffee), $this->p6Line($this->cake), $this->p6Line($hidden)]])
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'tablet_lines_unavailable')->json('data.lines');

        $this->assertSame([1, 2], array_column($refused, 'line_index'));
        $this->assertSame([$this->cake, $hidden], array_column($refused, 'product_id'));
        $this->assertSame('sold_out', $refused[0]['reason']);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(0, TabletOrder::query()->count());
    }
}
