<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\SyncEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\QrPendingTestCase;

final class QrExpiredOrdersCancelTest extends QrPendingTestCase
{
    private int $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = DB::table('pos_staff')->insertGetId(['uuid' => Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'name' => 'Q1 synthetic manager', 'pin_hash' => Hash::make('4321'), 'position' => 'manager', 'status' => 'active']);
    }

    private function preview(?Order $order = null)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($this->till->device_token)->getJson('/api/v1/device/qr/pending-orders/cancel-preview'.($order ? '?order_uuid='.$order->uuid : ''));
    }

    private function input(array $preview): array
    {
        return ['client_request_id' => (string) Str::uuid(), 'preview_token' => $preview['preview_token'], 'pin' => '4321',
            'reason' => 'Q1 synthetic expired order cleanup', 'prepared_order_uuids' => []];
    }

    private function cancel(array $input, $device = null)
    {
        return $this->postAs($device ?? $this->till, '/api/v1/device/qr/pending-orders/cancel', $input);
    }

    public function test_cancel_one_is_audited_idempotent_and_does_not_take_payment(): void
    {
        $order = $this->order([], 'closed');
        $preview = $this->preview($order)->assertOk()->assertJsonPath('data.count', 1)->assertJsonPath('data.total_baisas', 4750)->json('data');
        $input = $this->input($preview);
        $this->cancel($input)->assertOk()->assertJsonPath('data.orders.0.status', 'void')->assertJsonPath('data.replayed', false);
        $after = $this->snapshot();
        $this->cancel($input)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame($after, $this->snapshot());
        $this->assertSame('void', $order->fresh()->status);
        $this->assertDatabaseCount('pos_payments', 0);
        $audit = SyncEvent::query()->sole();
        $this->assertSame('qr.quick.cancel_expired', $audit->event_type);
        $this->assertSame($this->manager, $audit->result_json['approved_by_staff_id']);
        $this->assertArrayNotHasKey('pin', $audit->payload_json);
        $this->getPending()->assertOk()->assertJsonCount(0, 'data.orders');
        $input['reason'] = 'different';
        $this->cancel($input)->assertConflict()->assertJsonPath('errors.0.code', 'idempotency_conflict');
    }

    public function test_bulk_preview_covers_more_than_list_limit_and_never_touches_active_or_foreign_orders(): void
    {
        $expired = [];
        for ($i = 0; $i < 201; $i++) {
            $expired[] = $this->order([], $i % 2 === 0 ? 'closed' : 'timestamp_expired');
        }
        $active = $this->order();
        $foreign = $this->order(['branch_id' => 20], 'closed');
        $paid = $this->order(['status' => 'paid'], 'closed');
        $this->getPending()->assertOk()->assertJsonCount(200, 'data.orders');
        $preview = $this->preview()->assertOk()->assertJsonPath('data.count', 201)->assertJsonPath('data.total_baisas', 954750)->json('data');
        // A newly expired order was not in the one confirmed snapshot.
        $late = $this->order([], 'closed');
        $this->cancel($this->input($preview))->assertOk()->assertJsonPath('data.count', 201);
        $this->assertSame(201, Order::query()->where('status', 'void')->count());
        foreach ([$active, $foreign, $late] as $order) {
            $this->assertSame('held', $order->fresh()->status);
        }
        $this->assertSame('paid', $paid->fresh()->status);
        $this->assertDatabaseCount('pos_sync_events', 1);
    }

    public function test_active_paid_missing_scope_and_bad_manager_are_refused_without_changes(): void
    {
        $active = $this->order();
        $paid = $this->order(['status' => 'paid'], 'closed');
        $this->preview($active)->assertConflict()->assertJsonPath('errors.0.code', 'qr_session_active');
        $this->preview($paid)->assertConflict()->assertJsonPath('errors.0.code', 'void_bill_not_unpaid');
        $foreign = $this->order(['company_id' => 200], 'closed');
        $this->preview($foreign)->assertNotFound();
        $expired = $this->order([], 'closed');
        $input = $this->input($this->preview($expired)->assertOk()->json('data'));
        $input['pin'] = '9999';
        $before = $this->snapshot();
        $this->cancel($input)->assertUnauthorized()->assertJsonPath('errors.0.code', 'invalid_pin');
        $this->assertSame($before, $this->snapshot());
        $this->cancel($input, $this->station)->assertConflict()->assertJsonPath('errors.0.code', 'device_not_attended');
        $this->assertDatabaseCount('pos_sync_events', 0);
    }

    public function test_batch_is_atomic_when_session_reactivates_bill_changes_or_payment_claim_appears(): void
    {
        $first = $this->order([], 'closed');
        $second = $this->order([], 'closed');
        $input = $this->input($this->preview()->assertOk()->json('data'));
        QrSession::whereKey($second->qr_session_id)->update(['status' => 'ordered', 'expires_at' => now()->addHour()]);
        $this->cancel($input)->assertConflict()->assertJsonPath('errors.0.code', 'qr_session_active');
        $this->assertSame('held', $first->fresh()->status);
        QrSession::whereKey($second->qr_session_id)->update(['status' => 'closed']);
        $second->update(['grand_total' => '5.000']);
        $this->cancel($input)->assertConflict()->assertJsonPath('errors.0.code', 'void_preview_changed');
        $second->update(['grand_total' => '4.750', 'charge_outcome' => 'uncertain']);
        $this->cancel($input)->assertConflict()->assertJsonPath('errors.0.code', 'qr_charge_recovery_required');
        $this->assertDatabaseCount('pos_sync_events', 0);
        $this->assertSame('held', $first->fresh()->status);
    }

    public function test_printed_ingredient_items_follow_frozen_wastage_and_retry_never_books_twice(): void
    {
        $order = $this->order([], 'closed');
        $ingredient = DB::table('pos_ingredients')->insertGetId(['uuid' => Str::uuid(), 'company_id' => 100, 'name' => 'Q1 flour', 'unit' => 'kg', 'default_unit_cost' => 99]);
        DB::table('pos_branch_stock')->insert(['branch_id' => 10, 'ingredient_id' => $ingredient, 'quantity' => 1]);
        $item = $order->items()->sole();
        $item->update(['recipe_snapshot_json' => [['ingredient_id' => $ingredient, 'qty' => .2, 'unit' => 'kg', 'unit_cost' => 3]]]);

        $unsent = $item->replicate();
        $unsent->qty = 2;
        $unsent->save();
        QrOrderRound::create(['qr_session_id' => $order->qr_session_id, 'order_id' => $order->id,
            'client_request_id' => Str::uuid(), 'round_no' => 1, 'status' => 'accepted', 'submitted_at' => now(),
            'kitchen_printed_at' => now(), 'priced_lines' => [['order_item_id' => $item->id, 'qty' => 1]],
            'subtotal_baisas' => 4750, 'tax_baisas' => 0, 'total_baisas' => 4750]);
        $preview = $this->preview($order)->assertOk()->assertJsonPath('data.orders.0.prepared', true)->json('data');
        $input = $this->input($preview);
        $this->cancel($input)->assertOk()->assertJsonPath('data.orders.0.waste.ingredient_cost_baisas', 600);
        $this->cancel($input)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertDatabaseCount('pos_waste_records', 1);
        $this->assertDatabaseCount('pos_stock_movements', 1);
        $this->assertSame(.8, (float) DB::table('pos_branch_stock')->value('quantity'));

        $this->assertSame(.2, (float) DB::table('pos_waste_records')->value('quantity'));
        $this->assertSame([(int) $item->id], SyncEvent::query()->sole()->result_json['orders'][0]['waste']['prepared_item_ids']);
        $this->assertDatabaseCount('pos_payments', 0);
    }

    public function test_preview_expiry_wrong_scope_and_paid_after_preview_keep_every_row_unchanged(): void
    {
        $order = $this->order([], 'closed');
        $input = $this->input($this->preview($order)->assertOk()->json('data'));
        $other = $this->device('fixed_pos', 20);
        $this->cancel($input, $other)->assertConflict()->assertJsonPath('errors.0.code', 'void_preview_changed');
        $order->update(['status' => 'paid']);
        $this->cancel($input)->assertConflict()->assertJsonPath('errors.0.code', 'void_bill_not_unpaid');
        $order->update(['status' => 'held']);
        $this->travel(6)->minutes();
        $this->cancel($input)->assertConflict()->assertJsonPath('errors.0.code', 'void_preview_changed');
        $this->assertSame('held', $order->fresh()->status);
        $this->assertDatabaseCount('pos_sync_events', 0);
        $this->assertDatabaseCount('pos_payments', 0);
    }

    public function test_same_request_after_device_reassignment_does_not_leak_or_replay_other_branch_audit(): void
    {
        $order = $this->order([], 'closed');
        $input = $this->input($this->preview($order)->assertOk()->json('data'));
        $this->cancel($input)->assertOk();
        $this->till->forceFill(['branch_id' => 20])->save();
        $this->assertSame(20, (int) $this->till->fresh()->branch_id);
        $this->cancel($input)->assertConflict()->assertJsonPath('errors.0.code', 'idempotency_conflict');
        $this->assertDatabaseCount('pos_sync_events', 1);
    }

    public function test_manager_confirmed_prepared_shelf_waste_allows_shortage_once_using_existing_domain(): void
    {
        $order = $this->order([], 'closed');
        $product = DB::table('pos_products')->insertGetId(['uuid' => Str::uuid(), 'company_id' => 100,
            'name' => 'Q1 shelf item', 'base_price' => '4.750', 'stock_mode' => 'unit', 'cost_price' => '.200']);
        DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => $product, 'stock_qty' => -2, 'is_available' => true]);
        $order->items()->update(['product_id' => $product]);
        $input = $this->input($this->preview($order)->assertOk()->json('data'));
        $input['prepared_order_uuids'] = [$order->uuid];
        $this->cancel($input)->assertOk()->assertJsonPath('data.orders.0.waste.shelf_lines', 1);
        $this->cancel($input)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertDatabaseCount('pos_product_stock_movements', 1);
        $this->assertSame(-3.0, (float) DB::table('pos_branch_product')->value('stock_qty'));
        $this->assertSame(-1.0, (float) DB::table('pos_product_stock_movements')->value('quantity'));
        $this->assertDatabaseCount('pos_payments', 0);
    }
}
