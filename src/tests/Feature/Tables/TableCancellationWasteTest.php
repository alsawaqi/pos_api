<?php

declare(strict_types=1);

namespace Tests\Feature\Tables;

use App\Models\CompReason;
use App\Models\Order;
use App\Models\Payment;
use App\Models\TableSessionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class TableCancellationWasteTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedPosStaff([7]);
    }

    private function postAs($device, string $path, array $payload)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($device->device_token)->postJson('/api/v1/device/'.$path, $payload);
    }

    private function fixture(): array
    {
        $device = $this->seatingDevice();
        $seat = $this->seatingRow($this->seatingTable('T11 recipe'), ['opened_by_device_id' => $device->id]);
        $product = $this->seatingProduct();
        $product->update(['stock_mode' => 'ingredient']);
        $ingredients = [];
        // Two prepared units: .040*4=.160, .300*1.2=.360,
        // .010*2=.020, plus .020*5=.100 => .640 OMR.
        foreach ([[.020, 4], [.150, 1.2], [.005, 2], [.010, 5]] as $i => [$qty, $cost]) {
            $id = DB::table('pos_ingredients')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => 100,
                'name' => 'T11 ingredient '.$i, 'unit' => 'kg', 'default_unit_cost' => $cost]);
            $ingredients[] = $id;
            DB::table('pos_branch_stock')->insert(['branch_id' => 10, 'ingredient_id' => $id, 'quantity' => .010]);
            if ($i < 3) {
                DB::table('pos_product_recipes')->insert(['product_id' => $product->id, 'ingredient_id' => $id,
                    'quantity' => $qty, 'unit_at_set' => 'kg', 'sort_order' => $i]);
            }
        }
        $group = DB::table('pos_addon_groups')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'T11 extra']);
        $addon = DB::table('pos_addons')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => 100,
            'add_on_group_id' => $group, 'name' => 'T11 extra ingredient', 'price_delta' => 0,
            'ingredient_id' => $ingredients[3], 'ingredient_qty' => .010, 'ingredient_unit' => 'kg']);
        DB::table('pos_addon_group_products')->insert(['add_on_group_id' => $group, 'product_id' => $product->id]);
        $prefix = ['table_id' => (int) $seat->table_id, 'seating_key' => $seat->client_request_id,
            'queued_offline' => false, 'staff_id' => 7];
        $this->postAs($device, 'tables/'.$seat->uuid.'/round', $prefix + [
            'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(),
            'lines' => [['product_id' => $product->id, 'qty' => 3, 'addon_ids' => [$addon]]],
        ])->assertOk()->assertJsonPath('data.outcome', 'appended');
        $order = Order::query()->sole();
        $cancel = $prefix + ['client_request_id' => (string) Str::uuid(), 'product_id' => (int) $product->id,
            'addon_ids' => [$addon], 'notes' => null, 'qty' => 2, 'prepared' => true,
            'reason' => 'T11 synthetic cancellation', 'authorized_by' => 'Test manager',
            'cancelled_at' => now()->toIso8601String()];

        return [$device, $seat->refresh(), $order, $cancel, $ingredients];
    }

    public function test_f32_staff_round_prices_shared_addon_per_line_and_refuses_within_line_duplicate(): void
    {
        [$device, $seat, $order, $cancel] = $this->fixture();
        $line = ['product_id' => $cancel['product_id'], 'qty' => 1, 'addon_ids' => $cancel['addon_ids']];
        $payload = ['table_id' => (int) $seat->table_id, 'seating_key' => $seat->client_request_id,
            'queued_offline' => false, 'staff_id' => 7, 'client_request_id' => (string) Str::uuid(),
            'submitted_at' => now()->toIso8601String(), 'lines' => [$line, $line + ['notes' => 'second']]];
        $this->postAs($device, 'tables/'.$seat->uuid.'/round', $payload)->assertOk()
            ->assertJsonPath('data.outcome', 'appended')->assertJsonPath('data.total_baisas', 2000);
        $this->assertSame('5.000', $order->fresh()->grand_total);
        $payload['client_request_id'] = (string) Str::uuid();
        $payload['lines'][0]['addon_ids'] = array_merge($cancel['addon_ids'], $cancel['addon_ids']);
        $this->postAs($device, 'tables/'.$seat->uuid.'/round', $payload)->assertStatus(422);
        $this->assertSame('5.000', $order->fresh()->grand_total);
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (['pos_orders', 'pos_order_items', 'pos_order_item_addons', 'pos_qr_order_rounds', 'pos_table_sessions',
            'pos_table_session_events', 'pos_waste_records', 'pos_stock_movements', 'pos_branch_stock',
            'pos_order_comps', 'pos_order_discounts', 'pos_payments'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $rows;
    }

    public static function recipeCases(): array
    {
        return ['golden' => [false, false], 'recipe edited after acceptance' => [true, false], 'requested three only two remain' => [true, true]];
    }

    #[DataProvider('recipeCases')]
    public function test_prepared_frozen_recipe_golden_and_idempotency(bool $edit, bool $partial): void
    {
        [$device, $seat, $order, $cancel, $ids] = $this->fixture();
        if ($edit) {
            DB::table('pos_product_recipes')->update(['quantity' => 99]);
            DB::table('pos_ingredients')->update(['default_unit_cost' => 99]);
            DB::table('pos_addons')->update(['ingredient_qty' => 99]);
        }
        if ($partial) {
            $first = array_replace($cancel, ['qty' => 1, 'prepared' => false, 'client_request_id' => (string) Str::uuid()]);
            $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-line', $first)->assertOk();
            $cancel['qty'] = 3;
        }
        $r = $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-line', $cancel)->assertOk()
            ->assertJsonPath('data.cancelled_qty', 2)->assertJsonPath('data.waste.booked', true)
            ->assertJsonPath('data.waste.cost_baisas', 640);
        $event = TableSessionEvent::query()->where('payload->client_request_id', $cancel['client_request_id'])->sole();
        $this->assertSame($r->json('data.waste'), $event->payload['waste']);
        $this->assertDatabaseCount('pos_waste_records', 4);
        $this->assertDatabaseCount('pos_stock_movements', 4);
        foreach ([[.040, 4, -.030], [.300, 1.2, -.290], [.010, 2, 0], [.020, 5, -.010]] as $i => [$qty, $cost, $stock]) {
            $movement = DB::table('pos_stock_movements')->where('ingredient_id', $ids[$i])->sole();
            $waste = DB::table('pos_waste_records')->where('ingredient_id', $ids[$i])->sole();
            $this->assertSame(-$qty, (float) $movement->quantity);
            $this->assertSame((float) $cost, (float) $movement->unit_cost_at_time);
            $this->assertSame((int) $event->id, (int) $movement->reference_id);
            $this->assertSame('table_session_event', $movement->reference_type);
            $this->assertSame('waste', $movement->movement_type);
            $this->assertSame(7, (int) $movement->recorded_by_pos_staff_id);
            $this->assertSame($qty, (float) $waste->quantity);
            $this->assertSame('kg', $waste->unit_at_set);
            $this->assertEqualsWithDelta($stock, (float) DB::table('pos_branch_stock')->where('ingredient_id', $ids[$i])->value('quantity'), .000001);
        }
        fwrite(STDOUT, "\nT11_GOLDEN_MEASURED=".json_encode(DB::table('pos_stock_movements')->get(['ingredient_id', 'quantity', 'unit_cost_at_time', 'reference_type', 'reference_id', 'recorded_by_pos_staff_id']))."\n");
        $before = $this->snapshot();
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-line', $cancel)->assertOk()->assertJsonPath('data.outcome', 'replayed')
            ->assertJsonPath('data.waste.cost_baisas', 640);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_not_prepared_has_explicit_empty_waste_and_no_stock_write(): void
    {
        [$device, $seat, $order, $cancel] = $this->fixture();
        $cancel['prepared'] = false;
        $before = DB::table('pos_branch_stock')->get()->toJson();
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-line', $cancel)->assertOk()
            ->assertJsonPath('data.waste', ['booked' => false, 'cost_baisas' => 0, 'ingredients' => []]);
        $this->assertDatabaseCount('pos_waste_records', 0);
        $this->assertDatabaseCount('pos_stock_movements', 0);
        $this->assertSame($before, DB::table('pos_branch_stock')->get()->toJson());
    }

    public static function guards(): iterable
    {
        foreach (['held', 'claim', 'billing', 'ambiguous'] as $cause) {
            foreach (['cancel-line', 'cancel-bill'] as $route) {
                yield $route.'/'.$cause => [$cause, $route];
            }
        }
    }

    private function bill(array $cancel): array
    {
        $line = array_intersect_key($cancel, array_flip(['product_id', 'addon_ids', 'notes', 'prepared']));
        $line += ['qty' => 3, 'client_request_id' => (string) Str::uuid()];

        return array_intersect_key($cancel, array_flip(['table_id', 'seating_key', 'queued_offline', 'staff_id', 'client_request_id',
            'reason', 'authorized_by', 'cancelled_at'])) + ['lines' => [$line]];
    }

    #[DataProvider('guards')]
    public function test_reserved_guards_write_nothing(string $cause, string $route): void
    {
        [$device, $seat, $order, $cancel] = $this->fixture();
        if ($cause === 'held') {
            $order->update(['status' => 'held']);
        } elseif ($cause === 'claim') {
            $this->postAs($device, 'qr/claim-settlement', ['order_uuid' => $order->uuid])->assertOk();
        } elseif ($cause === 'billing') {
            $seat->update(['billing_at' => now()]);
        } else {
            $order->update(['charge_amount_baisas' => 1000]);
        }
        $before = $this->snapshot();
        $this->postAs($device, 'tables/'.$seat->uuid.'/'.$route, $route === 'cancel-bill' ? $this->bill($cancel) : $cancel)
            ->assertConflict()->assertJsonPath('errors.0.code', 'bill_reserved');
        $this->assertSame($before, $this->snapshot());
    }

    public static function transports(): array
    {
        return [['route'], ['sync']];
    }

    #[DataProvider('transports')]
    public function test_cancel_bill_reverses_adjustments_closes_and_replays_without_payment(string $transport): void
    {
        [$device, $seat, $order, $cancel] = $this->fixture();
        $shelf = $this->seatingProduct();
        $shelf->update(['stock_mode' => 'unit', 'cost_price' => .200]);
        DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => $shelf->id, 'is_available' => true, 'stock_qty' => 4]);
        $prefix = array_intersect_key($cancel, array_flip(['table_id', 'seating_key', 'queued_offline', 'staff_id']));
        $this->postAs($device, 'tables/'.$seat->uuid.'/round', $prefix + ['client_request_id' => (string) Str::uuid(),
            'submitted_at' => now()->toIso8601String(), 'lines' => [['product_id' => $shelf->id, 'qty' => 2, 'addon_ids' => []]]])->assertOk();
        $reason = CompReason::query()->create(['uuid' => Str::uuid(), 'company_id' => 100, 'code' => 'T11', 'name' => 'T11 reason', 'is_active' => true]);
        foreach ([['kind' => 'discount', 'mode' => 'fixed', 'amount_baisas' => 100, 'label' => 'T11'],
            ['kind' => 'comp', 'mode' => 'apply', 'comp_reason_id' => $reason->id, 'authorized_by' => 'Test manager',
                'target' => ['order_item_id' => $order->items()->first()->id, 'qty' => 1]]] as $adjustment) {
            $this->postAs($device, 'tables/'.$seat->uuid.'/adjust', $prefix + ['client_request_id' => (string) Str::uuid(), 'adjustment' => $adjustment])->assertOk();
        }
        $original = DB::table('pos_order_comps')->get()->toJson();
        $compIds = DB::table('pos_order_comps')->pluck('id');
        $pending = $this->seatingRound($seat, $order, ['round_no' => 3, 'status' => 'pending_confirmation', 'needs_review' => true]);
        $payload = $this->bill($cancel);
        $payload['lines'][] = ['client_request_id' => (string) Str::uuid(), 'product_id' => $shelf->id, 'addon_ids' => [], 'qty' => 2, 'prepared' => false];
        $call = fn () => $transport === 'route'
            ? $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-bill', $payload)
            : $this->postAs($device, 'sync/push', ['events' => [['client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.cancel_bill',
                'client_timestamp' => now()->toIso8601String(), 'payload' => $payload]]]);
        $path = $transport === 'route' ? 'data' : 'data.results.0.result';
        $call()->assertOk()->assertJsonPath($path.'.outcome', 'cancelled');
        $this->assertSame('void', $order->fresh()->status);
        $this->assertSame('Cancelled at table', $order->fresh()->void_reason_label);
        $this->assertNull($order->fresh()->receipt_number);
        $this->assertSame('closed', $seat->fresh()->status);
        $this->assertSame('cancelled', $seat->fresh()->close_reason);
        $this->assertSame('rejected', $pending->fresh()->status);
        $this->assertDatabaseCount('pos_payments', 0);
        $this->assertDatabaseCount('pos_product_stock_movements', 0);
        $this->assertSame(4.0, (float) DB::table('pos_branch_product')->value('stock_qty'));
        $this->assertEquals(0, DB::table('pos_order_comps')->sum('amount'));
        $this->assertEquals(0, DB::table('pos_order_discounts')->where('amount_type_snapshot', 'like', 'table_manual_%')->sum('amount'));
        $this->assertSame($original, DB::table('pos_order_comps')->whereIn('id', $compIds)->get()->toJson());
        $this->assertSame(1, TableSessionEvent::query()->where('event_type', 'bill_cancelled')->count());
        $before = $this->snapshot();
        $call()->assertOk()->assertJsonPath($path.'.outcome', 'replayed');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_bill_changed_supplies_groups_without_any_partial_cancellation(): void
    {
        [$device, $seat, $order, $cancel] = $this->fixture();
        $payload = $this->bill($cancel);
        $payload['lines'][0]['qty'] = 2;
        $before = $this->snapshot();
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-bill', $payload)->assertConflict()
            ->assertJsonPath('errors.0.code', 'bill_changed')->assertJsonPath('data.groups.0.qty', 3);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_successful_payment_blocks_bill_cancel_without_writes(): void
    {
        [$device, $seat, $order, $cancel] = $this->fixture();
        Payment::query()->create(['uuid' => Str::uuid(), 'order_id' => $order->id, 'method' => 'cash', 'amount' => 1, 'status' => 'success']);
        $before = $this->snapshot();
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-bill', $this->bill($cancel))->assertConflict()
            ->assertJsonPath('errors.0.code', 'bill_has_payment');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_recipe_less_ingredient_parent_still_wastes_frozen_addon(): void
    {
        [$device, $seat, $order, $cancel, $ids] = $this->fixture();
        // Real persisted legacy shape: null recipe, still frozen add-on.
        $order->items()->update(['recipe_snapshot_json' => null]);
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-line', $cancel)->assertOk()
            ->assertJsonPath('data.waste.cost_baisas', 100);
        $this->assertDatabaseCount('pos_waste_records', 1);
        $this->assertDatabaseHas('pos_waste_records', ['ingredient_id' => $ids[3], 'quantity' => '.020']);
    }

    public function test_viewing_phone_gets_cancelled_bill_without_adopting_it_and_board_is_free(): void
    {
        [$device, $seat, $order, $cancel] = $this->fixture();
        DB::table('pos_branch_settings')->insert(['company_id' => 100, 'branch_id' => 10, 'key' => 'qr_table_card_enabled', 'value' => '"on"']);
        $bind = $this->postJson('/api/v1/public/qr/table-bind', ['table_token' => $seat->table->qr_token, 'client_secret' => 't11-test-browser'])->assertOk();
        $uuid = $bind->json('data.session_uuid');
        $this->withHeaders(['X-QR-Session' => $uuid, 'X-QR-Client-Secret' => 't11-test-browser']);
        $this->getJson('/api/v1/public/qr/status')->assertOk()->assertJsonPath('data.order', null);
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-bill', $this->bill($cancel))->assertOk();
        $this->getJson('/api/v1/public/qr/status')->assertOk()->assertJsonPath('data.order.status', 'void')
            ->assertJsonPath('data.dine_in.round_submission.allowed', false)->assertJsonPath('data.dine_in.finish_and_pay.allowed', false);
        $this->assertDatabaseHas('pos_qr_sessions', ['uuid' => $uuid, 'status' => 'closed']);
        $this->assertNull($order->fresh()->qr_session_id);
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);
        $this->getJson('/api/v1/device/tables/board')->assertOk()->assertJsonPath('data.tables.0.seating', null);
    }

    public static function foreignDevices(): array
    {
        return [['fixed_pos', 20, 100], ['fixed_pos', 30, 200], ['payment_station', 10, 100]];
    }

    #[DataProvider('foreignDevices')]
    public function test_cancel_bill_rejects_wrong_branch_tenant_or_unattended_device(string $type, int $branch, int $company): void
    {
        [$device, $seat, $order, $cancel] = $this->fixture();
        $other = $this->seatingDevice($type, $branch, $company);
        $before = $this->snapshot();
        $r = $this->postAs($other, 'tables/'.$seat->uuid.'/cancel-bill', $this->bill($cancel));
        $this->assertContains($r->status(), [403, 404, 409, 422]);
        $r->assertJsonPath('errors.0.code', $type === 'payment_station' ? 'device_not_attended' : 'table_not_found');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_whole_cancel_empty_terminal_and_reused_request_refusals(): void
    {
        [$device, $seat, $order, $cancel] = $this->fixture();
        $payload = $this->bill($cancel);
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-bill', $payload)->assertOk();
        $before = $this->snapshot();
        $payload['reason'] = 'different request';
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-bill', $payload)->assertConflict()->assertJsonPath('errors.0.code', 'cancel_request_conflict');
        $payload['client_request_id'] = (string) Str::uuid();
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-bill', $payload)->assertConflict()->assertJsonPath('errors.0.code', 'bill_terminal');
        $this->assertSame($before, $this->snapshot());
        $empty = $this->seatingRow($this->seatingTable('T11 empty'));
        $emptyOrder = $this->seatingOrder($empty);
        $payload['table_id'] = $empty->table_id;
        $payload['seating_key'] = $empty->client_request_id;
        $payload['lines'] = [];
        $this->postAs($device, 'tables/'.$empty->uuid.'/cancel-bill', $payload)->assertConflict()->assertJsonPath('errors.0.code', 'nothing_to_cancel');
        $this->assertSame('open', $emptyOrder->fresh()->status);
    }

    public function test_whole_bill_shelf_line_keeps_existing_waste_proof_and_cap(): void
    {
        [$device, $seat, $order, $cancel] = $this->fixture();
        $shelf = $this->seatingProduct();
        $shelf->update(['stock_mode' => 'unit', 'cost_price' => .200]);
        DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => $shelf->id, 'is_available' => true, 'stock_qty' => 2]);
        $prefix = array_intersect_key($cancel, array_flip(['table_id', 'seating_key', 'queued_offline', 'staff_id']));
        $this->postAs($device, 'tables/'.$seat->uuid.'/round', $prefix + ['client_request_id' => (string) Str::uuid(),
            'submitted_at' => now()->toIso8601String(), 'lines' => [['product_id' => $shelf->id, 'qty' => 2, 'addon_ids' => []]]])->assertOk();
        $payload = $this->bill($cancel);
        $payload['lines'][0]['prepared'] = false;
        $id = (string) Str::uuid();
        $payload['lines'][] = ['client_request_id' => $id, 'product_id' => $shelf->id, 'addon_ids' => [], 'qty' => 2, 'prepared' => true];
        $this->postAs($device, 'tables/'.$seat->uuid.'/cancel-bill', $payload)->assertOk()->assertJsonPath('data.lines.1.waste.booked', false);
        $this->assertDatabaseCount('pos_waste_records', 0);
        $this->assertDatabaseCount('pos_stock_movements', 0);
        $this->assertDatabaseCount('pos_product_stock_movements', 0);
        // A later shelf shortage cannot invalidate accepted prepared waste.
        DB::table('pos_branch_product')->where('product_id', $shelf->id)->update(['stock_qty' => -1]);
        $event = ['client_event_id' => (string) Str::uuid(), 'event_type' => 'product.waste', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['table_cancellation_request_id' => $id, 'staff_id' => 7, 'note' => 'T11 prepared shelf',
                'lines' => [['product_id' => $shelf->id, 'qty' => 2, 'reason' => 'other']]]];
        $this->postAs($device, 'sync/push', ['events' => [$event]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->postAs($device, 'sync/push', ['events' => [$event]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $event['client_event_id'] = (string) Str::uuid();
        $this->postAs($device, 'sync/push', ['events' => [$event]])->assertOk()->assertJsonPath('data.results.0.status', 'failed');
        $this->assertDatabaseCount('pos_product_stock_movements', 1);
        $this->assertSame(-3.0, (float) DB::table('pos_branch_product')->value('stock_qty'));
        $this->assertSame(-2.0, (float) DB::table('pos_product_stock_movements')->value('quantity'));
    }

    public static function partBRefusals(): array
    {
        return [['cancel_bill', 'bill_changed'], ['cancel_bill', 'bill_reserved'], ['cancel_line', 'bill_reserved'], ['cancel_bill', 'bill_has_payment'], ['cancel_bill', 'bill_terminal'], ['cancel_bill', 'nothing_to_cancel'], ['cancel_bill', 'cancel_request_conflict']];
    }

    #[DataProvider('partBRefusals')]
    public function test_part_b_sync_refusal_contract(string $kind, string $code): void
    {
        [$device, $seat, $order, $cancel] = $this->fixture();
        $payload = $kind === 'cancel_bill' ? $this->bill($cancel) : $cancel;
        if ($code === 'bill_changed') {
            $payload['lines'][0]['qty'] = 2;
        } elseif ($code === 'bill_has_payment') {
            Payment::query()->create(['uuid' => Str::uuid(), 'order_id' => $order->id, 'method' => 'cash', 'amount' => 1, 'status' => 'success']);
        } elseif ($code === 'bill_terminal') {
            $order->update(['status' => 'void']);
        } elseif ($code === 'nothing_to_cancel') {
            DB::table('pos_qr_order_rounds')->where('order_id', $order->id)->update(['status' => 'rejected']);
        } elseif ($code === 'cancel_request_conflict') {
            $payload['lines'][0]['client_request_id'] = $payload['client_request_id'];
        } else {
            $seat->update(['billing_at' => now()]);
        }
        $before = $this->snapshot();
        $http = $this->postAs($device, 'tables/'.$seat->uuid.'/'.str_replace('_', '-', $kind), $payload)
            ->assertConflict()->assertJsonPath('errors.0.code', $code);
        $sync = $this->postAs($device, 'sync/push', ['events' => [[
            'client_event_id' => $payload['client_request_id'],
            'event_type' => 'table.session.'.$kind,
            'client_timestamp' => now()->toIso8601String(),
            'payload' => $payload,
        ]]])->assertOk()->assertJsonPath('data.results.0.status', 'failed');
        $this->assertSame($before, $this->snapshot());
        // Print only synthetic result data. Device token/request headers excluded.
        fwrite(STDOUT, "\nPART_B_CONTRACT ".json_encode([
            'kind' => $kind, 'expected_code' => $code,
            'http_errors' => $http->json('errors'),
            'http_data' => $http->json('data'),
            'sync_result' => $sync->json('data.results.0'),
        ], JSON_UNESCAPED_SLASHES)."\n");
        $sync->assertJsonPath('data.results.0.result.refusal_code', $code);
        if ($code === 'bill_changed') {
            $this->assertSame($http->json('data.groups'), $sync->json('data.results.0.result.groups'));
        }
    }
}
