<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\AppendQuickQrOrderItemsAction;
use App\Actions\Qr\ListAcceptedDineInQrRoundsAction;
use App\Actions\Qr\LoadQrPricingInputAction;
use App\Actions\Qr\QrCatalogueException;
use App\Actions\Qr\QrChargeException;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\QrPendingTestCase;

final class QrQuickStaffAdditionTest extends QrPendingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('pos_products')->insert([
            'id' => 105, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'Staff water', 'base_price' => '0.500', 'stock_mode' => 'unit',
            'status' => 'active', 'show_on_customer_tablet' => true, 'is_internal' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_branch_product')->insert([
            'branch_id' => 10, 'product_id' => 105, 'is_available' => true,
            'stock_qty' => '5.000', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_taxes')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'VAT',
            'rate_percent' => '5.00', 'is_active' => true, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function attendedSessions(): iterable
    {
        foreach (['fixed_pos', 'handheld'] as $device) {
            foreach (['live', 'timestamp_expired', 'explicit_expired', 'closed', 'missing'] as $session) {
                yield "$device/$session" => [$device, $session];
            }
        }
    }

    #[DataProvider('attendedSessions')]
    public function test_same_bill_append_preserves_customer_lines_identity_session_and_stock(string $type, string $session): void
    {
        $device = $type === 'fixed_pos' ? $this->till : $this->device($type);
        $order = $this->order([], $session);
        $before = $this->raw($order);
        $items = DB::table('pos_order_items')->get()->toArray();
        $sessionBefore = $this->sessionRaw($order);
        $customers = DB::table('pos_customers')->get()->toArray();
        $response = $this->append($order, $this->payload(2), $device)->assertOk()
            ->assertJsonPath('data.order.uuid', $order->uuid)
            ->assertJsonPath('data.order.temp_reference', 'T-0908-001')
            ->assertJsonPath('data.order.status', 'held')
            ->assertJsonPath('data.order.subtotal_baisas', 5750)
            ->assertJsonPath('data.order.tax_total_baisas', 50)
            ->assertJsonPath('data.order.grand_total_baisas', 5800)
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.addition.total_baisas', 1050)
            ->assertJsonCount(2, 'data.order.items');
        $changed = array_flip(['subtotal', 'discount_total', 'tax_total', 'grand_total', 'updated_at']);
        $this->assertSame(array_diff_key($before, $changed), array_diff_key($this->raw($order), $changed));
        $this->assertEquals($items, DB::table('pos_order_items')->whereIn('id', array_column($items, 'id'))->get()->toArray());
        $this->assertSame($sessionBefore, $this->sessionRaw($order));
        $this->assertEquals($customers, DB::table('pos_customers')->get()->toArray());
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseCount('pos_product_stock_movements', 0);
        $round = QrOrderRound::query()->sole();
        $this->assertSame((int) $device->id, (int) $round->resolved_by_device_id);
        $this->assertNull($round->qr_session_id);
        $this->assertNull($round->table_session_id);
        $this->assertNull($round->accepted_seq);
        $this->assertSame('accepted', $round->status);
        $this->assertSame(2, $round->priced_lines[0]['qty']);
        $this->assertSame($response->json('data.order.items.1.id'), $round->priced_lines[0]['order_item_id']);
        $this->assertEquals(5, DB::table('pos_branch_product')->value('stock_qty'));
        $this->getPending()->assertOk()->assertJsonPath('data.orders.0.grand_total_baisas', 5800);
    }

    public function test_retry_never_duplicates_or_reprices_even_after_claim(): void
    {
        $order = $this->order();
        $payload = $this->payload(2);
        $first = $this->append($order, $payload)->assertOk();
        DB::table('pos_products')->where('id', 105)->update(['base_price' => '9.000', 'status' => 'inactive']);
        $this->claim($order)->assertOk()->assertJsonPath('data.charge_amount_baisas', 5800);
        $before = $this->allRows();
        $this->append($order, $payload)->assertOk()->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.addition', $first->json('data.addition'));
        $this->assertSame($before, $this->allRows());
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseCount('pos_order_items', 2);
        $this->assertDatabaseCount('pos_qr_order_rounds', 1);
    }

    public function test_reusing_request_id_for_different_items_is_refused_without_write(): void
    {
        $order = $this->order();
        $payload = $this->payload();
        $this->append($order, $payload)->assertOk();
        $before = $this->allRows();
        $payload['lines'][0]['qty'] = 2;
        $this->append($order, $payload)->assertConflict()->assertJsonPath('errors.0.code', 'idempotency_conflict');
        $this->assertSame($before, $this->allRows());
    }

    public function test_two_staff_additions_accumulate_and_claim_freezes_new_total(): void
    {
        $order = $this->order();
        $this->append($order, $this->payload())->assertOk()->assertJsonPath('data.order.grand_total_baisas', 5275);
        $this->append($order, $this->payload(2), $this->device('handheld'))->assertOk()
            ->assertJsonPath('data.order.grand_total_baisas', 6325)->assertJsonPath('data.addition.round_no', 2);
        $this->claim($order)->assertOk()->assertJsonPath('data.charge_amount_baisas', 6325);
        $before = $this->allRows();
        $this->append($order, $this->payload())->assertConflict()->assertJsonPath('errors.0.code', 'charge_already_claimed');
        $this->assertSame($before, $this->allRows());
    }

    public static function refusedBills(): iterable
    {
        yield 'branch' => [['branch_id' => 20], 'order_not_found', 404];
        yield 'tenant' => [['company_id' => 200], 'order_not_found', 404];
        yield 'dine in' => [['order_type' => 'dine_in'], 'order_not_found', 404];
        yield 'staff order' => [['source' => 'main_pos'], 'order_not_found', 404];
        foreach (['paid', 'void', 'refunded', 'pending_verification', 'open', 'awaiting_payment'] as $status) {
            yield $status => [['status' => $status], 'order_not_editable', 409];
        }
    }

    #[DataProvider('refusedBills')]
    public function test_other_scope_and_non_counter_orders_are_unchanged(array $attributes, string $code, int $status): void
    {
        $order = $this->order($attributes);
        $before = $this->allRows();
        $this->append($order, $this->payload())->assertStatus($status)->assertJsonPath('errors.0.code', $code);
        $this->assertSame($before, $this->allRows());
    }

    public static function chargeCases(): iterable
    {
        foreach (['held', 'awaiting_payment'] as $status) {
            foreach (['live_claim', 'expired_claim', 'uncertain', 'approved', 'declined', 'cancelled', 'lapsed', 'residue'] as $charge) {
                yield "$status/$charge" => [$status, $charge];
            }
        }
    }

    #[DataProvider('chargeCases')]
    public function test_all_charge_provenance_blocks_new_items_without_clearing_any_evidence(string $status, string $charge): void
    {
        $order = $this->order(['status' => $status] + $this->charge($charge));
        $before = $this->allRows();
        $this->append($order, $this->payload())->assertConflict();
        $this->assertSame($before, $this->allRows());
    }

    public function test_existing_safe_counter_move_allows_append_after_decline(): void
    {
        $order = $this->order(['status' => 'awaiting_payment'] + $this->charge('declined'));
        $this->move($order)->assertOk();
        $this->append($order, $this->payload())->assertOk()->assertJsonPath('data.order.grand_total_baisas', 5275);
    }

    public function test_station_and_invalid_token_cannot_append_and_throttle_is_existing_name(): void
    {
        $order = $this->order();
        $before = $this->allRows();
        $this->append($order, $this->payload(), $this->station)->assertConflict()
            ->assertJsonPath('errors.0.code', 'device_not_attended');
        $this->app['auth']->forgetGuards();
        $this->withToken('synthetic-invalid')->postJson($this->url($order), $this->payload())->assertUnauthorized();
        $this->assertSame($before, $this->allRows());
        $this->assertContains('throttle:qr-table-device-write', Route::getRoutes()->getByName('device.qr.pending-orders.items')->gatherMiddleware());
    }

    public function test_device_gate_precedes_any_order_lookup(): void
    {
        $action = app(AppendQuickQrOrderItemsAction::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $action->handle($this->station, 'not-present', $this->payload());
            $this->fail('Station must not edit an order.');
        } catch (QrChargeException $exception) {
            $this->assertSame('device_not_attended', $exception->codeName);
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    }

    public function test_additions_replay_once_and_are_never_refused_on_the_shelf_count(): void
    {
        $order = $this->order();
        DB::table('pos_order_items')->where('order_id', $order->id)->update(['product_id' => 105, 'qty' => '2.000']);
        $payload = $this->payload(3);
        $this->append($order, $payload)->assertOk();
        $before = $this->allRows();
        $this->append($order, $payload)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame($before, $this->allRows());
        // LAUNCH-P2 P2-7 — sell, but warn: past the shelf count is still added.
        $this->append($order, $this->payload())->assertOk();
    }

    public function test_staff_hidden_product_does_not_become_publicly_orderable(): void
    {
        $order = $this->order();
        DB::table('pos_products')->where('id', 105)->update(['show_on_customer_tablet' => false]);
        $this->append($order, $this->payload())->assertOk();
        $this->expectException(QrCatalogueException::class);
        app(LoadQrPricingInputAction::class)->handle(100, 10, $this->payload()['lines']);
    }

    public static function badLines(): iterable
    {
        yield 'zero' => [['qty' => 0], 'validation_failed'];
        yield 'fractional' => [['qty' => 1.5], 'validation_failed'];
        yield 'too many' => [['qty' => 100], 'validation_failed'];
        yield 'product' => [['product_id' => 9999], 'product_unavailable'];
        yield 'addon' => [['addon_ids' => [9999]], 'addon_unavailable'];
        yield 'price' => [['unit_price_baisas' => 1], 'client_priced_payload_rejected'];
        yield 'total' => [['grand_total' => 1], 'client_priced_payload_rejected'];
        yield 'owned line' => [['order_item_id' => 1], 'validation_failed'];
    }

    #[DataProvider('badLines')]
    public function test_invalid_batches_are_atomic_refusals(array $change, string $code): void
    {
        $order = $this->order();
        $payload = $this->payload();
        $payload['lines'][] = array_replace($payload['lines'][0], $change);
        $before = $this->allRows();
        $this->append($order, $payload)->assertUnprocessable()->assertJsonPath('errors.0.code', $code);
        $this->assertSame($before, $this->allRows());
    }

    public function test_frozen_original_discount_and_comp_are_preserved_while_new_addons_discount_and_tax_reconcile(): void
    {
        $order = $this->order([
            'discount_total' => '0.500', 'comp_total' => '0.250',
            'tax_total' => '0.100', 'grand_total' => '4.100',
        ]);
        $original = DB::table('pos_order_discounts')->insertGetId([
            'company_id' => 100, 'branch_id' => 10, 'order_id' => $order->id,
            'name_snapshot' => 'Frozen old discount', 'amount_type_snapshot' => 'fixed',
            'amount' => '0.500', 'applied_at' => now(),
        ]);
        $originalDiscount = (array) DB::table('pos_order_discounts')->where('id', $original)->first();
        $originalItem = (array) DB::table('pos_order_items')->where('order_id', $order->id)->first();
        DB::table('pos_discounts')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'New batch discount',
            'scope' => 'order', 'amount_type' => 'fixed', 'amount' => '0.100',
            'auto_apply' => true, 'status' => 'active', 'requires_manager_approval' => false,
        ]);
        DB::table('pos_addon_groups')->insert([
            'id' => 701, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'Extras', 'is_global' => true, 'status' => 'active',
        ]);
        DB::table('pos_addons')->insert([
            'id' => 702, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'add_on_group_id' => 701, 'name' => 'Lemon', 'price_delta' => '0.200', 'status' => 'active',
        ]);
        $payload = $this->payload(2);
        $payload['lines'][0]['addon_ids'] = [702];
        $payload['lines'][0]['notes'] = 'Extra chilled';
        $this->append($order, $payload)->assertOk()
            ->assertJsonPath('data.order.subtotal_baisas', 6150)
            ->assertJsonPath('data.order.discount_total_baisas', 600)
            ->assertJsonPath('data.order.comp_total_baisas', 250)
            ->assertJsonPath('data.order.tax_total_baisas', 165)
            ->assertJsonPath('data.order.grand_total_baisas', 5465)
            ->assertJsonPath('data.addition.subtotal_baisas', 1400)
            ->assertJsonPath('data.addition.tax_baisas', 65)
            ->assertJsonPath('data.addition.total_baisas', 1365)
            ->assertJsonPath('data.addition.priced_lines.0.addons.0.price_delta_baisas', 200)
            ->assertJsonPath('data.order.items.1.notes', 'Extra chilled');
        $this->assertSame($originalDiscount, (array) DB::table('pos_order_discounts')->where('id', $original)->first());
        $this->assertSame($originalItem, (array) DB::table('pos_order_items')->where('id', $originalItem['id'])->first());
        $this->assertSame(600, (int) round((float) DB::table('pos_order_discounts')->sum('amount') * 1000));
        $this->assertDatabaseCount('pos_order_item_addons', 1);
        $before = $this->allRows();
        DB::table('pos_addons')->where('id', 702)->update(['price_delta' => '7.000']);
        $this->append($order, $payload)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame($before, $this->allRows());
    }

    public function test_internal_foreign_and_unavailable_products_stay_refused_for_staff(): void
    {
        $order = $this->order();
        foreach ([
            ['is_internal' => true], ['company_id' => 200], ['status' => 'inactive'],
        ] as $attributes) {
            DB::table('pos_products')->where('id', 105)->update($attributes + [
                'is_internal' => false, 'company_id' => 100, 'status' => 'active',
            ]);
            $before = $this->allRows();
            $this->append($order, $this->payload())->assertUnprocessable()
                ->assertJsonPath('errors.0.code', 'product_unavailable');
            $this->assertSame($before, $this->allRows());
        }
    }

    public function test_customer_status_keeps_reference_and_updated_total_without_entering_table_print_feed(): void
    {
        $order = $this->order();
        $session = QrSession::query()->findOrFail($order->qr_session_id);
        // Status authentication already touches last_seen_at. Pin it to the
        // frozen clock so the full raw-row assertion isolates bill/feed writes.
        $session->update([
            'client_secret_hash' => QrSession::hashClientSecret('synthetic-quick-staff-secret'),
            'last_seen_at' => now(),
        ]);
        $this->append($order, $this->payload(2))->assertOk();
        $before = $this->allRows();
        $this->withHeaders([
            'X-QR-Session' => $session->uuid,
            'X-QR-Client-Secret' => 'synthetic-quick-staff-secret',
        ])->getJson('/api/v1/public/qr/status')->assertOk()
            ->assertJsonPath('data.order.uuid', $order->uuid)
            ->assertJsonPath('data.order.temp_reference', 'T-0908-001')
            ->assertJsonPath('data.order.grand_total_baisas', 5800)
            ->assertJsonMissingPath('data.dine_in');
        $feed = app(ListAcceptedDineInQrRoundsAction::class)->handle($this->till);
        $this->assertSame([], $feed['rounds']);
        $this->assertSame($before, $this->allRows());
        $this->assertDatabaseCount('pos_payments', 0);
    }

    private function payload(int $quantity = 1): array
    {
        return ['client_request_id' => (string) Str::uuid(), 'lines' => [
            ['product_id' => 105, 'qty' => $quantity, 'addon_ids' => [], 'notes' => null],
        ]];
    }

    private function url(Order $order): string
    {
        return '/api/v1/device/qr/pending-orders/'.$order->uuid.'/items';
    }

    private function append(Order $order, array $payload, ?Device $device = null): TestResponse
    {
        return $this->postAs($device ?? $this->till, $this->url($order), $payload);
    }

    private function allRows(): array
    {
        $rows = $this->snapshot();
        foreach (['pos_qr_order_rounds', 'pos_order_discounts', 'pos_product_stock_movements', 'pos_customers', 'pos_payments'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }
        $rows['stock'] = DB::table('pos_branch_product')->orderBy('product_id')->get()->map(fn ($row): array => (array) $row)->all();

        return $rows;
    }
}
