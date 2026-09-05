<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QrSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class DeviceQrAwaitingOrdersTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/device/qr/awaiting-orders';

    private const PUBLIC_STATUS_URL = '/api/v1/public/qr/status';

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function device(
        string $token,
        int $branchId = 10,
        string $type = 'payment_station',
        array $attributes = [],
    ): Device {
        return Device::factory()->paired($token)->create($attributes + [
            'company_id' => 100,
            'branch_id' => $branchId,
            'device_type' => $type,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function qrSession(Device $device, array $attributes = []): QrSession
    {
        return QrSession::query()->create($attributes + [
            'uuid' => (string) Str::uuid(),
            'company_id' => $device->company_id,
            'branch_id' => $device->branch_id,
            'device_id' => $device->getKey(),
            'token' => hash('sha256', (string) Str::uuid()),
            'token_expires_at' => now()->addMinute(),
            'status' => QrSession::STATUS_ORDERED,
            'bound_at' => now(),
            'last_seen_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(QrSession $session, array $attributes = []): Order
    {
        return Order::query()->create($attributes + [
            'uuid' => (string) Str::uuid(),
            'company_id' => $session->company_id,
            'branch_id' => $session->branch_id,
            'device_id' => $session->device_id,
            'qr_session_id' => $session->getKey(),
            'client_request_id' => (string) Str::uuid(),
            'staff_id' => null,
            'customer_id' => 987,
            'table_id' => null,
            'order_type' => 'quick',
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'source' => Order::SOURCE_QR_WEB,
            'plate_number' => 'PRIVATE 42',
            'subtotal' => '4.750',
            'discount_total' => '0.000',
            'comp_total' => '0.000',
            'tax_total' => '0.000',
            'grand_total' => '4.750',
            'opened_at' => now(),
            'note' => 'Private customer note',
        ]);
    }

    private function addItem(Order $order, string $name): void
    {
        OrderItem::query()->create([
            'order_id' => $order->getKey(),
            'product_id' => null,
            'product_name_snapshot' => $name,
            'qty' => '1.000',
            'unit_price_snapshot' => '2.375',
            'line_discount' => '0.000',
            'line_total' => '2.375',
            'status' => OrderItem::STATUS_OPEN,
        ]);
    }

    private function getAs(Device $device): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken((string) $device->device_token)->getJson(self::URL);
    }

    private function claimAs(Device $device, string $orderUuid): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken((string) $device->device_token)
            ->postJson('/api/v1/device/qr/claim-charge', [
                'order_uuid' => $orderUuid,
            ]);
    }

    public function test_station_receives_its_order_and_returned_uuid_claims_unchanged(): void
    {
        $this->travelTo(Carbon::parse('2026-08-28 09:15:00'));
        $station = $this->device('station-read-compose');
        $secret = 'station-read-public-status-secret';
        $session = $this->qrSession($station, [
            'client_secret_hash' => QrSession::hashClientSecret($secret),
        ]);
        $order = $this->order($session, ['receipt_number' => 'QR-0042']);
        $this->addItem($order, 'Private product one');
        $this->addItem($order, 'Private product two');

        $response = $this->getAs($station)->assertOk()->assertExactJson([
            'data' => [
                'orders' => [[
                    'session_uuid' => $session->uuid,
                    'order_uuid' => $order->uuid,
                    'receipt_number' => 'QR-0042',
                    'temp_reference' => null,
                    'status' => Order::STATUS_AWAITING_PAYMENT,
                    'amount_baisas' => 4750,
                    'item_count' => 2,
                    'opened_at' => now()->toIso8601String(),
                ]],
            ],
            'meta' => [
                'generated_at' => now()->toIso8601String(),
                'money_unit' => 'baisas',
            ],
            'errors' => [],
        ]);

        $listed = $response->json('data.orders.0');
        $this->assertSame([
            'session_uuid',
            'order_uuid',
            'receipt_number',
            'temp_reference',
            'status',
            'amount_baisas',
            'item_count',
            'opened_at',
        ], array_keys($listed));

        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        foreach ([
            'public_ref',
            'customer_id',
            'customer_name',
            'plate_number',
            'PRIVATE 42',
            'Private customer note',
            'Private product one',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }

        $publicStatus = $this->withHeaders([
            'X-QR-Session' => $session->uuid,
            'X-QR-Client-Secret' => $secret,
        ])->getJson(self::PUBLIC_STATUS_URL)
            ->assertOk()
            ->assertJsonPath('data.order.receipt_number', 'QR-0042');
        $this->assertSame(
            $publicStatus->json('data.order.receipt_number'),
            $listed['receipt_number'],
        );

        $returnedOrderUuid = (string) $listed['order_uuid'];
        $this->assertSame($order->uuid, $returnedOrderUuid);
        $this->claimAs($station, $returnedOrderUuid)
            ->assertOk()
            ->assertJsonPath('data.order_uuid', $returnedOrderUuid)
            ->assertJsonPath('data.charge_amount_baisas', 4750)
            ->assertJsonPath('data.roundup_amount_baisas', 0)
            ->assertJsonPath('data.softpos_amount_baisas', 4750);
    }

    public function test_station_cannot_discover_or_claim_another_device_or_branch_order(): void
    {
        $station = $this->device('station-read-owner');
        $owned = $this->order($this->qrSession($station));

        $otherStation = $this->device('station-read-other-device');
        $otherDeviceOrder = $this->order($this->qrSession($otherStation));

        $otherBranchStation = $this->device('station-read-other-branch', 20);
        $otherBranchOrder = $this->order($this->qrSession($otherBranchStation));

        $response = $this->getAs($station)
            ->assertOk()
            ->assertJsonCount(1, 'data.orders')
            ->assertJsonPath('data.orders.0.order_uuid', $owned->uuid);

        $listedUuids = collect($response->json('data.orders'))->pluck('order_uuid')->all();
        $this->assertNotContains($otherDeviceOrder->uuid, $listedUuids);
        $this->assertNotContains($otherBranchOrder->uuid, $listedUuids);

        $this->claimAs($station, (string) $otherDeviceOrder->uuid)
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'order_not_bound_to_device_session');
        $this->claimAs($station, (string) $otherBranchOrder->uuid)
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'order_not_found');
    }

    public function test_poll_preserves_the_full_live_claim_eligibility_matrix(): void
    {
        $station = $this->device('station-read-outcomes');
        $unclaimed = $this->order($this->qrSession($station));
        $inFlight = $this->order($this->qrSession($station), [
            'charge_device_id' => $station->getKey(),
            'charge_amount_baisas' => 4750,
            'charge_claimed_at' => now(),
            'charge_deadline_at' => now()->addMinutes(3),
            'charge_outcome' => null,
        ]);
        $lapsed = $this->order($this->qrSession($station), [
            'charge_device_id' => $station->getKey(),
            'charge_amount_baisas' => 4750,
            'charge_claimed_at' => now()->subMinutes(5),
            'charge_deadline_at' => now()->subMinutes(2),
            'charge_outcome' => Order::CHARGE_OUTCOME_LAPSED,
        ]);
        $uncertain = $this->order($this->qrSession($station), [
            'charge_device_id' => $station->getKey(),
            'charge_amount_baisas' => 4750,
            'charge_claimed_at' => now(),
            'charge_deadline_at' => now()->addMinutes(3),
            'charge_outcome' => Order::CHARGE_OUTCOME_UNCERTAIN,
        ]);
        $approved = $this->order($this->qrSession($station), [
            'charge_device_id' => $station->getKey(),
            'charge_amount_baisas' => 4750,
            'charge_claimed_at' => now(),
            'charge_deadline_at' => now()->addMinutes(3),
            'charge_outcome' => Order::CHARGE_OUTCOME_APPROVED,
        ]);
        $declined = $this->order($this->qrSession($station), [
            'charge_device_id' => $station->getKey(),
            'charge_amount_baisas' => 4750,
            'charge_claimed_at' => now(),
            'charge_deadline_at' => now()->addMinutes(3),
            'charge_outcome' => Order::CHARGE_OUTCOME_DECLINED,
        ]);
        $cancelled = $this->order($this->qrSession($station), [
            'charge_device_id' => $station->getKey(),
            'charge_amount_baisas' => 4750,
            'charge_claimed_at' => now(),
            'charge_deadline_at' => now()->addMinutes(3),
            'charge_outcome' => Order::CHARGE_OUTCOME_CANCELLED,
        ]);

        $listedOrders = $this->getAs($station)
            ->assertOk()
            ->assertJsonCount(3, 'data.orders')
            ->json('data.orders');
        $this->assertIsArray($listedOrders);
        foreach ($listedOrders as $listedOrder) {
            $this->assertArrayHasKey('receipt_number', $listedOrder);
            $this->assertNull($listedOrder['receipt_number']);
        }
        $listedUuids = collect($listedOrders)->pluck('order_uuid')->all();

        $this->assertSame([$unclaimed->uuid, $declined->uuid, $cancelled->uuid], $listedUuids);
        $this->assertNotContains($inFlight->uuid, $listedUuids);
        $this->assertNotContains($lapsed->uuid, $listedUuids);
        $this->assertNotContains($uncertain->uuid, $listedUuids);
        $this->assertNotContains($approved->uuid, $listedUuids);

        $this->claimAs($station, (string) $unclaimed->uuid)->assertOk();
        $this->claimAs($station, (string) $declined->uuid)->assertOk();
        $this->claimAs($station, (string) $cancelled->uuid)->assertOk();
    }

    public function test_only_an_authenticated_assigned_payment_station_can_poll(): void
    {
        $this->getJson(self::URL)->assertUnauthorized();

        $blocked = $this->device('station-read-blocked', attributes: ['status' => 'blocked']);
        $this->getAs($blocked)->assertUnauthorized();

        $terminal = $this->device('station-read-terminal', type: 'pos_terminal');
        $this->getAs($terminal)
            ->assertStatus(409)
            ->assertExactJson([
                'data' => null,
                'errors' => [[
                    'code' => 'device_not_payment_station',
                    'message' => 'Only a payment station may read awaiting QR orders.',
                ]],
            ]);

        $unassigned = Device::factory()
            ->paired('station-read-unassigned')
            ->unassigned()
            ->create(['device_type' => 'payment_station']);
        $this->getAs($unassigned)
            ->assertStatus(409)
            ->assertExactJson([
                'data' => null,
                'errors' => [[
                    'code' => 'device_unassigned',
                    'message' => 'This device is not assigned to a branch.',
                ]],
            ]);

        $assigned = $this->device('station-read-assigned', attributes: [
            'status' => 'assigned',
        ]);
        $this->getAs($assigned)
            ->assertStatus(409)
            ->assertExactJson([
                'data' => null,
                'errors' => [[
                    'code' => 'device_not_active',
                    'message' => 'This payment station is not active.',
                ]],
            ]);
    }

    public function test_route_has_the_named_limiter_and_the_budget_is_per_station(): void
    {
        $route = Route::getRoutes()->getByName('device.qr.awaiting-orders');
        $this->assertNotNull($route);
        $this->assertSame('api/v1/device/qr/awaiting-orders', $route->uri());
        $this->assertContains('GET', $route->methods());

        $middleware = $route->gatherMiddleware();
        $this->assertContains('auth:pos_device', $middleware);
        $this->assertContains('throttle:device-api', $middleware);
        $this->assertContains('throttle:qr-station-read', $middleware);
        $this->assertSame([
            'throttle:qr-station-read',
        ], array_values(array_filter(
            $middleware,
            static fn (string $name): bool => str_starts_with($name, 'throttle:qr-'),
        )));

        $first = $this->device('station-read-limiter-first');
        $second = $this->device('station-read-limiter-second');

        for ($attempt = 1; $attempt <= 60; $attempt++) {
            $this->getAs($first)->assertOk();
        }

        $this->getAs($first)
            ->assertStatus(429)
            ->assertExactJson([
                'data' => null,
                'errors' => [[
                    'code' => 'rate_limited',
                    'message' => 'Too many requests.',
                ]],
            ]);

        $this->getAs($second)->assertOk();
    }
}
