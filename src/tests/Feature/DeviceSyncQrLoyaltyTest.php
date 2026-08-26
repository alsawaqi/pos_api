<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\LoyaltyAccount;
use App\Models\Order;
use App\Models\QrSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class DeviceSyncQrLoyaltyTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'mdev_qr_loyalty';

    private function seedDomain(): Device
    {
        $device = Device::factory()->paired(self::TOKEN)->create([
            'company_id' => 100,
            'branch_id' => 10,
        ]);
        $timestamps = ['created_at' => now(), 'updated_at' => now()];

        DB::table('pos_products')->insert([
            'id' => 1,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Latte',
            'base_price' => 3.000,
            'status' => 'active',
        ] + $timestamps);
        DB::table('pos_customers')->insert([
            [
                'id' => 1,
                'uuid' => (string) Str::uuid(),
                'company_id' => 100,
                'name' => 'QR Customer',
                'phone' => '+96890000001',
                'wallet_balance' => 0,
            ] + $timestamps,
            [
                'id' => 2,
                'uuid' => (string) Str::uuid(),
                'company_id' => 100,
                'name' => 'Cashier Customer',
                'phone' => '+96890000002',
                'wallet_balance' => 0,
            ] + $timestamps,
        ]);
        DB::table('pos_loyalty_rules')->insert([
            [
                'id' => 1,
                'uuid' => (string) Str::uuid(),
                'company_id' => 100,
                'name' => 'Active stamp card',
                'type' => 'visit_based',
                'config_json' => json_encode(['min_order_value' => '2.000', 'stamps_required' => 5]),
                'status' => 'active',
            ] + $timestamps,
            [
                'id' => 2,
                'uuid' => (string) Str::uuid(),
                'company_id' => 100,
                'name' => 'Active points',
                'type' => 'spend_based',
                'config_json' => json_encode([
                    'points_per_omr' => 10,
                    'redemption_points' => 100,
                    'min_redemption_points' => 100,
                    'redemption_value' => '5.000',
                ]),
                'status' => 'active',
            ] + $timestamps,
            [
                'id' => 3,
                'uuid' => (string) Str::uuid(),
                'company_id' => 100,
                'name' => 'Paused rule',
                'type' => 'visit_based',
                'config_json' => json_encode(['min_order_value' => '0.000', 'stamps_required' => 1]),
                'status' => 'paused',
            ] + $timestamps,
            [
                'id' => 9,
                'uuid' => (string) Str::uuid(),
                'company_id' => 200,
                'name' => 'Other-company rule',
                'type' => 'visit_based',
                'config_json' => json_encode(['min_order_value' => '0.000', 'stamps_required' => 1]),
                'status' => 'active',
            ] + $timestamps,
        ]);

        return $device;
    }

    private function qrSession(Device $device): int
    {
        $now = now();

        return (int) DB::table('pos_qr_sessions')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => 10,
            'device_id' => $device->id,
            'token' => hash('sha256', (string) Str::uuid()),
            'token_expires_at' => $now->copy()->addMinute(),
            'status' => QrSession::STATUS_ORDERED,
            'bound_at' => $now,
            'expires_at' => $now->copy()->addHour(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function qrOrder(Device $device, int $sessionId, array $attributes = []): Order
    {
        return Order::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => 10,
            'device_id' => $device->id,
            'customer_id' => 1,
            'qr_session_id' => $sessionId,
            'order_type' => 'quick',
            'status' => Order::STATUS_HELD,
            'source' => Order::SOURCE_QR_WEB,
            'plate_number' => '12345 A',
            'receipt_number' => 'QR-0042',
            'subtotal' => '3.000',
            'discount_total' => '0.000',
            'tax_total' => '0.000',
            'grand_total' => '3.000',
            'opened_at' => now(),
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $orderOverrides
     * @return array<string, mixed>
     */
    private function createEvent(string $orderUuid, array $orderOverrides = []): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.create',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order' => array_merge([
                    'uuid' => $orderUuid,
                    'order_type' => 'quick',
                    'source' => 'main_pos',
                    'opened_at' => now()->toIso8601String(),
                    'subtotal_baisas' => 3000,
                    'discount_total_baisas' => 0,
                    'tax_total_baisas' => 0,
                    'grand_total_baisas' => 3000,
                    'lines' => [[
                        'product_id' => 1,
                        'qty' => 1,
                        'unit_price_baisas' => 3000,
                        'line_discount_baisas' => 0,
                        'line_total_baisas' => 3000,
                    ]],
                ], $orderOverrides),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payloadOverrides
     * @return array<string, mixed>
     */
    private function payEvent(string $orderUuid, Carbon $paidAt, array $payloadOverrides = []): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => array_merge([
                'order_uuid' => $orderUuid,
                'paid_at' => $paidAt->toIso8601String(),
                'payments' => [['method' => 'cash', 'amount_baisas' => 3000]],
            ], $payloadOverrides),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(array $events): TestResponse
    {
        return $this->withToken(self::TOKEN)
            ->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }

    private function assertProcessed(TestResponse $response, int $index = 0): void
    {
        $response->assertOk();
        $this->assertSame(
            'processed',
            $response->json("data.results.$index.status"),
            (string) json_encode($response->json("data.results.$index")),
        );
    }

    private function assertActiveRulesEarned(): void
    {
        $stampAccount = LoyaltyAccount::query()
            ->where(['customer_id' => 1, 'loyalty_rule_id' => 1])
            ->firstOrFail();
        $pointsAccount = LoyaltyAccount::query()
            ->where(['customer_id' => 1, 'loyalty_rule_id' => 2])
            ->firstOrFail();

        $this->assertSame(1, (int) $stampAccount->stamp_count);
        $this->assertSame(30, (int) $pointsAccount->point_balance);
        $this->assertDatabaseMissing('pos_loyalty_accounts', ['loyalty_rule_id' => 3]);
        $this->assertDatabaseMissing('pos_loyalty_accounts', ['loyalty_rule_id' => 9]);
        $this->assertDatabaseCount('pos_loyalty_transactions', 2);
    }

    private function assertSessionClosed(int $sessionId, Carbon $paidAt): void
    {
        $session = DB::table('pos_qr_sessions')->where('id', $sessionId)->first();

        $this->assertNotNull($session);
        $this->assertSame(QrSession::STATUS_CLOSED, $session->status);
        $this->assertTrue($paidAt->equalTo(Carbon::parse((string) $session->closed_at)));
    }

    public function test_machine_pay_ignores_event_rule_ids_and_resolves_active_company_rules(): void
    {
        $device = $this->seedDomain();
        $sessionId = $this->qrSession($device);
        $order = $this->qrOrder($device, $sessionId, [
            'status' => Order::STATUS_AWAITING_PAYMENT,
        ]);
        $paidAt = now()->startOfSecond();

        $response = $this->push([$this->payEvent($order->uuid, $paidAt, [
            'loyalty_rule_ids' => [3, 9, 999],
            'loyalty_rule_id' => 999,
        ])]);

        $this->assertProcessed($response);
        $this->assertCount(2, $response->json('data.results.0.result.loyalty_transaction_ids'));
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertActiveRulesEarned();
        $this->assertSessionClosed($sessionId, $paidAt);
    }

    public function test_pay_does_not_reopen_an_expired_qr_session_as_closed(): void
    {
        $device = $this->seedDomain();
        $sessionId = $this->qrSession($device);
        $order = $this->qrOrder($device, $sessionId, [
            'status' => Order::STATUS_AWAITING_PAYMENT,
        ]);
        $expiredAt = now()->subMinute()->startOfSecond();
        DB::table('pos_qr_sessions')->where('id', $sessionId)->update([
            'status' => QrSession::STATUS_EXPIRED,
            'closed_at' => $expiredAt,
            'updated_at' => $expiredAt,
        ]);

        $this->assertProcessed($this->push([
            $this->payEvent($order->uuid, now()->startOfSecond()),
        ]));

        $session = DB::table('pos_qr_sessions')->where('id', $sessionId)->sole();
        $this->assertSame(QrSession::STATUS_EXPIRED, $session->status);
        $this->assertTrue($expiredAt->equalTo(Carbon::parse((string) $session->closed_at)));
    }

    public function test_counter_finalize_preserves_omitted_or_null_qr_attribution_and_earns(): void
    {
        $device = $this->seedDomain();
        $sessionId = $this->qrSession($device);
        $order = $this->qrOrder($device, $sessionId);

        // Before this fix, the till's own anonymous-looking re-emit satisfied
        // wasCreatedAsAnonymousWalkIn(), so payment silently wrote no earn.
        $finalize = $this->push([$this->createEvent($order->uuid)]);
        $this->assertProcessed($finalize);

        $finalized = $order->fresh();
        $this->assertSame(1, (int) $finalized->customer_id);
        $this->assertSame('12345 A', $finalized->plate_number);
        $this->assertSame('QR-0042', $finalized->receipt_number);
        $this->assertSame($sessionId, (int) $finalized->qr_session_id);
        $this->assertSame('main_pos', $finalized->source);

        $paidAt = now()->startOfSecond();
        $pay = $this->push([$this->payEvent($order->uuid, $paidAt)]);
        $this->assertProcessed($pay);

        $this->assertSame(1, (int) $order->fresh()->customer_id);
        $this->assertActiveRulesEarned();
        $this->assertSessionClosed($sessionId, $paidAt);

        $nullSessionId = $this->qrSession($device);
        $nullOrder = $this->qrOrder($device, $nullSessionId, [
            'receipt_number' => 'QR-0043',
        ]);
        $nullFinalize = $this->push([$this->createEvent($nullOrder->uuid, [
            'customer_id' => null,
            'plate_number' => null,
            'receipt_number' => null,
        ])]);
        $this->assertProcessed($nullFinalize);

        $nullFinalized = $nullOrder->fresh();
        $this->assertSame(1, (int) $nullFinalized->customer_id);
        $this->assertSame('12345 A', $nullFinalized->plate_number);
        $this->assertSame('QR-0043', $nullFinalized->receipt_number);
        $this->assertSame($nullSessionId, (int) $nullFinalized->qr_session_id);
    }

    public function test_counter_finalize_allows_explicit_non_null_cashier_replacements(): void
    {
        $device = $this->seedDomain();
        $sessionId = $this->qrSession($device);
        $order = $this->qrOrder($device, $sessionId);

        $response = $this->push([$this->createEvent($order->uuid, [
            'customer_id' => 2,
            'plate_number' => '54321 B',
            'receipt_number' => 'POS-0099',
        ])]);
        $this->assertProcessed($response);

        $finalized = $order->fresh();
        $this->assertSame(2, (int) $finalized->customer_id);
        $this->assertSame('54321 B', $finalized->plate_number);
        $this->assertSame('POS-0099', $finalized->receipt_number);
        $this->assertSame($sessionId, (int) $finalized->qr_session_id);
        $this->assertSame('main_pos', $finalized->source);
    }
}
