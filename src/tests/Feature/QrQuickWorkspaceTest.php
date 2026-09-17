<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\ListStationQrAwaitingOrdersAction;
use App\Models\Order;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\QrPendingTestCase;

final class QrQuickWorkspaceTest extends QrPendingTestCase
{
    public function test_grouped_quantity_and_delete_are_atomic_and_replayable(): void
    {
        $order = $this->order(['subtotal' => '9.500', 'grand_total' => '9.500']);
        $first = $order->items()->first();
        $second = $first->replicate();
        $second->save();
        $payload = $this->payload('quantity', ['item_id' => $first->id, 'item_ids' => [$first->id, $second->id], 'qty' => 1]);
        $this->change($order, $payload)->assertOk()->assertJsonPath('data.order.grand_total_baisas', 4750);
        $this->assertSame(1.0, (float) $order->items()->sum('qty'));
        $before = $this->snapshot();
        $this->change($order, $payload)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame($before, $this->snapshot());
        $this->change($order, $this->payload('quantity', ['item_id' => $first->id, 'item_ids' => [$first->id, $second->id], 'qty' => 0]))
            ->assertOk()->assertJsonPath('data.order.grand_total_baisas', 0);
        $this->assertSame(0.0, (float) $order->items()->sum('qty'));
        $this->assertDatabaseCount('pos_order_items', 2);
    }

    public function test_grouped_replace_reprices_once_and_rolls_back_all_lines_on_refusal(): void
    {
        $order = $this->order(['subtotal' => '9.500', 'grand_total' => '9.500']);
        $first = $order->items()->first();
        $second = $first->replicate();
        $second->save();
        $selection = ['item_id' => $first->id, 'item_ids' => [$first->id, $second->id]];
        $before = $this->snapshot();
        $this->change($order, $this->payload('replace', $selection + ['lines' => [['product_id' => 99999, 'qty' => 2, 'addon_ids' => []]]]))->assertStatus(422);
        $this->assertSame($before, $this->snapshot());
        DB::table('pos_products')->insert(['id' => 105, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'Water', 'base_price' => '0.500', 'stock_mode' => 'unit', 'status' => 'active',
            'show_on_customer_tablet' => true, 'is_internal' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => 105, 'is_available' => true,
            'stock_qty' => '5.000', 'created_at' => now(), 'updated_at' => now()]);
        $payload = $this->payload('replace', $selection + ['lines' => [['product_id' => 105, 'qty' => 2, 'addon_ids' => [], 'notes' => 'Cold']]]);
        $this->change($order, $payload)->assertOk()->assertJsonPath('data.order.grand_total_baisas', 1000);
        $before = $this->snapshot();
        $this->change($order, $payload)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, $order->items()->where('qty', '>', 0)->count());
        $this->assertDatabaseCount('pos_order_items', 3);
    }

    public function test_grouped_edit_rejects_foreign_and_duplicate_ids_without_mutation(): void
    {
        $order = $this->order();
        $item = $order->items()->first();
        $before = $this->snapshot();
        $this->change($order, $this->payload('quantity', ['item_id' => $item->id, 'item_ids' => [$item->id, 99999], 'qty' => 0]))->assertConflict();
        $this->assertSame($before, $this->snapshot());
        $this->change($order, $this->payload('quantity', ['item_id' => $item->id, 'item_ids' => [$item->id, $item->id], 'qty' => 0]))->assertStatus(422);
        $this->assertSame($before, $this->snapshot());
    }

    private function payload(string $operation, array $extra = []): array
    {
        $this->app['auth']->forgetGuards();
        $row = $this->withToken((string) $this->till->device_token)->getJson('/api/v1/device/qr/pending-orders?workspace=1')->assertOk()->json('data.orders.0');

        return $extra + ['operation' => $operation, 'client_request_id' => (string) Str::uuid(),
            'revision' => $row['edit_revision'] ?? str_repeat('0', 64)];
    }

    private function change(Order $order, array $payload)
    {
        return $this->postAs($this->till, '/api/v1/device/qr/pending-orders/'.$order->uuid.'/workspace', $payload);
    }

    public function test_clear_preserves_order_identity_keeps_audit_and_allows_new_items(): void
    {
        $order = $this->order();
        $payload = $this->payload('clear');
        $this->change($order, $payload)->assertOk()->assertJsonPath('data.order.grand_total_baisas', 0)
            ->assertJsonPath('data.order.temp_reference', 'T-0908-001')->assertJsonPath('data.order.actions.settle', false);
        $this->assertSame('held', $order->fresh()->status);
        $this->assertDatabaseHas('pos_order_items', ['order_id' => $order->id, 'qty' => 0, 'status' => 'void']);
        $this->assertDatabaseCount('pos_product_stock_movements', 0);
        $this->assertDatabaseCount('pos_sync_events', 1);
        $before = $this->snapshot();
        $this->change($order, $payload)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_quantity_reduction_uses_frozen_amounts_and_stale_edit_is_refused(): void
    {
        $order = $this->order(['subtotal' => '9.500', 'grand_total' => '9.500']);
        $item = $order->items()->first();
        $item->update(['qty' => 2, 'line_total' => '9.500']);
        $payload = $this->payload('quantity', ['item_id' => $item->id, 'qty' => 1]);
        $this->change($order, $payload)->assertOk()->assertJsonPath('data.order.grand_total_baisas', 4750);
        $before = $this->snapshot();
        $payload['client_request_id'] = (string) Str::uuid();
        $this->change($order, $payload)->assertConflict()->assertJsonPath('errors.0.code', 'order_changed');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_replace_is_server_priced_same_bill_and_retry_does_not_duplicate(): void
    {
        $order = $this->order();
        DB::table('pos_products')->insert(['id' => 105, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'Water', 'base_price' => '0.500', 'stock_mode' => 'unit', 'status' => 'active',
            'show_on_customer_tablet' => true, 'is_internal' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => 105, 'is_available' => true,
            'stock_qty' => '5.000', 'created_at' => now(), 'updated_at' => now()]);
        $payload = $this->payload('replace', ['item_id' => $order->items()->first()->id,
            'lines' => [['product_id' => 105, 'qty' => 2, 'addon_ids' => [], 'notes' => 'Cold']]]);
        $this->change($order, $payload)->assertOk()->assertJsonPath('data.order.grand_total_baisas', 1000);
        $this->change($order, $payload)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseCount('pos_order_items', 2);
        $this->assertDatabaseCount('pos_product_stock_movements', 0);
        $this->assertDatabaseHas('pos_order_items', ['order_id' => $order->id, 'product_id' => 105, 'qty' => 2, 'notes' => 'Cold']);
    }

    public function test_transferred_summary_excludes_removed_audit_lines(): void
    {
        $order = $this->order();
        $removed = $order->items()->first()->replicate();
        $removed->qty = 0;
        $removed->status = 'void';
        $removed->line_total = '0.000';
        $removed->save();
        $target = $this->device('payment_station');
        $this->change($order, $this->payload('transfer', ['target_device_id' => $target->id]))->assertOk();
        $this->assertSame(1, app(ListStationQrAwaitingOrdersAction::class)->handle($target)[0]['item_count']);
        $this->assertDatabaseCount('pos_order_items', 2);
    }

    public function test_transfer_to_chosen_station_preserves_bill_and_admits_only_that_station(): void
    {
        $order = $this->order([], 'explicit_expired');
        $target = $this->device('payment_station');
        $before = $this->snapshot();
        $payload = $this->payload('transfer', ['target_device_id' => $target->id]);
        $this->change($order, $payload)->assertOk()->assertJsonPath('data.order.grand_total_baisas', 4750);
        $this->assertSame($before['pos_order_items'], $this->snapshot()['pos_order_items']);
        $this->assertSame($before['pos_qr_sessions'], $this->snapshot()['pos_qr_sessions']);
        $this->assertSame([], app(ListStationQrAwaitingOrdersAction::class)->handle($this->station));
        $this->assertSame($order->uuid, app(ListStationQrAwaitingOrdersAction::class)->handle($target)[0]['order_uuid']);
        $this->postAs($this->station, '/api/v1/device/qr/claim-charge', ['order_uuid' => $order->uuid])->assertConflict();
        $this->postAs($target, '/api/v1/device/qr/claim-charge', ['order_uuid' => $order->uuid])->assertOk()
            ->assertJsonPath('data.charge_amount_baisas', 4750);
        $this->change($order, $payload)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertDatabaseCount('pos_payments', 0);
        $this->postAs($target, '/api/v1/device/qr/release-charge', ['order_uuid' => $order->uuid, 'outcome' => 'cancelled'])->assertOk();
        $this->postAs($target, '/api/v1/device/qr/orders/'.$order->uuid.'/payment-recovery', ['action' => 'counter'])->assertOk()
            ->assertJsonPath('data.state', 'counter');
        $this->assertNull($order->fresh()->transferred_to_device_id);
        $this->assertNull($order->fresh()->charge_claimed_at);
        $this->getPending()->assertOk()->assertJsonPath('data.orders.0.actions.settle', true);
    }

    public function test_handheld_receives_same_qr_bill_and_other_device_cannot_take_it(): void
    {
        $order = $this->order();
        $target = $this->device('handheld');
        $this->change($order, $this->payload('transfer', ['target_device_id' => $target->id]))->assertOk();
        $this->claim($order)->assertConflict();
        $this->postAs($target, '/api/v1/device/transfers/'.$order->uuid.'/claim')->assertOk()
            ->assertJsonPath('data.order.source', 'qr_web')->assertJsonPath('data.order.grand_total_baisas', 4750);
        $this->claim($order, $target)->assertOk()->assertJsonPath('data.charge_amount_baisas', 4750);
    }

    public function test_cross_branch_target_and_changed_request_and_live_claim_cannot_mutate(): void
    {
        $order = $this->order();
        $other = $this->device('payment_station', 20);
        $before = $this->snapshot();
        $this->change($order, $this->payload('transfer', ['target_device_id' => $other->id]))->assertConflict();
        $this->assertSame($before, $this->snapshot());
        $payload = $this->payload('clear');
        $this->claim($order)->assertOk();
        $before = $this->snapshot();
        $this->change($order, $payload)->assertConflict();
        $this->assertSame($before, $this->snapshot());
    }
}
