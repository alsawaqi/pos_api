<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class QrCheckoutPathsTest extends TestCase
{
    use RefreshDatabase;

    private const CHECKOUT_URL = '/api/v1/public/qr/checkout';

    private const ACTIVE_ORDERS_URL = '/api/v1/device/orders/active';

    private const PHONE = '90001234';

    private function createStation(string $token = 'mdev_qr_checkout_station'): Device
    {
        return Device::factory()->paired($token)->create([
            'company_id' => 100,
            'branch_id' => 10,
            'device_type' => 'payment_station',
        ]);
    }

    private function createQrSession(Device $station, string $secret): QrSession
    {
        $now = now();

        return QrSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $station->company_id,
            'branch_id' => $station->branch_id,
            'device_id' => $station->getKey(),
            'token' => hash('sha256', (string) Str::uuid()),
            'token_expires_at' => $now->copy()->addMinute(),
            'client_secret_hash' => QrSession::hashClientSecret($secret),
            'status' => QrSession::STATUS_ACTIVE,
            'bound_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => $now->copy()->addMinutes(30),
        ]);
    }

    private function seedCheckoutProduct(): void
    {
        DB::table('pos_products')->insert([
            'id' => 1,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'category_id' => null,
            'name' => 'Checkout Latte',
            'base_price' => '2.500',
            'stock_mode' => 'untracked',
            'display_order' => 1,
            'status' => 'active',
            'show_on_customer_tablet' => true,
            'is_internal' => false,
            'available_from' => null,
            'available_until' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
    }

    private function enableNumbering(): void
    {
        DB::table('pos_company_settings')->insert([
            'company_id' => 100,
            'key' => 'order_numbering',
            'value' => json_encode([
                'enabled' => true,
                'prefix' => 'QR-',
                'pad' => 4,
                'scope' => 'branch',
                'daily_reset' => false,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function disableNumbering(): void
    {
        DB::table('pos_company_settings')->insert([
            'company_id' => 100,
            'key' => 'order_numbering',
            'value' => json_encode([
                'enabled' => false,
                'prefix' => 'QR-',
                'pad' => 4,
                'scope' => 'branch',
                'daily_reset' => false,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function postCheckout(
        QrSession $session,
        string $secret,
        string $choice,
        string $clientRequestId,
    ): TestResponse {
        return $this->withHeaders([
            'X-QR-Session' => $session->uuid,
            'X-QR-Client-Secret' => $secret,
        ])->postJson(self::CHECKOUT_URL, [
            'client_request_id' => $clientRequestId,
            'checkout_choice' => $choice,
            'phone' => self::PHONE,
            'plate_number' => '12345 A',
            'lines' => [[
                'product_id' => 1,
                'qty' => 1,
                'addon_ids' => [],
                'notes' => null,
            ]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function insertRawOrder(QrSession $session, array $attributes = []): Order
    {
        return Order::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $session->company_id,
            'branch_id' => $session->branch_id,
            'device_id' => $session->device_id,
            'qr_session_id' => $session->getKey(),
            'client_request_id' => (string) Str::uuid(),
            'staff_id' => null,
            'customer_id' => null,
            'table_id' => null,
            'order_type' => 'quick',
            'status' => Order::STATUS_HELD,
            'source' => Order::SOURCE_QR_WEB,
            'subtotal' => '2.500',
            'discount_total' => '0.000',
            'comp_total' => '0.000',
            'tax_total' => '0.000',
            'grand_total' => '2.500',
            'opened_at' => now(),
            'client_event_id' => null,
        ], $attributes));
    }

    public function test_machine_checkout_tolerates_disabled_numbering(): void
    {
        $this->seedCheckoutProduct();
        $this->disableNumbering();
        $station = $this->createStation();
        $secret = 'machine-checkout-secret';
        $session = $this->createQrSession($station, $secret);

        $response = $this->postCheckout($session, $secret, 'machine', 'machine-request-1')
            ->assertCreated()
            ->assertJsonPath('data.order.status', Order::STATUS_AWAITING_PAYMENT)
            ->assertJsonPath('data.order.subtotal_baisas', 2500)
            ->assertJsonPath('data.order.grand_total_baisas', 2500)
            ->assertJsonPath('meta.money_unit', 'baisas');

        $this->assertNull($response->json('data.order.receipt_number'));
        $order = Order::query()->sole();
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->status);
        $this->assertSame(Order::SOURCE_QR_WEB, $order->source);
        $this->assertSame((int) $session->id, (int) $order->qr_session_id);
        $this->assertNull($order->staff_id);
        $this->assertNull($order->receipt_number);
        $this->assertNull($order->client_event_id);
        $this->assertDatabaseCount('pos_order_sequences', 0);
    }

    public function test_machine_and_counter_checkouts_share_one_interleaved_sequence(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedCheckoutProduct();
        $this->enableNumbering();
        $station = $this->createStation();
        $receipts = [];

        for ($number = 1; $number <= 20; $number++) {
            $choice = $number % 2 === 1 ? 'machine' : 'counter';
            $status = $choice === 'machine'
                ? Order::STATUS_AWAITING_PAYMENT
                : Order::STATUS_HELD;
            $secret = 'interleaved-secret-'.$number;
            $session = $this->createQrSession($station, $secret);
            $expectedReceipt = sprintf('QR-%04d', $number);

            $response = $this->postCheckout(
                $session,
                $secret,
                $choice,
                'interleaved-request-'.$number,
            )->assertCreated()
                ->assertJsonPath('data.order.status', $status)
                ->assertJsonPath('data.order.receipt_number', $expectedReceipt);

            $order = Order::query()
                ->where('uuid', $response->json('data.order.uuid'))
                ->sole();
            $this->assertSame($expectedReceipt, $order->receipt_number);
            $receipts[] = $order->receipt_number;
        }

        $expectedReceipts = array_map(
            static fn (int $number): string => sprintf('QR-%04d', $number),
            range(1, 20),
        );
        $this->assertSame($expectedReceipts, $receipts);
        $this->assertCount(20, array_unique($receipts));
        $this->assertSame(10, Order::query()->where('status', Order::STATUS_AWAITING_PAYMENT)->count());
        $this->assertSame(10, Order::query()->where('status', Order::STATUS_HELD)->count());
        $this->assertDatabaseHas('pos_order_sequences', [
            'company_id' => 100,
            'branch_id' => 10,
            'next_number' => 21,
        ]);
    }

    public function test_counter_checkout_allocates_and_returns_the_same_receipt_number(): void
    {
        $this->seedCheckoutProduct();
        $this->enableNumbering();
        $station = $this->createStation();
        $secret = 'counter-numbered-secret';
        $session = $this->createQrSession($station, $secret);

        $response = $this->postCheckout($session, $secret, 'counter', 'counter-numbered-1')
            ->assertCreated()
            ->assertJsonPath('data.order.status', Order::STATUS_HELD)
            ->assertJsonPath('data.order.receipt_number', 'QR-0001');

        $order = Order::query()->sole();
        $this->assertSame(Order::STATUS_HELD, $order->status);
        $this->assertSame('QR-0001', $order->receipt_number);
        $this->assertSame($order->receipt_number, $response->json('data.order.receipt_number'));
        $this->assertDatabaseHas('pos_order_sequences', [
            'company_id' => 100,
            'branch_id' => 10,
            'next_number' => 2,
        ]);
    }

    public function test_counter_checkout_still_writes_when_numbering_is_disabled(): void
    {
        $this->seedCheckoutProduct();
        $this->disableNumbering();
        $station = $this->createStation();
        $secret = 'counter-unnumbered-secret';
        $session = $this->createQrSession($station, $secret);

        $response = $this->postCheckout($session, $secret, 'counter', 'counter-unnumbered-1')
            ->assertCreated()
            ->assertJsonPath('data.order.status', Order::STATUS_HELD);

        $this->assertNull($response->json('data.order.receipt_number'));
        $order = Order::query()->sole();
        $this->assertSame(Order::STATUS_HELD, $order->status);
        $this->assertNull($order->receipt_number);
        $this->assertDatabaseCount('pos_order_sequences', 0);
    }

    public function test_held_qr_order_is_active_with_device_fields_while_awaiting_is_absent(): void
    {
        $this->seedCheckoutProduct();
        $this->enableNumbering();
        $station = $this->createStation();
        Device::factory()->paired('mdev_qr_checkout_till')->create([
            'company_id' => 100,
            'branch_id' => 10,
            'device_type' => 'pos_terminal',
        ]);

        $heldSecret = 'held-active-secret';
        $heldSession = $this->createQrSession($station, $heldSecret);
        $heldResponse = $this->postCheckout($heldSession, $heldSecret, 'counter', 'held-active-1')
            ->assertCreated();
        $heldOrder = Order::query()->where('uuid', $heldResponse->json('data.order.uuid'))->sole();

        $awaitingSecret = 'awaiting-hidden-secret';
        $awaitingSession = $this->createQrSession($station, $awaitingSecret);
        $awaitingResponse = $this->postCheckout(
            $awaitingSession,
            $awaitingSecret,
            'machine',
            'awaiting-hidden-1',
        )->assertCreated();

        app('auth')->forgetGuards();
        $activeResponse = $this->withToken('mdev_qr_checkout_till')
            ->getJson(self::ACTIVE_ORDERS_URL)
            ->assertOk()
            ->assertJsonPath('meta.count', 1);

        $orders = $activeResponse->json('data.orders');
        $this->assertIsArray($orders);
        $this->assertCount(1, $orders);
        $this->assertSame($heldOrder->uuid, $orders[0]['uuid']);
        $this->assertSame(Order::STATUS_HELD, $orders[0]['status']);
        $this->assertSame(Order::SOURCE_QR_WEB, $orders[0]['source']);
        $this->assertSame('QR-0001', $orders[0]['receipt_number']);
        $this->assertSame((int) $heldOrder->customer_id, $orders[0]['customer_id']);
        $this->assertSame('12345 A', $orders[0]['plate_number']);
        $this->assertNotContains(
            $awaitingResponse->json('data.order.uuid'),
            array_column($orders, 'uuid'),
        );
    }

    public function test_partial_unique_rejects_a_second_live_order_then_allows_one_after_terminal(): void
    {
        $station = $this->createStation();
        $session = $this->createQrSession($station, 'partial-unique-secret');
        $first = $this->insertRawOrder($session, [
            'client_request_id' => 'partial-live-1',
            'status' => Order::STATUS_HELD,
        ]);

        try {
            $this->insertRawOrder($session, [
                'client_request_id' => 'partial-live-2',
                'status' => Order::STATUS_AWAITING_PAYMENT,
            ]);
            $this->fail('Expected the live-order partial unique index to reject the second row.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('UNIQUE constraint failed', $exception->getMessage());
        }

        $this->assertDatabaseCount('pos_orders', 1);
        $first->update(['status' => Order::STATUS_PAID, 'closed_at' => now()]);

        $second = $this->insertRawOrder($session, [
            'client_request_id' => 'partial-live-2',
            'status' => Order::STATUS_AWAITING_PAYMENT,
        ]);

        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $second->status);
        $this->assertDatabaseCount('pos_orders', 2);
    }

    public function test_replaying_a_client_request_returns_the_original_response_and_order(): void
    {
        $this->seedCheckoutProduct();
        $this->enableNumbering();
        $station = $this->createStation();
        $secret = 'checkout-replay-secret';
        $session = $this->createQrSession($station, $secret);

        $first = $this->postCheckout($session, $secret, 'machine', 'checkout-replay-1')
            ->assertCreated();
        $replay = $this->postCheckout($session, $secret, 'machine', 'checkout-replay-1')
            ->assertCreated();

        $this->assertSame($first->getContent(), $replay->getContent());
        $this->assertSame($first->json('data.order.uuid'), $replay->json('data.order.uuid'));
        $this->assertSame('QR-0001', $replay->json('data.order.receipt_number'));
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseCount('pos_order_items', 1);
        $this->assertDatabaseHas('pos_order_sequences', [
            'company_id' => 100,
            'branch_id' => 10,
            'seq_date' => null,
            'next_number' => 2,
        ]);
    }

    public function test_client_request_id_is_stored_verbatim(): void
    {
        $this->seedCheckoutProduct();
        $station = $this->createStation();
        $secret = 'verbatim-request-key-secret';
        $session = $this->createQrSession($station, $secret);
        $clientRequestId = '  opaque request key  ';

        $this->postCheckout($session, $secret, 'machine', $clientRequestId)
            ->assertCreated();

        $this->assertSame($clientRequestId, Order::query()->sole()->client_request_id);
    }

    public function test_same_client_request_id_on_different_sessions_is_accepted(): void
    {
        $this->seedCheckoutProduct();
        $station = $this->createStation();
        $firstSecret = 'scoped-request-first-secret';
        $secondSecret = 'scoped-request-second-secret';
        $firstSession = $this->createQrSession($station, $firstSecret);
        $secondSession = $this->createQrSession($station, $secondSecret);

        $first = $this->postCheckout($firstSession, $firstSecret, 'machine', 'shared-request-id')
            ->assertCreated();
        $second = $this->postCheckout($secondSession, $secondSecret, 'machine', 'shared-request-id')
            ->assertCreated();

        $this->assertNotSame($first->json('data.order.uuid'), $second->json('data.order.uuid'));
        $this->assertSame(2, Order::query()->where('client_request_id', 'shared-request-id')->count());
        $this->assertSame(
            [$firstSession->id, $secondSession->id],
            Order::query()
                ->where('client_request_id', 'shared-request-id')
                ->orderBy('id')
                ->pluck('qr_session_id')
                ->all(),
        );
    }
}
