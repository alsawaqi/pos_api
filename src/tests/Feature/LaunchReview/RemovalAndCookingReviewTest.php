<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchReview;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\ConfirmDineInQrRoundAction;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Support\LaunchP3RecipeFixtures;
use Tests\Support\LaunchP4Fixtures;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * LAUNCH review add-on — Remove options and cooking time on every order
 * writer (work order §3.4, §4.2; menu audit §6.3, §7.2, §9; tester calls 1,
 * 14 and 16).
 *
 * Shawarma (made to order, cooking 10 min): chicken 150 g, bread 1, garlic
 * sauce 30 ml (a prep item: garlic 6 g, oil 21 ml, lemon 3 ml) and garlic
 * 5 g. Its own "Remove" group: "NO Garlic sauce" (→ the sauce line) and "NO
 * Garlic" (→ the direct garlic line). Fries (a shelf product, 4 min).
 * "Shawarma meal" 2.500: Main (is_main: Shawarma), Side (Fries).
 */
final class RemovalAndCookingReviewTest extends TestCase
{
    use LaunchP3RecipeFixtures;
    use LaunchP4Fixtures;
    use RefreshDatabase;
    use TableSessionFixtures;

    private int $noSauce;

    private int $noGarlic;

    private int $fries;

    /** @var array{id: int, slots: list<int>} */
    private array $meal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-06 09:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedPosStaff([7]);
        $this->seatingBranch();
        $this->seedPrepKitchen();
        DB::table('pos_products')->where('id', self::SHAWARMA)->update(['cooking_minutes' => 10, 'show_on_customer_tablet' => true]);
        $ids = $this->p4Addons(self::SHAWARMA, ['NO Garlic sauce' => '0.000', 'NO Garlic' => '0.000'], ['name' => 'Remove', 'kind' => 'remove']);
        $this->noSauce = $ids['NO Garlic sauce'];
        $this->noGarlic = $ids['NO Garlic'];
        DB::table('pos_addons')->where('id', $this->noSauce)->update(['removes_ingredient_id' => self::SAUCE]);
        DB::table('pos_addons')->where('id', $this->noGarlic)->update(['removes_ingredient_id' => self::GARLIC]);
        $this->fries = $this->p4Product('Fries', '0.800', ['stock_mode' => 'unit', 'cooking_minutes' => 4]);
        DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => $this->fries, 'is_available' => true,
            'stock_qty' => '20.000', 'created_at' => now(), 'updated_at' => now()]);
        $this->meal = $this->p4Combo('Shawarma meal', '2.500', [
            ['Main', 1, 1, [self::SHAWARMA => '0.000']],
            ['Side', 1, 1, [$this->fries => '0.000']],
        ]);
    }

    /** @return array<int, float> per-unit recipe copy, ingredient id => qty */
    private function recipe(int $itemId): array
    {
        $copy = [];
        foreach (OrderItem::query()->findOrFail($itemId)->recipe_snapshot_json ?? [] as $line) {
            $copy[(int) $line['ingredient_id']] = ($copy[(int) $line['ingredient_id']] ?? 0) + round((float) $line['qty'], 4);
        }
        ksort($copy);

        return $copy;
    }

    /** @return array<string, mixed> */
    private function shawarmaWire(int $qty, array $addonIds): array
    {
        return ['product_id' => self::SHAWARMA, 'qty' => $qty, 'unit_price_baisas' => 1500, 'line_total_baisas' => 1500 * $qty,
            'addons' => array_map(static fn (int $id): array => ['add_on_id' => $id, 'price_delta_baisas' => 0], $addonIds)];
    }

    /** @return array<string, mixed> */
    private function mealWire(array $mainAddonIds): array
    {
        [$main, $side] = $this->meal['slots'];

        return ['product_id' => $this->meal['id'], 'qty' => 1, 'unit_price_baisas' => 2500, 'line_total_baisas' => 2500, 'combo' => [
            ['line_id' => $main, 'product_id' => self::SHAWARMA, 'qty' => 1, 'extra_price_baisas' => 0,
                'addons' => array_map(static fn (int $id): array => ['add_on_id' => $id, 'price_delta_baisas' => 0], $mainAddonIds)],
            ['line_id' => $side, 'product_id' => $this->fries, 'qty' => 1, 'extra_price_baisas' => 0],
        ]];
    }

    /** @return array<string, float> */
    private function stock(): array
    {
        return ['garlic' => $this->branchBalance(self::GARLIC), 'oil' => $this->branchBalance(self::OIL),
            'lemon' => $this->branchBalance(self::LEMON), 'chicken' => $this->branchBalance(self::CHICKEN),
            'bread' => $this->branchBalance(self::BREAD)];
    }

    /** @return array<string, mixed> */
    private function payEventFor(string $uuid, int $baisas): array
    {
        $at = now()->subMinute()->toIso8601String();

        return ['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => $at,
            'payload' => ['order_uuid' => $uuid, 'paid_at' => $at,
                'payments' => [['method' => 'cash', 'amount_baisas' => $baisas, 'change_given_baisas' => 0]]]];
    }

    public function test_no_garlic_sauce_leaves_every_raw_ingredient_of_the_sauce_out_and_pay_and_void_never_touch_them(): void
    {
        $this->p4Device('mdev_rv_rm');
        $order = $this->p4Order([$this->shawarmaWire(2, [$this->noSauce])]);
        $this->p4Push('mdev_rv_rm', [$this->p4Event('order.create', $order)]);
        $item = OrderItem::query()->sole();
        // chicken 150, bread 1 and the direct garlic 5: no oil, no lemon, no sauce garlic.
        $this->assertSame([self::GARLIC => 5.0, self::CHICKEN => 150.0, self::BREAD => 1.0], $this->recipe((int) $item->id));
        $this->assertNull($item->addons()->sole()->consumption_snapshot_json);

        $this->p4Push('mdev_rv_rm', [$this->payEventFor($order['uuid'], 3000)]);
        $this->assertSame(['garlic' => 990.0, 'oil' => 1000.0, 'lemon' => 1000.0, 'chicken' => 4700.0, 'bread' => 48.0], $this->stock());
        $this->assertFalse(DB::table('pos_stock_movements')->whereIn('ingredient_id', [self::OIL, self::LEMON])->exists());

        $this->p4Push('mdev_rv_rm', [['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(), 'payload' => ['order_uuid' => $order['uuid'], 'reason' => 'mistake']]]);
        $this->assertSame(['garlic' => 1000.0, 'oil' => 1000.0, 'lemon' => 1000.0, 'chicken' => 5000.0, 'bread' => 50.0], $this->stock());
        $this->assertFalse(DB::table('pos_stock_movements')->whereIn('ingredient_id', [self::OIL, self::LEMON])->exists());
    }

    public function test_no_garlic_skips_only_the_direct_garlic_and_keeps_the_garlic_inside_the_sauce(): void
    {
        $this->p4Device('mdev_rv_rm2');
        $this->p4Push('mdev_rv_rm2', [$this->p4Event('order.create', $this->p4Order([$this->shawarmaWire(1, [$this->noGarlic])]))]);

        $this->assertSame([self::GARLIC => 6.0, self::OIL => 21.0, self::LEMON => 3.0, self::CHICKEN => 150.0, self::BREAD => 1.0],
            $this->recipe((int) OrderItem::query()->sole()->id));
    }

    public function test_a_combo_child_takes_its_own_removal_and_the_parent_snapshots_its_longest_childs_cooking_time(): void
    {
        $this->p4Device('mdev_rv_combo');
        $result = $this->p4Push('mdev_rv_combo', [$this->p4Event('order.create', $this->p4Order([$this->mealWire([$this->noSauce])]))])
            ->json('data.results.0');
        $this->assertSame('processed', $result['status'], (string) json_encode($result));

        $parent = OrderItem::query()->whereNull('parent_order_item_id')->sole();
        $shawarma = OrderItem::query()->where('product_id', self::SHAWARMA)->sole();
        $fries = OrderItem::query()->where('product_id', $this->fries)->sole();
        $this->assertNull($parent->recipe_snapshot_json);
        $this->assertSame([self::GARLIC => 5.0, self::CHICKEN => 150.0, self::BREAD => 1.0], $this->recipe((int) $shawarma->id));
        $this->assertSame([10, 10, 4], [(int) $parent->cooking_minutes, (int) $shawarma->cooking_minutes, (int) $fries->cooking_minutes]);
    }

    public function test_a_resent_unchanged_line_keeps_its_filtered_copy_and_a_changed_removal_copies_again(): void
    {
        $this->p4Device('mdev_rv_resend');
        $order = $this->p4Order([$this->shawarmaWire(1, [$this->noSauce])]);
        $this->p4Push('mdev_rv_resend', [$this->p4Event('order.hold', $order)]);
        $held = OrderItem::query()->sole();
        $this->assertSame(10, (int) $held->cooking_minutes);

        // The merchant changes the cooking time after the hold: the kept line keeps both copies.
        DB::table('pos_products')->where('id', self::SHAWARMA)->update(['cooking_minutes' => 25]);
        $this->p4Push('mdev_rv_resend', [$this->p4Event('order.create', $order)]);
        $kept = OrderItem::query()->sole();
        $this->assertSame([self::GARLIC => 5.0, self::CHICKEN => 150.0, self::BREAD => 1.0], $this->recipe((int) $kept->id));
        $this->assertSame(10, (int) $kept->cooking_minutes);

        // Dropping the removal changes the line: the full recipe is copied again.
        $order['lines'] = [$this->shawarmaWire(1, [])];
        $this->p4Push('mdev_rv_resend', [$this->p4Event('order.create', $order)]);
        $changed = OrderItem::query()->sole();
        $this->assertSame([self::GARLIC => 11.0, self::OIL => 21.0, self::LEMON => 3.0, self::CHICKEN => 150.0, self::BREAD => 1.0],
            $this->recipe((int) $changed->id));
        $this->assertSame(25, (int) $changed->cooking_minutes);
    }

    public function test_a_remove_option_naming_another_companys_ingredient_is_ignored_and_the_sale_settles(): void
    {
        DB::table('pos_ingredients')->insert($this->ingredientRow(99, 'Foreign garlic', 'g', '0.001000', companyId: 200));
        DB::table('pos_addons')->where('id', $this->noGarlic)->update(['removes_ingredient_id' => 99]);
        Log::spy();
        $this->p4Device('mdev_rv_foreign');
        $result = $this->p4Push('mdev_rv_foreign', [$this->p4Event('order.create', $this->p4Order([$this->shawarmaWire(1, [$this->noGarlic])]))])
            ->json('data.results.0');

        $this->assertSame('processed', $result['status'], (string) json_encode($result));
        $this->assertSame([self::GARLIC => 11.0, self::OIL => 21.0, self::LEMON => 3.0, self::CHICKEN => 150.0, self::BREAD => 1.0],
            $this->recipe((int) OrderItem::query()->sole()->id));
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_contains($message, 'outside its company'))->atLeast()->once();
    }

    public function test_qr_checkout_filters_the_line_and_combo_child_recipes_snapshots_cooking_time_and_reports_ready_in(): void
    {
        $session = $this->p4QrSession();
        [$main, $side] = $this->meal['slots'];
        $lines = [
            ['product_id' => self::SHAWARMA, 'qty' => 1, 'addon_ids' => [$this->noSauce], 'notes' => ''],
            ['product_id' => $this->meal['id'], 'qty' => 1, 'addon_ids' => [], 'notes' => '', 'combo' => [
                ['line_id' => $main, 'product_id' => self::SHAWARMA, 'qty' => 1, 'addon_ids' => [$this->noGarlic], 'notes' => ''],
                ['line_id' => $side, 'product_id' => $this->fries, 'qty' => 1, 'addon_ids' => [], 'notes' => ''],
            ]],
        ];
        $this->p4QrPost($session, '/api/v1/public/qr/checkout', $this->p4QrCheckout($lines))->assertStatus(201)
            ->assertJsonPath('data.order.ready_in_minutes', 10)
            // Contract addition (orchestrator): "Ordered at HH:MM" — the order's created time.
            ->assertJsonPath('data.order.ordered_at', '2026-10-06T09:00:00+00:00');

        $line = OrderItem::query()->whereNull('parent_order_item_id')->where('product_id', self::SHAWARMA)->sole();
        $child = OrderItem::query()->whereNotNull('parent_order_item_id')->where('product_id', self::SHAWARMA)->sole();
        $parent = OrderItem::query()->where('product_id', $this->meal['id'])->sole();
        $this->assertSame([self::GARLIC => 5.0, self::CHICKEN => 150.0, self::BREAD => 1.0], $this->recipe((int) $line->id));
        $this->assertSame([self::GARLIC => 6.0, self::OIL => 21.0, self::LEMON => 3.0, self::CHICKEN => 150.0, self::BREAD => 1.0],
            $this->recipe((int) $child->id));
        $this->assertSame([10, 10, 10, 4], [(int) $line->cooking_minutes, (int) $parent->cooking_minutes, (int) $child->cooking_minutes,
            (int) OrderItem::query()->where('product_id', $this->fries)->value('cooking_minutes')]);

        $this->travel(5)->minutes();
        $this->p4QrGet($session, '/api/v1/public/qr/status')->assertOk()->assertJsonPath('data.order.ready_in_minutes', 10)
            ->assertJsonPath('data.order.ordered_at', '2026-10-06T09:00:00+00:00');
        // A voided line no longer counts; with no timed line left, nothing is shown.
        OrderItem::query()->where('cooking_minutes', 10)->update(['status' => OrderItem::STATUS_VOID]);
        $this->p4QrGet($session, '/api/v1/public/qr/status')->assertOk()->assertJsonPath('data.order.ready_in_minutes', 4);
        OrderItem::query()->update(['cooking_minutes' => null]);
        $this->p4QrGet($session, '/api/v1/public/qr/status')->assertOk()->assertJsonPath('data.order.ready_in_minutes', null);
    }

    public function test_a_staff_round_filters_the_recipe_and_a_prepared_cancellation_books_no_waste_for_the_removed_sauce(): void
    {
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
        $payload = ['seating_key' => $seating->client_request_id, 'table_id' => (int) $seating->table_id, 'queued_offline' => false,
            'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(),
            'lines' => [['product_id' => self::SHAWARMA, 'qty' => 1, 'addon_ids' => [$this->noSauce], 'notes' => null]]];
        $ack = $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.round',
            'client_timestamp' => $payload['submitted_at'], 'payload' => $payload,
        ]]])->assertOk()->json('data.results.0');
        $this->assertSame('processed', $ack['status'], (string) json_encode($ack));
        $item = OrderItem::query()->sole();
        $this->assertSame([self::GARLIC => 5.0, self::CHICKEN => 150.0, self::BREAD => 1.0], $this->recipe((int) $item->id));
        $this->assertSame(10, (int) $item->cooking_minutes);

        $at = now()->startOfSecond()->toIso8601String();
        $cancel = $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.cancel_line', 'client_timestamp' => $at,
            'payload' => ['seating_key' => $seating->client_request_id, 'table_id' => (int) $seating->table_id, 'queued_offline' => false,
                'client_request_id' => (string) Str::uuid(), 'product_id' => self::SHAWARMA, 'addon_ids' => [$this->noSauce], 'notes' => null,
                'qty' => 1, 'prepared' => true, 'cancelled_at' => $at, 'staff_id' => 7],
        ]]])->assertOk()->json('data.results.0');
        $this->assertSame('processed', $cancel['status'], (string) json_encode($cancel));

        $wasted = DB::table('pos_waste_records')->orderBy('ingredient_id')->get()
            ->mapWithKeys(static fn (object $row): array => [(int) $row->ingredient_id => (float) $row->quantity])->all();
        $this->assertSame([self::GARLIC => 5.0, self::CHICKEN => 150.0, self::BREAD => 1.0], $wasted);
    }

    public function test_a_confirmed_qr_dine_in_round_keeps_the_filtered_copy_from_submit_time_and_reports_its_ready_in(): void
    {
        DB::table('pos_branch_settings')->insert(['company_id' => 100, 'branch_id' => 10, 'key' => 'qr_table_card_enabled', 'value' => '"on"']);
        $table = $this->seatingTable();
        $till = $this->seatingDevice();
        $bind = app(BindQrTableSessionAction::class)->handle($table->qr_token, 'owner');
        $this->withHeaders(['X-QR-Session' => $bind['session_uuid'], 'X-QR-Client-Secret' => 'owner']);
        [$main, $side] = $this->meal['slots'];
        $round = $this->postJson('/api/v1/public/qr/table-round', [
            'client_request_id' => 'rv-round', 'phone' => '91234567',
            'lines' => [
                ['product_id' => self::SHAWARMA, 'qty' => 1, 'addon_ids' => [$this->noSauce], 'notes' => null],
                ['product_id' => $this->meal['id'], 'qty' => 1, 'addon_ids' => [], 'notes' => null, 'combo' => [
                    ['line_id' => $main, 'product_id' => self::SHAWARMA, 'qty' => 1, 'addon_ids' => [], 'notes' => null],
                    ['line_id' => $side, 'product_id' => $this->fries, 'qty' => 1, 'addon_ids' => [], 'notes' => null],
                ]],
            ],
        ])->assertCreated()->assertJsonPath('data.round.status', 'pending_confirmation');
        $this->getJson('/api/v1/public/qr/status')->assertOk()->assertJsonPath('data.dine_in.rounds.0.ready_in_minutes', null);

        // The merchant edits the product before staff confirm: the round keeps its submit-time copies.
        DB::table('pos_products')->where('id', self::SHAWARMA)->update(['cooking_minutes' => 30]);
        app(ConfirmDineInQrRoundAction::class)->handle($till, $round->json('data.round.id'));

        $line = OrderItem::query()->whereNull('parent_order_item_id')->where('product_id', self::SHAWARMA)->sole();
        $this->assertSame([self::GARLIC => 5.0, self::CHICKEN => 150.0, self::BREAD => 1.0], $this->recipe((int) $line->id));
        $this->assertSame(10, (int) $line->cooking_minutes);
        $this->assertSame(10, (int) OrderItem::query()->where('product_id', $this->meal['id'])->value('cooking_minutes'));
        $this->assertSame(QrOrderRound::STATUS_ACCEPTED, QrOrderRound::query()->sole()->status);
        $this->getJson('/api/v1/public/qr/status')->assertOk()->assertJsonPath('data.dine_in.rounds.0.ready_in_minutes', 10);
    }

    public function test_a_round_confirmed_from_a_payload_frozen_before_the_add_on_writes_no_cooking_time(): void
    {
        DB::table('pos_branch_settings')->insert(['company_id' => 100, 'branch_id' => 10, 'key' => 'qr_table_card_enabled', 'value' => '"on"']);
        $table = $this->seatingTable();
        $till = $this->seatingDevice();
        $bind = app(BindQrTableSessionAction::class)->handle($table->qr_token, 'owner');
        $this->withHeaders(['X-QR-Session' => $bind['session_uuid'], 'X-QR-Client-Secret' => 'owner']);
        $round = $this->postJson('/api/v1/public/qr/table-round', [
            'client_request_id' => 'rv-old-round', 'phone' => '91234567',
            'lines' => [['product_id' => self::SHAWARMA, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
        ])->assertCreated();
        // Strip the new key the way a pre-deploy stored payload looks.
        $stored = QrOrderRound::query()->sole();
        $payload = $stored->confirm_payload;
        foreach ($payload['items'] as &$item) {
            unset($item['attributes']['cooking_minutes']);
        }
        unset($item);
        $stored->forceFill(['confirm_payload' => $payload])->save();

        app(ConfirmDineInQrRoundAction::class)->handle($till, $round->json('data.round.id'));
        $this->assertNull(OrderItem::query()->sole()->cooking_minutes);
        $this->getJson('/api/v1/public/qr/status')->assertOk()->assertJsonPath('data.dine_in.rounds.0.ready_in_minutes', null);
    }
}
