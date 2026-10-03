<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Floor;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\Table as PosTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class QrDineInFoundationTest extends TestCase
{
    use RefreshDatabase;

    private const OPEN_TABLE_URL = '/api/v1/device/qr/open-table';

    private const ROTATE_URL = '/api/v1/device/qr/rotate';

    private const TABLE_MENU_URL = '/api/v1/public/qr/table-menu';

    private const TABLE_BIND_URL = '/api/v1/public/qr/table-bind';

    private const STATUS_URL = '/api/v1/public/qr/status';

    private int $deviceSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-30 12:00:00'));
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['qr.dine_in_session_lifetime_hours' => 6]);
    }

    public function test_rotation_storm_expires_quick_pending_sessions_but_never_touches_open_tables(): void
    {
        $station = $this->device('payment_station');
        $table = $this->table(branchId: 10, label: 'T-ROTATE');

        $opened = $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $table->id])
            ->assertCreated();
        $dineIn = QrSession::query()
            ->where('uuid', $opened->json('data.session_uuid'))
            ->sole();
        $quick = $this->qrSessionRecord($station, null, QrSession::STATUS_PENDING);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postAs($station, self::ROTATE_URL)->assertOk();
        }

        $dineIn->refresh();
        $quick->refresh();
        $this->assertSame(QrSession::STATUS_PENDING, $dineIn->status);
        $this->assertNull($dineIn->closed_at);
        $this->assertSame(QrSession::STATUS_EXPIRED, $quick->status);
        $this->assertNotNull($quick->closed_at);
        $this->assertSame(
            1,
            QrSession::query()->where('table_id', $table->id)->count(),
        );
        $this->assertSame(
            1,
            QrSession::query()
                ->whereNull('table_id')
                ->where('status', QrSession::STATUS_PENDING)
                ->count(),
        );
    }

    public function test_quick_rotate_bind_and_checkout_never_create_a_seating(): void
    {
        $station = $this->device('payment_station');
        $productId = $this->product('Quick seating-free coffee', 10);
        $this->postAs($station, self::ROTATE_URL)->assertOk();
        $this->assertDatabaseCount('pos_table_sessions', 0);
        $session = QrSession::query()->whereNull('table_id')->sole();
        $secret = 'quick-has-no-seating';
        $this->postJson('/api/v1/public/qr/bind', [
            'token' => $session->token,
            'client_secret' => $secret,
        ])->assertOk();
        $this->assertDatabaseCount('pos_table_sessions', 0);
        $this->withHeaders([
            'X-QR-Session' => $session->uuid,
            'X-QR-Client-Secret' => $secret,
        ])->postJson('/api/v1/public/qr/checkout', [
            'client_request_id' => 'quick-seating-free-checkout',
            'checkout_choice' => 'counter',
            'phone' => '90001234',
            'lines' => [['product_id' => $productId, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
        ])->assertCreated();
        $this->assertDatabaseCount('pos_table_sessions', 0);
        $this->assertNull($session->fresh()->table_session_id);
        $this->assertNull(Order::query()->sole()->table_session_id);
        $this->assertDatabaseCount('pos_table_session_events', 0);
    }

    public function test_open_table_enforces_station_branch_state_token_and_one_live_session(): void
    {
        $station = $this->device('payment_station');
        $fixedPos = $this->device('fixed_pos');
        $valid = $this->table(branchId: 10, label: 'T-VALID');
        $otherBranch = $this->table(branchId: 20, label: 'T-OTHER');
        $inactive = $this->table(branchId: 10, label: 'T-INACTIVE', status: 'inactive');
        $withoutToken = $this->table(branchId: 10, label: 'T-NO-TOKEN', token: null);

        $this->postAs($fixedPos, self::OPEN_TABLE_URL, ['table_id' => $valid->id])
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'device_not_payment_station');
        $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $otherBranch->id])
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'qr_table_not_found');
        $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $inactive->id])
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_table_not_active');
        $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $withoutToken->id])
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_table_token_missing');

        $response = $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $valid->id])
            ->assertCreated()
            ->assertJsonPath('data.table_token', $valid->qr_token)
            ->assertJsonPath('data.expires_at', now()->copy()->addHours(6)->toIso8601String());

        $session = QrSession::query()
            ->where('uuid', $response->json('data.session_uuid'))
            ->sole();
        $this->assertSame(QrSession::STATUS_PENDING, $session->status);
        $this->assertSame((int) $valid->id, (int) $session->table_id);
        $this->assertSame(
            $session->expires_at?->toIso8601String(),
            $session->token_expires_at?->toIso8601String(),
        );
        $this->assertDatabaseCount('pos_table_sessions', 1);
        $seating = $session->tableSession()->sole();
        $this->assertSame('open', $seating->status);
        $this->assertSame('station', $seating->origin);
        $this->assertSame(100, (int) $seating->company_id);
        $this->assertSame(10, (int) $seating->branch_id);
        $this->assertSame((int) $valid->id, (int) $seating->table_id);
        $this->assertSame((int) $station->id, (int) $seating->opened_by_device_id);
        $this->assertSame(now()->toIso8601String(), $seating->opened_at->toIso8601String());
        $this->assertSame($session->expires_at->toIso8601String(), $seating->expires_at->toIso8601String());
        $this->assertSame('T-0830-001', $seating->temp_reference);
        $this->assertTrue(Str::isUuid($seating->uuid));
        foreach (['order_id', 'billing_at', 'closed_at', 'closed_by_device_id', 'close_reason'] as $column) {
            $this->assertNull($seating->{$column}, $column);
        }
        $this->assertDatabaseCount('pos_table_session_events', 1);
        $this->assertDatabaseHas('pos_table_session_events', [
            'table_session_id' => $seating->id, 'event_type' => 'opened', 'device_id' => $station->id,
        ]);
        $seatingBefore = $seating->getRawOriginal();

        $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $valid->id])
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_table_already_open');
        $this->assertSame(
            1,
            QrSession::query()->where('table_id', $valid->id)->count(),
        );
        $this->assertDatabaseCount('pos_table_sessions', 1);
        $this->assertSame($seatingBefore, $seating->fresh()->getRawOriginal());
    }

    public function test_open_table_order_guard_survives_lazy_horizon_expiry(): void
    {
        $station = $this->device('payment_station');
        $table = $this->table(branchId: 10, label: 'T-ORPHAN');
        $open = $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $table->id])
            ->assertCreated();
        $session = QrSession::query()
            ->where('uuid', $open->json('data.session_uuid'))
            ->sole();
        $secret = 'orphan-horizon-secret';

        $this->postJson(self::TABLE_BIND_URL, [
            'table_token' => $table->qr_token,
            'client_secret' => $secret,
        ])->assertOk();
        $this->order($station, $table, $session, Order::SOURCE_QR_WEB);

        $this->travelTo($session->expires_at->copy()->addSecond());
        $this->qrStatus($session, $secret)
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'qr_session_not_found');
        $this->assertSame(QrSession::STATUS_EXPIRED, $session->fresh()->status);

        $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $table->id])
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_table_has_unpaid_order');
        $this->assertSame(
            1,
            QrSession::query()->where('table_id', $table->id)->count(),
        );
    }

    public function test_any_source_unpaid_primary_or_joined_table_occupancy_blocks_open_table(): void
    {
        $station = $this->device('payment_station');
        $till = $this->device('fixed_pos');
        $cases = [
            ['status' => Order::STATUS_OPEN, 'source' => 'main_pos'],
            ['status' => Order::STATUS_HELD, 'source' => 'delivery'],
            ['status' => Order::STATUS_AWAITING_PAYMENT, 'source' => Order::SOURCE_QR_WEB],
        ];

        foreach ($cases as $index => $case) {
            foreach (['primary', 'pivot'] as $placement) {
                $target = $this->table(
                    branchId: 10,
                    label: sprintf('T-OCC-%d-%s', $index, $placement),
                );
                $primary = $placement === 'primary'
                    ? $target
                    : $this->table(
                        branchId: 10,
                        label: sprintf('T-OCC-%d-PARENT', $index),
                    );
                $order = $this->order(
                    $till,
                    $primary,
                    null,
                    $case['source'],
                    $case['status'],
                );
                if ($placement === 'pivot') {
                    DB::table('pos_order_tables')->insert([
                        'order_id' => $order->id,
                        'table_id' => $target->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $target->id])
                    ->assertConflict()
                    ->assertJsonPath('errors.0.code', 'qr_table_has_unpaid_order');
                $this->assertSame(
                    0,
                    QrSession::query()->where('table_id', $target->id)->count(),
                    sprintf('%s %s occupancy created a QR session', $case['source'], $placement),
                );
            }
        }
    }

    public function test_table_menu_is_sessionless_branch_scoped_and_does_not_mutate_sessions(): void
    {
        $station = $this->device('payment_station', branchId: 20);
        $table = $this->table(branchId: 10, label: 'T-MENU');
        $otherTable = $this->table(branchId: 20, label: 'T-OTHER-MENU');
        $branchProduct = $this->product('Branch ten coffee', 10);
        $otherProduct = $this->product('Branch twenty coffee', 20);
        $this->qrSessionRecord($station, $otherTable, QrSession::STATUS_PENDING);
        $before = DB::table('pos_qr_sessions')
            ->orderBy('id')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        $response = $this->getJson(self::TABLE_MENU_URL.'?t='.urlencode((string) $table->qr_token))
            ->assertOk()
            ->assertJsonPath('data.table.uuid', $table->uuid)
            ->assertJsonPath('data.table.label', 'T-MENU')
            ->assertJsonCount(1, 'data.products')
            ->assertJsonPath('data.products.0.id', $branchProduct)
            ->assertJsonPath('meta.money_unit', 'baisas');

        $this->assertNotSame($otherProduct, $response->json('data.products.0.id'));
        $this->assertArrayNotHasKey('session_uuid', $response->json('data'));
        $this->assertArrayNotHasKey('expires_at', $response->json('data'));
        $this->assertSame(
            $before,
            DB::table('pos_qr_sessions')
                ->orderBy('id')
                ->get()
                ->map(static fn (object $row): array => (array) $row)
                ->all(),
        );

        $this->getJson(self::TABLE_MENU_URL.'?t=unknown-table-token')
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'qr_table_not_found');
    }

    public function test_first_bind_ignores_rotation_window_and_a_second_secret_is_read_only(): void
    {
        $station = $this->device('payment_station');
        $table = $this->table(branchId: 10, label: 'T-REBIND');
        $open = $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $table->id])
            ->assertCreated();
        $session = QrSession::query()
            ->where('uuid', $open->json('data.session_uuid'))
            ->sole();
        $session->update(['token_expires_at' => now()->subMinute()]);

        $firstSecret = 'first-browser-secret';
        $this->postJson(self::TABLE_BIND_URL, [
            'table_token' => $table->qr_token,
            'client_secret' => $firstSecret,
        ])->assertOk()
            ->assertJsonPath('data.session_uuid', $session->uuid)
            ->assertJsonPath('data.status', QrSession::STATUS_ACTIVE);
        $session->refresh();
        $this->assertTrue($session->clientSecretMatches($firstSecret));
        $this->assertNotNull($session->bound_at);
        $this->assertNull($session->secret_rotated_at);

        $order = $this->order($station, $table, $session, Order::SOURCE_QR_WEB);
        $orderBefore = $order->fresh()->getRawOriginal();
        $boundAt = $session->bound_at?->toIso8601String();
        $this->travel(5)->minutes();

        $secondSecret = 'replacement-browser-secret';
        $this->postJson(self::TABLE_BIND_URL, [
            'table_token' => $table->qr_token,
            'client_secret' => $secondSecret,
        ])->assertOk()
            ->assertJsonPath('data.session_uuid', null)
            ->assertJsonPath('data.status', 'read_only')
            ->assertJsonPath('data.read_only', true);

        $session->refresh();
        $this->assertFalse($session->clientSecretMatches($secondSecret));
        $this->assertTrue($session->clientSecretMatches($firstSecret));
        $this->assertSame($boundAt, $session->bound_at?->toIso8601String());
        $this->assertNull($session->secret_rotated_at);
        $this->assertSame($orderBefore, $order->fresh()->getRawOriginal());

        $this->qrStatus($session, $firstSecret)
            ->assertOk()
            ->assertJsonPath('data.order.uuid', $order->uuid);
        $this->qrStatus($session, $secondSecret)
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'qr_session_not_found');
    }

    public function test_ordered_table_bind_is_read_only_and_stationless_stays_generic(): void
    {
        $station = $this->device('payment_station');
        $table = $this->table(branchId: 10, label: 'T-FROZEN');
        $open = $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $table->id])
            ->assertCreated();
        $session = QrSession::query()
            ->where('uuid', $open->json('data.session_uuid'))
            ->sole();
        $secret = 'frozen-browser-secret';
        $this->postJson(self::TABLE_BIND_URL, [
            'table_token' => $table->qr_token,
            'client_secret' => $secret,
        ])->assertOk();

        $session->update(['status' => QrSession::STATUS_ORDERED]);
        $beforeOrdered = $session->fresh()->getRawOriginal();
        $this->postJson(self::TABLE_BIND_URL, [
            'table_token' => $table->qr_token,
            'client_secret' => 'must-not-replace-ordered',
        ])->assertOk()
            ->assertJsonPath('data.read_only', true);
        $this->assertSame($beforeOrdered, $session->fresh()->getRawOriginal());

        $session->update(['status' => QrSession::STATUS_ACTIVE]);
        $station->update(['status' => 'inactive']);
        $beforeStationless = $session->fresh()->getRawOriginal();
        $this->postJson(self::TABLE_BIND_URL, [
            'table_token' => $table->qr_token,
            'client_secret' => 'must-not-replace-stationless',
        ])->assertNotFound()
            ->assertExactJson($this->genericBindFailure());
        $this->assertSame($beforeStationless, $session->fresh()->getRawOriginal());
        $this->assertTrue($session->fresh()->clientSecretMatches($secret));
    }

    private function device(string $type, int $branchId = 10): Device
    {
        $this->deviceSequence++;
        Branch::query()->firstOrCreate(['id' => $branchId], [
            'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'Foundation branch '.$branchId, 'status' => 'active',
            'latitude' => null, 'longitude' => null, 'geofence_radius_m' => 500,
        ]);

        return Device::factory()
            ->paired('mdev_qr2_foundation_'.$this->deviceSequence)
            ->create([
                'company_id' => 100,
                'branch_id' => $branchId,
                'device_type' => $type,
            ]);
    }

    private function table(
        int $branchId,
        string $label,
        string $status = 'active',
        ?string $token = 'generated',
    ): PosTable {
        $floor = Floor::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => $branchId,
            'name' => 'Floor '.$label,
            'display_order' => 1,
            'status' => 'active',
        ]);

        return PosTable::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'floor_id' => $floor->id,
            'label' => $label,
            'seats' => 4,
            'shape' => 'square',
            'qr_token' => $token === 'generated'
                ? hash('sha256', (string) Str::uuid())
                : $token,
            'status' => $status,
            'display_order' => 1,
        ]);
    }

    private function qrSessionRecord(
        Device $station,
        ?PosTable $table,
        string $status,
    ): QrSession {
        return QrSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $station->company_id,
            'branch_id' => $station->branch_id,
            'device_id' => $station->id,
            'table_id' => $table?->id,
            'token' => hash('sha256', (string) Str::uuid()),
            'token_expires_at' => now()->addMinute(),
            'status' => $status,
            'expires_at' => now()->addHours(6),
        ]);
    }

    private function order(
        Device $device,
        PosTable $table,
        ?QrSession $session,
        string $source,
        string $status = Order::STATUS_OPEN,
    ): Order {
        return Order::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $device->company_id,
            'branch_id' => $device->branch_id,
            'device_id' => $device->id,
            'qr_session_id' => $session?->id,
            'client_request_id' => $session === null ? null : (string) Str::uuid(),
            'staff_id' => null,
            'customer_id' => null,
            'table_id' => $table->id,
            'order_type' => 'dine_in',
            'status' => $status,
            'source' => $source,
            'plate_number' => null,
            'subtotal' => '4.750',
            'discount_total' => '0.000',
            'comp_total' => '0.000',
            'tax_total' => '0.000',
            'grand_total' => '4.750',
            'opened_at' => now(),
            'receipt_number' => 'QR-TEST',
        ]);
    }

    private function product(string $name, int $branchId): int
    {
        $productId = DB::table('pos_products')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'category_id' => null,
            'name' => $name,
            'base_price' => '1.500',
            'stock_mode' => 'untracked',
            'display_order' => 1,
            'status' => 'active',
            'show_on_customer_tablet' => true,
            'is_internal' => false,
            'available_from' => null,
            'available_until' => null,
            // LAUNCH-P4 — sold at the given branch only.
            'branch_scope' => 'selected',
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
        DB::table('pos_branch_product')->insert([
            'branch_id' => $branchId,
            'product_id' => $productId,
            'is_available' => true,
            'stock_qty' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $productId;
    }

    /** @param array<string, mixed> $payload */
    private function postAs(Device $device, string $url, array $payload = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken((string) $device->plainTextToken)->postJson($url, $payload);
    }

    private function qrStatus(QrSession $session, string $secret): TestResponse
    {
        return $this->withHeaders([
            'X-QR-Session' => $session->uuid,
            'X-QR-Client-Secret' => $secret,
        ])->getJson(self::STATUS_URL);
    }

    /** @return array<string, mixed> */
    private function genericBindFailure(): array
    {
        return [
            'data' => null,
            'errors' => [[
                'code' => 'qr_bind_failed',
                'message' => 'QR session could not be bound.',
            ]],
        ];
    }
}
