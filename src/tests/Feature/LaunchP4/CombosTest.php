<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP4;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP4Fixtures;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * LAUNCH-P4 A3 — combos on the server (owner decision 7, data contract).
 *
 * "Burger meal" 2.500: Main (Burger, made to order: beef 150 g; extra cheese
 * +0.200 = cheese 20 g), Side (Fries, a shelf product), Drink (Cola +0 or
 * Juice +0.300). One meal with cheese and juice costs 3.000.
 *
 *  - the device config carries the combo's slots and options;
 *  - every writer turns the line's choices into children: qty = combo qty
 *    × choice qty, no money, their own recipe / component / add-on copies;
 *  - pay moves each child's stock and a void restores it; the combo line
 *    itself moves nothing;
 *  - a held combo comes back nested, and a re-send keeps the children;
 *  - QR checkout and staff rounds price combos on the server; kitchen
 *    tickets carry the components; a cancelled combo line takes its
 *    children with it.
 */
final class CombosTest extends TestCase
{
    use LaunchP4Fixtures;
    use RefreshDatabase;
    use TableSessionFixtures;

    private int $burger;

    private int $fries;

    private int $cola;

    private int $juice;

    private int $cheese;

    /** @var array{id: int, slots: list<int>} */
    private array $meal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-03 09:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedPosStaff([7]);
        $this->seatingBranch();
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_ingredients')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Beef', 'unit' => 'g', 'default_unit_cost' => '0.004000', 'status' => 'active'] + $t,
            ['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Cheese', 'unit' => 'g', 'default_unit_cost' => '0.006000', 'status' => 'active'] + $t,
        ]);
        DB::table('pos_branch_stock')->insert([
            ['branch_id' => 10, 'ingredient_id' => 1, 'quantity' => '5000'] + $t,
            ['branch_id' => 10, 'ingredient_id' => 2, 'quantity' => '1000'] + $t,
        ]);
        $this->burger = $this->p4Product('Burger', '2.000', ['stock_mode' => 'ingredient']);
        DB::table('pos_product_recipes')->insert(['product_id' => $this->burger, 'ingredient_id' => 1, 'quantity' => '150', 'unit_at_set' => 'g', 'sort_order' => 1] + $t);
        $this->fries = $this->p4Product('Fries', '0.800', ['stock_mode' => 'unit']);
        DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => $this->fries, 'is_available' => true, 'stock_qty' => '20.000'] + $t);
        $this->cola = $this->p4Product('Cola', '0.500');
        $this->juice = $this->p4Product('Juice', '0.900', ['sold_in_store' => false]);
        $this->cheese = $this->p4Addons($this->burger, ['Cheese' => '0.200'])['Cheese'];
        DB::table('pos_addons')->where('id', $this->cheese)->update(['ingredient_id' => 2, 'ingredient_qty' => '20', 'ingredient_unit' => 'g']);
        $this->meal = $this->p4Combo('Burger meal', '2.500', [
            ['Main', 1, 1, [$this->burger => '0.000']],
            ['Side', 1, 1, [$this->fries => '0.000']],
            ['Drink', 1, 1, [$this->cola => '0.000', $this->juice => '0.300']],
        ]);
    }

    /** The device wire for one meal: burger + cheese, fries, juice (3.000). */
    private function wireLine(int $qty = 2): array
    {
        [$main, $side, $drink] = $this->meal['slots'];

        return [
            'product_id' => $this->meal['id'], 'qty' => $qty, 'unit_price_baisas' => 3000, 'line_total_baisas' => 3000 * $qty,
            'combo' => [
                ['slot_id' => $main, 'product_id' => $this->burger, 'qty' => 1, 'extra_price_baisas' => 0,
                    'addons' => [['add_on_id' => $this->cheese, 'price_delta_baisas' => 200]]],
                ['slot_id' => $side, 'product_id' => $this->fries, 'qty' => 1, 'extra_price_baisas' => 0],
                ['slot_id' => $drink, 'product_id' => $this->juice, 'qty' => 1, 'extra_price_baisas' => 300, 'notes' => 'No ice'],
            ],
        ];
    }

    /** The QR / server-priced shape of the same meal. */
    private function qrLine(int $qty = 2, ?array $drink = null): array
    {
        [$main, $side, $drinkSlot] = $this->meal['slots'];

        return [
            'product_id' => $this->meal['id'], 'qty' => $qty, 'addon_ids' => [], 'notes' => '',
            'combo' => array_values(array_filter([
                ['slot_id' => $main, 'product_id' => $this->burger, 'qty' => 1, 'addon_ids' => [$this->cheese], 'notes' => ''],
                ['slot_id' => $side, 'product_id' => $this->fries, 'qty' => 1, 'addon_ids' => [], 'notes' => ''],
                $drink ?? ['slot_id' => $drinkSlot, 'product_id' => $this->juice, 'qty' => 1, 'addon_ids' => [], 'notes' => 'No ice'],
            ])),
        ];
    }

    /** @return array<int, array{qty: float, price: float, slot: int|null, extra: float, parent: int|null}> children by product */
    private function children(string $uuid): array
    {
        $order = Order::query()->where('uuid', $uuid)->sole();
        $parent = OrderItem::query()->where('order_id', $order->id)->whereNull('parent_order_item_id')->sole();
        $rows = [];
        foreach (OrderItem::query()->where('parent_order_item_id', $parent->id)->orderBy('id')->get() as $child) {
            $rows[(int) $child->product_id] = ['qty' => (float) $child->qty, 'price' => (float) $child->line_total,
                'slot' => $child->combo_slot_id !== null ? (int) $child->combo_slot_id : null,
                'extra' => (float) $child->combo_extra_price, 'parent' => (int) $child->parent_order_item_id];
        }

        return $rows;
    }

    public function test_the_device_config_carries_the_combo_slots_and_options(): void
    {
        $this->p4Device('mdev_p4_combo');
        $products = collect($this->withToken('mdev_p4_combo')->getJson('/api/v1/device/config')->assertOk()->json('data.products'))->keyBy('id');

        $meal = $products[$this->meal['id']];
        $this->assertSame('combo', $meal['product_type']);
        $this->assertSame([], $meal['addon_group_ids']);
        [$main, $side, $drink] = $this->meal['slots'];
        $this->assertSame([
            ['id' => $main, 'name' => 'Main', 'name_ar' => 'Main (ع)', 'min' => 1, 'max' => 1, 'sort_order' => 0,
                'options' => [['product_id' => $this->burger, 'extra_price_baisas' => 0, 'is_default' => true, 'sort_order' => 0]]],
            ['id' => $side, 'name' => 'Side', 'name_ar' => 'Side (ع)', 'min' => 1, 'max' => 1, 'sort_order' => 1,
                'options' => [['product_id' => $this->fries, 'extra_price_baisas' => 0, 'is_default' => true, 'sort_order' => 0]]],
            ['id' => $drink, 'name' => 'Drink', 'name_ar' => 'Drink (ع)', 'min' => 1, 'max' => 1, 'sort_order' => 2,
                'options' => [
                    ['product_id' => $this->cola, 'extra_price_baisas' => 0, 'is_default' => true, 'sort_order' => 0],
                    ['product_id' => $this->juice, 'extra_price_baisas' => 300, 'is_default' => false, 'sort_order' => 1],
                ]],
        ], $meal['combo']['slots']);
        // A choice sold only inside combos still reaches the device for the builder.
        $this->assertFalse($products[$this->juice]['sold_in_store']);
        $this->assertSame('standard', $products[$this->burger]['product_type']);
        $this->assertArrayNotHasKey('combo', $products[$this->burger]);
    }

    public function test_order_create_writes_combo_children_with_their_own_copies_and_no_money(): void
    {
        $this->p4Device('mdev_p4_combo');
        $order = $this->p4Order([$this->wireLine()]);
        $result = $this->p4Push('mdev_p4_combo', [$this->p4Event('order.create', $order)])->json('data.results.0');
        $this->assertSame('processed', $result['status'], (string) json_encode($result));
        $this->assertTrue($result['result']['pricing_check']['match'], (string) json_encode($result['result']['pricing_check']));

        $parent = OrderItem::query()->whereNull('parent_order_item_id')->sole();
        $this->assertSame([$this->meal['id'], 2.0, 6.0, null], [(int) $parent->product_id, (float) $parent->qty, (float) $parent->line_total, $parent->recipe_snapshot_json]);
        [$main, $side, $drink] = $this->meal['slots'];
        $this->assertSame([
            $this->burger => ['qty' => 2.0, 'price' => 0.0, 'slot' => $main, 'extra' => 0.0, 'parent' => (int) $parent->id],
            $this->fries => ['qty' => 2.0, 'price' => 0.0, 'slot' => $side, 'extra' => 0.0, 'parent' => (int) $parent->id],
            $this->juice => ['qty' => 2.0, 'price' => 0.0, 'slot' => $drink, 'extra' => 0.3, 'parent' => (int) $parent->id],
        ], $this->children($order['uuid']));
        $burger = OrderItem::query()->where('product_id', $this->burger)->sole();
        $this->assertSame(150.0, (float) $burger->recipe_snapshot_json[0]['qty']);
        $cheese = $burger->addons()->sole();
        $this->assertSame([$this->cheese, 0.2], [(int) $cheese->add_on_id, (float) $cheese->price_delta_snapshot]);
        $this->assertSame('No ice', OrderItem::query()->where('product_id', $this->juice)->value('notes'));

        // A wrong extra price is flagged by the pricing check, never refused.
        $wrong = $this->wireLine(1);
        $wrong['combo'][2]['extra_price_baisas'] = 100;
        $wrong['unit_price_baisas'] = 2800;
        $wrong['line_total_baisas'] = 2800;
        $result = $this->p4Push('mdev_p4_combo', [$this->p4Event('order.create', $this->p4Order([$wrong]))])->json('data.results.0');
        $this->assertSame('processed', $result['status']);
        $this->assertSame('combo', $result['result']['pricing_check']['failures'][0]['code']);
        $this->assertSame(300, $result['result']['pricing_check']['failures'][0]['expected']['extra_price_baisas']);
    }

    public function test_paying_a_combo_moves_each_childs_own_stock_and_a_void_restores_it(): void
    {
        $this->p4Device('mdev_p4_combo');
        $order = $this->p4Order([$this->wireLine()]);
        $at = now()->subMinute()->toIso8601String();
        $this->p4Push('mdev_p4_combo', [
            $this->p4Event('order.create', $order),
            ['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => $at,
                'payload' => ['order_uuid' => $order['uuid'], 'paid_at' => $at,
                    'payments' => [['method' => 'cash', 'amount_baisas' => 6000, 'change_given_baisas' => 0]]]],
        ]);
        $stock = fn (): array => [
            (float) DB::table('pos_branch_stock')->where('ingredient_id', 1)->value('quantity'),
            (float) DB::table('pos_branch_stock')->where('ingredient_id', 2)->value('quantity'),
            (float) DB::table('pos_branch_product')->where('product_id', $this->fries)->value('stock_qty'),
        ];
        // 2 × burger 150 g beef + 20 g cheese, 2 × fries off the shelf.
        $this->assertSame([4700.0, 960.0, 18.0], $stock());
        $this->assertFalse(DB::table('pos_product_stock_movements')->where('product_id', $this->meal['id'])->exists());

        $this->p4Push('mdev_p4_combo', [['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(), 'payload' => ['order_uuid' => $order['uuid'], 'reason' => 'mistake']]]);
        $this->assertSame([5000.0, 1000.0, 20.0], $stock());
        $this->assertSame(['void'], OrderItem::query()->pluck('status')->unique()->values()->all());
    }

    public function test_a_held_combo_comes_back_nested_and_a_resend_keeps_the_children(): void
    {
        $this->p4Device('mdev_p4_combo');
        $plain = ['product_id' => $this->cola, 'qty' => 1, 'unit_price_baisas' => 500, 'line_total_baisas' => 500];
        $order = $this->p4Order([$this->wireLine(), $plain], 0, null, [
            'comp_total_baisas' => 500, 'grand_total_baisas' => 6000,
            'comps' => [['is_gift' => true, 'amount_baisas' => 500, 'line_index' => 1]],
        ]);
        $this->p4Push('mdev_p4_combo', [$this->p4Event('order.hold', $order)]);

        $held = collect($this->withToken('mdev_p4_combo')->getJson('/api/v1/device/orders/active')->assertOk()->json('data.orders'))
            ->firstWhere('uuid', $order['uuid']);
        $this->assertCount(2, $held['items']);
        $this->assertSame([$this->burger, $this->fries, $this->juice], array_column($held['items'][0]['combo'], 'product_id'));
        $this->assertSame([1.0, 1.0, 1.0], array_map('floatval', array_column($held['items'][0]['combo'], 'qty')));
        $this->assertSame(300, $held['items'][0]['combo'][2]['extra_price_baisas']);
        $this->assertArrayNotHasKey('combo', $held['items'][1]);
        // The gift still points at the cola line.
        $this->assertSame(1, $held['comps'][0]['line_index']);

        $childIds = OrderItem::query()->whereNotNull('parent_order_item_id')->orderBy('id')->pluck('recipe_snapshot_json')->all();
        $this->p4Push('mdev_p4_combo', [$this->p4Event('order.create', $order)]);
        $this->assertSame(5, OrderItem::query()->count());
        $this->assertSame($childIds, OrderItem::query()->whereNotNull('parent_order_item_id')->orderBy('id')->pluck('recipe_snapshot_json')->all());
    }

    public function test_qr_checkout_prices_a_combo_writes_its_children_and_refuses_bad_choices(): void
    {
        $session = $this->p4QrSession();
        [, $side, $drink] = $this->meal['slots'];

        $this->p4QrPost($session, '/api/v1/public/qr/quote', ['lines' => [$this->qrLine()]])->assertOk()
            ->assertJsonPath('data.quote.lines.0.unit_price_baisas', 3000)
            ->assertJsonPath('data.quote.lines.0.components.2.extra_price_baisas', 300)
            ->assertJsonPath('data.quote.grand_total_baisas', 6000);

        // Every slot must be filled; a product must be an option of its slot.
        $missing = $this->qrLine();
        unset($missing['combo'][2]);
        $missing['combo'] = array_values($missing['combo']);
        $this->p4QrPost($session, '/api/v1/public/qr/quote', ['lines' => [$missing]])->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'combo_invalid');
        $this->p4QrPost($session, '/api/v1/public/qr/quote', ['lines' => [$this->qrLine(1, ['slot_id' => $side, 'product_id' => $this->cola, 'qty' => 1, 'addon_ids' => [], 'notes' => ''])]])
            ->assertStatus(422)->assertJsonPath('errors.0.code', 'combo_invalid');
        // A client price inside a combo is refused like anywhere else.
        $priced = $this->qrLine();
        $priced['combo'][0]['extra_price_baisas'] = 0;
        $this->p4QrPost($session, '/api/v1/public/qr/quote', ['lines' => [$priced]])->assertStatus(422);

        $this->p4QrPost($session, '/api/v1/public/qr/checkout', $this->p4QrCheckout([$this->qrLine()]))->assertStatus(201)
            ->assertJsonPath('data.order.grand_total_baisas', 6000);
        $uuid = (string) DB::table('pos_orders')->value('uuid');
        $children = $this->children($uuid);
        $this->assertSame([2.0, 2.0, 2.0], array_column($children, 'qty'));
        $this->assertSame([0.0, 0.0, 0.0], array_column($children, 'price'));
        $this->assertSame([$drink], array_values(array_filter(array_column($children, 'slot'), fn (int $s): bool => $s === $drink)));
        $this->assertSame(0.2, (float) DB::table('pos_order_item_addons')->where('add_on_id', $this->cheese)->value('price_delta_snapshot'));
    }

    public function test_a_staff_round_combo_is_priced_by_the_server_and_its_ticket_lists_the_components(): void
    {
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
        $line = $this->wireLine(1);
        unset($line['unit_price_baisas'], $line['line_total_baisas']);
        $line['combo'][0]['addons'][0]['price_delta_baisas'] = 9999; // ignored: the server prices
        $line += ['addon_ids' => [], 'notes' => null];
        $payload = ['seating_key' => $seating->client_request_id, 'table_id' => (int) $seating->table_id, 'queued_offline' => false,
            'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(), 'lines' => [$line]];
        $ack = $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.round',
            'client_timestamp' => $payload['submitted_at'], 'payload' => $payload,
        ]]])->assertOk()->json('data.results.0');
        $this->assertSame('processed', $ack['status'], (string) json_encode($ack));
        $this->assertSame(3000, $ack['result']['total_baisas']);

        $round = QrOrderRound::query()->findOrFail($ack['result']['round_id']);
        $components = $round->priced_lines[0]['components'];
        $this->assertSame(['Burger', 'Fries', 'Juice'], array_column($components, 'product_name'));
        $this->assertSame('Cheese', $components[0]['addons'][0]['name']);
        $this->assertSame(1, OrderItem::query()->whereNull('parent_order_item_id')->count());
        $this->assertSame(3, OrderItem::query()->whereNotNull('parent_order_item_id')->count());

        $ticket = $this->withToken($device->plainTextToken)->postJson('/api/v1/device/kitchen/claim-print', ['ticket_key' => 'round:'.$round->id])
            ->assertSuccessful()->json('data');
        $this->assertSame(['Burger', 'Fries', 'Juice'], array_column($ticket['priced_lines'][0]['components'], 'product_name'));
        $this->assertSame('No ice', $ticket['priced_lines'][0]['components'][2]['notes']);

        // Cancelling the combo line takes its children with it.
        $cancel = $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.cancel_line',
            'client_timestamp' => now()->toIso8601String(), 'payload' => [
                'seating_key' => $seating->client_request_id, 'table_id' => (int) $seating->table_id, 'queued_offline' => false,
                'client_request_id' => (string) Str::uuid(), 'product_id' => $this->meal['id'], 'qty' => 1,
                'prepared' => false, 'cancelled_at' => now()->toIso8601String(), 'staff_id' => 7,
            ],
        ]]])->assertOk()->json('data.results.0');
        $this->assertSame('processed', $cancel['status'], (string) json_encode($cancel));
        $this->assertSame([0.0], OrderItem::query()->whereNotNull('parent_order_item_id')->pluck('qty')->map('floatval')->unique()->values()->all());
        $this->assertSame(['void'], OrderItem::query()->pluck('status')->unique()->values()->all());
    }
}
