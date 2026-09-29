<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Floor;
use App\Models\Order;
use App\Models\Payment;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\Table as PosTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class QrTempReferenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.timezone' => 'UTC',
            'qr.charge_claim_seconds' => 180,
            'qr.settlement_claim_seconds' => 300,
            'qr.charge_sweep_enabled' => false,
            'qr.station_geofence_exempt' => false,
        ]);
        $this->travelTo(Carbon::parse('2026-09-05 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);

        foreach ([10, 20] as $branchId) {
            Branch::query()->create([
                'id' => $branchId,
                'uuid' => (string) Str::uuid(),
                'company_id' => 100,
                'name' => 'Temporary reference branch '.$branchId,
                'latitude' => null,
                'longitude' => null,
                'geofence_radius_m' => 500,
                'status' => 'active',
            ]);
        }

        DB::table('pos_products')->insert([
            'id' => 1,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'category_id' => null,
            'name' => 'Temporary reference coffee',
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
        foreach ([10, 20] as $branchId) {
            DB::table('pos_branch_product')->insert([
                'branch_id' => $branchId,
                'product_id' => 1,
                'is_available' => true,
                'stock_qty' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->setNumbering(true);
    }

    public function test_open_allocates_once_and_first_round_reuses_the_seating_reference(): void
    {
        $station = $this->device();
        $table = $this->dineInTable($station);
        $open = $this->postAs($station, '/api/v1/device/qr/open-table', ['table_id' => $table->id])
            ->assertCreated();
        $session = QrSession::query()->where('uuid', $open->json('data.session_uuid'))->sole();
        $seating = $session->tableSession()->sole();
        $this->assertSame('T-0905-001', $seating->temp_reference);
        $this->assertDatabaseHas('pos_temp_reference_sequences', [
            'company_id' => 100, 'branch_id' => 10, 'seq_date' => '2026-09-05', 'next_number' => 2,
        ]);
        $this->assertDatabaseCount('pos_orders', 0);
        $this->assertDatabaseCount('pos_order_sequences', 0);
        $secret = 'opened-reference-first-round';
        $this->postJson('/api/v1/public/qr/table-bind', [
            'table_token' => $table->qr_token, 'client_secret' => $secret,
        ])->assertOk();
        $payload = [
            'client_request_id' => 'opened-reference-round',
            'phone' => '90001234',
            'lines' => [['product_id' => 1, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
        ];
        $this->withHeaders($this->qrHeaders($session, $secret))
            ->postJson('/api/v1/public/qr/table-round', $payload)
            ->assertCreated()->assertJsonPath('data.order.temp_reference', 'T-0905-001');
        $order = Order::query()->sole();
        $this->assertSame('T-0905-001', $order->temp_reference);
        $this->assertSame((int) $seating->id, (int) $order->table_session_id);
        $this->assertSame((int) $order->id, (int) $seating->fresh()->order_id);
        $this->assertDatabaseHas('pos_temp_reference_sequences', [
            'company_id' => 100, 'branch_id' => 10, 'seq_date' => '2026-09-05', 'next_number' => 2,
        ]);
        $seatingBefore = $seating->fresh()->getRawOriginal();
        $this->withHeaders($this->qrHeaders($session, $secret))
            ->postJson('/api/v1/public/qr/table-round', $payload)
            ->assertCreated()->assertJsonPath('data.replayed', true);
        $this->assertSame($seatingBefore, $seating->fresh()->getRawOriginal());
        $this->assertDatabaseHas('pos_temp_reference_sequences', [
            'company_id' => 100, 'branch_id' => 10, 'seq_date' => '2026-09-05', 'next_number' => 2,
        ]);
        $this->assertDatabaseCount('pos_order_sequences', 0);
    }

    public function test_two_opens_without_orders_consume_two_branch_day_references(): void
    {
        $station = $this->device();
        foreach (['T-0905-001', 'T-0905-002'] as $reference) {
            $table = $this->dineInTable($station);
            $open = $this->postAs($station, '/api/v1/device/qr/open-table', ['table_id' => $table->id])
                ->assertCreated();
            $session = QrSession::query()->where('uuid', $open->json('data.session_uuid'))->sole();
            $this->assertSame($reference, $session->tableSession()->sole()->temp_reference);
        }
        $this->assertDatabaseCount('pos_table_sessions', 2);
        $this->assertDatabaseCount('pos_orders', 0);
        $this->assertDatabaseCount('pos_order_sequences', 0);
        $this->assertDatabaseHas('pos_temp_reference_sequences', [
            'company_id' => 100, 'branch_id' => 10, 'seq_date' => '2026-09-05', 'next_number' => 3,
        ]);
    }

    public function test_station_card_payment_allocates_the_official_number_and_preserves_the_temporary_reference(): void
    {
        $station = $this->device();
        [$order, $session, $secret] = $this->checkout($station, 'machine');
        $this->assertSame('T-0905-001', $order->temp_reference);
        $this->assertDatabaseCount('pos_order_sequences', 0);

        $this->postAs($station, '/api/v1/device/qr/claim-charge', [
            'order_uuid' => $order->uuid,
        ])->assertOk();

        $paid = $this->pay($station, $order, true);
        $this->assertPaymentReferences($paid, 'QR-0001', 'T-0905-001');
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame('QR-0001', $order->fresh()->receipt_number);
        $this->assertSame('T-0905-001', $order->fresh()->temp_reference);
        $this->assertDatabaseHas('pos_order_sequences', [
            'company_id' => 100, 'branch_id' => 10, 'next_number' => 2,
        ]);
        $this->publicStatus($session, $secret)
            ->assertOk()
            ->assertJsonPath('data.order.receipt_number', 'QR-0001')
            ->assertJsonPath('data.order.temp_reference', 'T-0905-001');
    }

    public function test_attended_standalone_payment_after_claim_settlement_allocates_the_official_number(): void
    {
        $station = $this->device();
        $till = $this->device('fixed_pos');
        [$order] = $this->dineInOrder($station);
        $this->assertSame('T-0905-001', $order->temp_reference);
        $this->assertDatabaseCount('pos_order_sequences', 0);

        $this->postAs($till, '/api/v1/device/qr/claim-settlement', [
            'order_uuid' => $order->uuid,
        ])->assertOk()
            ->assertJsonPath('data.receipt_number', null)
            ->assertJsonPath('data.temp_reference', 'T-0905-001')
            ->assertJsonPath('data.charge_amount_baisas', 2500);

        $this->assertPaymentReferences($this->pay($till, $order), 'QR-0001', 'T-0905-001');
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(Order::SOURCE_QR_WEB, $order->fresh()->source);
        $this->assertSame('QR-0001', $order->fresh()->receipt_number);
        $this->assertSame('T-0905-001', $order->fresh()->temp_reference);
        $this->assertDatabaseHas('pos_order_sequences', [
            'company_id' => 100, 'branch_id' => 10, 'next_number' => 2,
        ]);
    }

    public function test_numbering_disabled_payment_keeps_a_null_official_number_and_the_temporary_reference(): void
    {
        $this->setNumbering(false);
        $station = $this->device();
        [$order, $session, $secret] = $this->checkout($station, 'machine');

        $this->postAs($station, '/api/v1/device/qr/claim-charge', [
            'order_uuid' => $order->uuid,
        ])->assertOk();

        $this->assertPaymentReferences($this->pay($station, $order, true), null, 'T-0905-001');
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertNull($order->fresh()->receipt_number);
        $this->assertSame('T-0905-001', $order->fresh()->temp_reference);
        $this->assertDatabaseCount('pos_order_sequences', 0);
        $this->assertDatabaseHas('pos_temp_reference_sequences', [
            'company_id' => 100, 'branch_id' => 10, 'seq_date' => '2026-09-05', 'next_number' => 2,
        ]);
        $this->publicStatus($session, $secret)
            ->assertOk()
            ->assertJsonPath('data.order.receipt_number', null)
            ->assertJsonPath('data.order.temp_reference', 'T-0905-001');
    }

    public function test_till_finalisation_preserves_an_explicit_receipt_without_reallocating_at_payment(): void
    {
        [$order] = $this->checkout($this->device(), 'counter');
        $till = $this->device('fixed_pos');
        $sequenceBefore = DB::table('pos_order_sequences')->get()->toArray();

        $created = $this->push($till, [$this->createEvent($order->uuid, 'POS-0099')]);
        $this->assertProcessed($created);
        $this->assertSame('POS-0099', $order->fresh()->receipt_number);
        $this->assertSame('T-0905-001', $order->fresh()->temp_reference);
        $this->assertSame('main_pos', $order->fresh()->source);
        $this->assertSame($order->qr_session_id, $order->fresh()->qr_session_id);

        $this->assertPaymentReferences($this->pay($till, $order), 'POS-0099', 'T-0905-001');
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame('POS-0099', $order->fresh()->receipt_number);
        $this->assertSame('T-0905-001', $order->fresh()->temp_reference);
        $this->assertSame($sequenceBefore, DB::table('pos_order_sequences')->get()->toArray());
        $this->assertDatabaseCount('pos_order_sequences', 0);
    }

    public function test_temporary_reference_resets_at_utc_midnight_with_the_matching_date(): void
    {
        $station = $this->device();
        $this->travelTo(Carbon::parse('2026-09-05 23:59:59', 'UTC'));
        [$beforeMidnight] = $this->checkout($station, 'counter');
        $this->travelTo(Carbon::parse('2026-09-06 00:00:00', 'UTC'));
        [$afterMidnight] = $this->checkout($station, 'counter');

        $this->assertSame('T-0905-001', $beforeMidnight->temp_reference);
        $this->assertSame('T-0906-001', $afterMidnight->temp_reference);
        $this->assertDatabaseCount('pos_temp_reference_sequences', 2);
        foreach (['2026-09-05', '2026-09-06'] as $date) {
            $this->assertDatabaseHas('pos_temp_reference_sequences', [
                'company_id' => 100, 'branch_id' => 10, 'seq_date' => $date, 'next_number' => 2,
            ]);
        }
        $this->assertDatabaseCount('pos_order_sequences', 0);
    }

    public function test_two_branches_each_start_the_same_day_at_the_first_temporary_reference(): void
    {
        [$firstBranch] = $this->checkout($this->device(branchId: 10), 'counter');
        [$secondBranch] = $this->checkout($this->device(branchId: 20), 'counter');

        $this->assertSame('T-0905-001', $firstBranch->temp_reference);
        $this->assertSame('T-0905-001', $secondBranch->temp_reference);
        $this->assertNotSame($firstBranch->branch_id, $secondBranch->branch_id);
        $this->assertDatabaseCount('pos_temp_reference_sequences', 2);
        foreach ([10, 20] as $branchId) {
            $this->assertDatabaseHas('pos_temp_reference_sequences', [
                'company_id' => 100, 'branch_id' => $branchId, 'seq_date' => '2026-09-05', 'next_number' => 2,
            ]);
        }
        $this->assertDatabaseCount('pos_order_sequences', 0);
    }

    public function test_voiding_an_unpaid_qr_order_never_consumes_an_official_number(): void
    {
        [$order] = $this->checkout($this->device(), 'counter');
        $till = $this->device('fixed_pos');
        $response = $this->push($till, [[
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => $order->uuid,
                'voided_at' => now()->toIso8601String(),
                'reason' => 'Customer cancelled before payment',
            ],
        ]]);

        $this->assertProcessed($response);
        $this->assertSame(Order::STATUS_VOID, $order->fresh()->status);
        $this->assertNull($order->fresh()->receipt_number);
        $this->assertSame('T-0905-001', $order->fresh()->temp_reference);
        $this->assertDatabaseCount('pos_order_sequences', 0);
        $this->assertDatabaseCount('pos_payments', 0);
        $this->assertDatabaseHas('pos_temp_reference_sequences', [
            'company_id' => 100, 'branch_id' => 10, 'seq_date' => '2026-09-05', 'next_number' => 2,
        ]);
    }

    public function test_numbering_disabled_station_safe_fallback_is_held_and_claim_settleable(): void
    {
        $this->setNumbering(false);
        $station = $this->device();
        $till = $this->device('fixed_pos');
        [$order, $session, $secret] = $this->dineInOrder($station);

        $this->withHeaders($this->qrHeaders($session, $secret))
            ->postJson('/api/v1/public/qr/table-finish', ['payment_choice' => 'station'])
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_AWAITING_PAYMENT);

        $this->postAs($station, '/api/v1/device/qr/fallback-to-counter', [
            'order_uuid' => $order->uuid,
        ])->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_HELD)
            ->assertJsonPath('data.receipt_number', null)
            ->assertJsonPath('data.temp_reference', 'T-0905-001');
        $this->assertSame(Order::STATUS_HELD, $order->fresh()->status);
        $this->assertNull($order->fresh()->receipt_number);
        $this->assertSame('T-0905-001', $order->fresh()->temp_reference);

        $this->postAs($till, '/api/v1/device/qr/claim-settlement', [
            'order_uuid' => $order->uuid,
        ])->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_AWAITING_PAYMENT)
            ->assertJsonPath('data.receipt_number', null)
            ->assertJsonPath('data.temp_reference', 'T-0905-001')
            ->assertJsonPath('data.charge_amount_baisas', 2500);
        $this->assertSame((int) $till->id, (int) $order->fresh()->charge_device_id);
        $this->assertDatabaseCount('pos_order_sequences', 0);
        $this->assertDatabaseCount('pos_payments', 0);
    }

    public function test_main_pos_payment_result_carries_the_till_receipt_and_a_null_temporary_reference(): void
    {
        $till = $this->device('fixed_pos');
        $uuid = (string) Str::uuid();
        $this->assertProcessed($this->push($till, [$this->createEvent($uuid, 'POS-0042')]));
        $order = Order::query()->where('uuid', $uuid)->sole();
        $this->assertSame('main_pos', $order->source);
        $this->assertNull($order->temp_reference);
        $sequenceBefore = DB::table('pos_order_sequences')->get()->toArray();

        $this->assertPaymentReferences($this->pay($till, $order), 'POS-0042', null);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame('POS-0042', $order->fresh()->receipt_number);
        $this->assertNull($order->fresh()->temp_reference);
        $this->assertSame($sequenceBefore, DB::table('pos_order_sequences')->get()->toArray());
        $this->assertDatabaseCount('pos_temp_reference_sequences', 0);
    }

    private function setNumbering(bool $enabled): void
    {
        DB::table('pos_company_settings')->updateOrInsert([
            'company_id' => 100,
            'key' => 'order_numbering',
        ], [
            'value' => json_encode([
                'enabled' => $enabled,
                'prefix' => 'QR-',
                'pad' => 4,
                'scope' => 'branch',
                'daily_reset' => false,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function device(string $type = 'payment_station', int $branchId = 10): Device
    {
        return Device::factory()->paired()->create([
            'company_id' => 100,
            'branch_id' => $branchId,
            'device_type' => $type,
        ]);
    }

    private function qrSession(Device $station, string $secret, ?PosTable $table = null): QrSession
    {
        $now = now();

        return QrSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $station->company_id,
            'branch_id' => $station->branch_id,
            'device_id' => $station->id,
            'table_id' => $table?->id,
            'token' => hash('sha256', (string) Str::uuid()),
            'token_expires_at' => $now->copy()->addMinute(),
            'client_secret_hash' => QrSession::hashClientSecret($secret),
            'status' => QrSession::STATUS_ACTIVE,
            'bound_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => $now->copy()->addHours(6),
        ]);
    }

    /** @return array{Order, QrSession, string} */
    private function checkout(Device $station, string $choice): array
    {
        $secret = 'temp-reference-'.Str::uuid();
        $session = $this->qrSession($station, $secret);
        $response = $this->withHeaders($this->qrHeaders($session, $secret))
            ->postJson('/api/v1/public/qr/checkout', [
                'client_request_id' => (string) Str::uuid(),
                'checkout_choice' => $choice,
                'phone' => '90001234',
                'plate_number' => '12345 A',
                'lines' => [['product_id' => 1, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
            ])->assertCreated()
            ->assertJsonPath('data.order.receipt_number', null);

        $order = Order::query()->where('uuid', $response->json('data.order.uuid'))->sole();
        $this->assertNull($order->receipt_number);
        $this->assertSame($order->temp_reference, $response->json('data.order.temp_reference'));

        return [$order, $session, $secret];
    }

    private function dineInTable(Device $station): PosTable
    {
        $floor = Floor::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => $station->branch_id,
            'name' => 'Temporary reference floor',
            'display_order' => 1,
            'status' => 'active',
        ]);

        return PosTable::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'floor_id' => $floor->id,
            'label' => 'TEMP-1',
            'seats' => 4,
            'shape' => 'square',
            'qr_token' => hash('sha256', (string) Str::uuid()),
            'status' => 'active',
            'display_order' => 1,
        ]);
    }

    /** @return array{Order, QrSession, string} */
    private function dineInOrder(Device $station): array
    {
        $table = $this->dineInTable($station);
        $secret = 'temp-dine-in-'.Str::uuid();
        $session = $this->qrSession($station, $secret, $table);
        $response = $this->withHeaders($this->qrHeaders($session, $secret))
            ->postJson('/api/v1/public/qr/table-round', [
                'client_request_id' => (string) Str::uuid(),
                'phone' => '90001234',
                'lines' => [['product_id' => 1, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
            ])->assertCreated()
            ->assertJsonPath('data.round.status', QrOrderRound::STATUS_ACCEPTED)
            ->assertJsonPath('data.order.receipt_number', null);

        $order = Order::query()->where('uuid', $response->json('data.order.uuid'))->sole();
        $this->assertNull($order->receipt_number);
        $this->assertSame($order->temp_reference, $response->json('data.order.temp_reference'));

        return [$order, $session, $secret];
    }

    /** @return array<string, string> */
    private function qrHeaders(QrSession $session, string $secret): array
    {
        return ['X-QR-Session' => $session->uuid, 'X-QR-Client-Secret' => $secret];
    }

    private function publicStatus(QrSession $session, string $secret): TestResponse
    {
        return $this->withHeaders($this->qrHeaders($session, $secret))
            ->getJson('/api/v1/public/qr/status');
    }

    /** @param array<string, mixed> $payload */
    private function postAs(Device $device, string $url, array $payload): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->withToken((string) $device->plainTextToken)->postJson($url, $payload);
    }

    /** @param list<array<string, mixed>> $events */
    private function push(Device $device, array $events): TestResponse
    {
        return $this->postAs($device, '/api/v1/device/sync/push', ['events' => $events]);
    }

    private function pay(Device $device, Order $order, bool $card = false): TestResponse
    {
        $payment = [
            'method' => $card ? Payment::METHOD_CARD : Payment::METHOD_CASH,
            'amount_baisas' => 2500,
            'status' => Payment::STATUS_SUCCESS,
        ];
        if ($card) {
            $payment['softpos_reference'] = 'TEMP-'.Str::uuid();
            $payment['softpos_auth_code'] = 'AUTH42';
        }

        return $this->push($device, [[
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => $order->uuid,
                'paid_at' => now()->toIso8601String(),
                'payments' => [$payment],
            ],
        ]]);
    }

    /** @return array<string, mixed> */
    private function createEvent(string $uuid, string $receiptNumber): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.create',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order' => [
                    'uuid' => $uuid,
                    'order_type' => 'quick',
                    'source' => 'main_pos',
                    'receipt_number' => $receiptNumber,
                    'opened_at' => now()->toIso8601String(),
                    'subtotal_baisas' => 2500,
                    'discount_total_baisas' => 0,
                    'tax_total_baisas' => 0,
                    'grand_total_baisas' => 2500,
                    'lines' => [[
                        'product_id' => 1,
                        'qty' => 1,
                        'unit_price_baisas' => 2500,
                        'line_discount_baisas' => 0,
                        'line_total_baisas' => 2500,
                    ]],
                ],
            ],
        ];
    }

    private function assertProcessed(TestResponse $response): void
    {
        $response->assertOk();
        $this->assertSame(
            'processed',
            $response->json('data.results.0.status'),
            (string) json_encode($response->json('data.results.0')),
        );
    }

    private function assertPaymentReferences(
        TestResponse $response,
        ?string $receiptNumber,
        ?string $tempReference,
    ): void {
        $this->assertProcessed($response);
        $result = $response->json('data.results.0.result');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('receipt_number', $result);
        $this->assertArrayHasKey('temp_reference', $result);
        $this->assertSame($receiptNumber, $result['receipt_number']);
        $this->assertSame($tempReference, $result['temp_reference']);

        $keys = array_keys($result);
        $warningIndex = array_search('loyalty_redeem_warning', $keys, true);
        $this->assertIsInt($warningIndex);
        $this->assertSame(
            ['loyalty_redeem_warning', 'receipt_number', 'temp_reference'],
            array_slice($keys, $warningIndex, 3),
        );
    }
}
