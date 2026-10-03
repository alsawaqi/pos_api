<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Api\V1\PublicQr\QrStatusController;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class QrStatusControllerTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const PUBLIC_ORDER_FIELDS = [
        'uuid',
        'status',
        'receipt_number',
        'temp_reference',
        'subtotal_baisas',
        'discount_total_baisas',
        'tax_total_baisas',
        'grand_total_baisas',
        // LAUNCH-P4 — whether the tax is inside grand_total (QR web reads it).
        'prices_include_tax',
    ];

    /** @var list<string> */
    private const FORBIDDEN_ORDER_FIELDS = [
        'customer',
        'customer_id',
        'customer_name',
        'name',
        'plate_number',
        'wallet',
        'wallet_balance',
        'loyalty',
        'loyalty_account',
        'loyalty_accounts',
        'loyalty_rule_id',
        'loyalty_rule_ids',
        'loyalty_transactions',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Route::get(
            'api/v1/public/qr/status-test',
            QrStatusController::class,
        )->middleware('qr.session:include-closed');
    }

    private function device(): Device
    {
        return Device::factory()->paired('mdev_qr_status')->create([
            'company_id' => 100,
            'branch_id' => 10,
            'device_type' => 'payment_station',
        ]);
    }

    private function createQrSession(
        Device $device,
        string $secret,
        string $status = QrSession::STATUS_ACTIVE,
    ): QrSession {
        $now = now();

        return QrSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $device->company_id,
            'branch_id' => $device->branch_id,
            'device_id' => $device->getKey(),
            'token' => Str::random(64),
            'token_expires_at' => $now->copy()->addMinute(),
            'client_secret_hash' => QrSession::hashClientSecret($secret),
            'status' => $status,
            'bound_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => $now->copy()->addMinutes(30),
            'closed_at' => $status === QrSession::STATUS_CLOSED ? $now : null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(QrSession $session, array $attributes = []): Order
    {
        return Order::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $session->company_id,
            'branch_id' => $session->branch_id,
            'device_id' => $session->device_id,
            'qr_session_id' => $session->getKey(),
            'customer_id' => 987,
            'order_type' => 'quick',
            'status' => Order::STATUS_HELD,
            'source' => Order::SOURCE_QR_WEB,
            'plate_number' => 'PRIVATE 42',
            'receipt_number' => 'QR-0042',
            'subtotal' => '12.345',
            'discount_total' => '0.345',
            'tax_total' => '0.617',
            'grand_total' => '12.617',
            'opened_at' => now(),
        ], $attributes));
    }

    private function requestStatus(QrSession $session, string $secret): TestResponse
    {
        return $this->withHeaders([
            'X-QR-Session' => $session->uuid,
            'X-QR-Client-Secret' => $secret,
        ])->getJson('/api/v1/public/qr/status-test');
    }

    private function assertPublicOrderShape(TestResponse $response): void
    {
        $order = $response->json('data.order');

        $this->assertIsArray($order);
        $this->assertSame(self::PUBLIC_ORDER_FIELDS, array_keys($order));

        $encoded = json_encode($order, JSON_THROW_ON_ERROR);
        foreach (self::FORBIDDEN_ORDER_FIELDS as $field) {
            $this->assertStringNotContainsString('"'.$field.'":', $encoded);
        }
    }

    public function test_active_session_without_an_order_returns_the_public_empty_shape(): void
    {
        $this->travelTo(Carbon::parse('2026-08-26 12:00:00'));
        $secret = 'active-status-secret';
        $session = $this->createQrSession($this->device(), $secret);

        $this->requestStatus($session, $secret)->assertOk()->assertExactJson([
            'data' => [
                'session_uuid' => $session->uuid,
                'status' => QrSession::STATUS_ACTIVE,
                'expires_at' => $session->expires_at->toIso8601String(),
                'order' => null,
            ],
            'meta' => ['money_unit' => 'baisas'],
            'errors' => [],
        ]);
    }

    public function test_ordered_session_returns_only_the_newest_orders_public_fields(): void
    {
        $this->travelTo(Carbon::parse('2026-08-26 12:00:00'));
        $secret = 'ordered-status-secret';
        $session = $this->createQrSession($this->device(), $secret, QrSession::STATUS_ORDERED);
        $older = $this->order($session, [
            'status' => Order::STATUS_PAID,
            'receipt_number' => 'QR-OLD',
        ]);
        $newest = $this->order($session);

        $response = $this->requestStatus($session, $secret)
            ->assertOk()
            ->assertJsonPath('data.session_uuid', $session->uuid)
            ->assertJsonPath('data.status', QrSession::STATUS_ORDERED)
            ->assertJsonPath('data.order.uuid', $newest->uuid)
            ->assertJsonPath('data.order.status', Order::STATUS_HELD)
            ->assertJsonPath('data.order.receipt_number', 'QR-0042')
            ->assertJsonPath('data.order.subtotal_baisas', 12345)
            ->assertJsonPath('data.order.discount_total_baisas', 345)
            ->assertJsonPath('data.order.tax_total_baisas', 617)
            ->assertJsonPath('data.order.grand_total_baisas', 12617)
            ->assertJsonPath('meta.money_unit', 'baisas');

        $this->assertNotSame($older->uuid, $response->json('data.order.uuid'));
        $this->assertPublicOrderShape($response);
    }

    public function test_paid_order_is_visible_for_a_closed_session_and_wrong_secret_is_404(): void
    {
        $this->travelTo(Carbon::parse('2026-08-26 12:00:00'));
        $secret = 'closed-status-secret';
        $session = $this->createQrSession($this->device(), $secret, QrSession::STATUS_CLOSED);
        $order = $this->order($session, [
            'status' => Order::STATUS_PAID,
            'receipt_number' => 'QR-PAID',
            'closed_at' => now(),
        ]);

        $response = $this->requestStatus($session, $secret)
            ->assertOk()
            ->assertJsonPath('data.status', QrSession::STATUS_CLOSED)
            ->assertJsonPath('data.order.uuid', $order->uuid)
            ->assertJsonPath('data.order.status', Order::STATUS_PAID)
            ->assertJsonPath('data.order.receipt_number', 'QR-PAID')
            ->assertJsonPath('meta.money_unit', 'baisas');
        $this->assertPublicOrderShape($response);

        $this->requestStatus($session, 'wrong-secret')
            ->assertNotFound()
            ->assertExactJson([
                'data' => null,
                'errors' => [[
                    'code' => 'qr_session_not_found',
                    'message' => 'QR session was not found.',
                ]],
            ]);
    }
}
