<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\OpenDineInTableAction;
use App\Actions\Qr\SubmitDineInQrRoundAction;
use App\Actions\Tables\AppendStaffRoundAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\Table;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class SharedTableSnapshotWriteGuardTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function event(Order $order, string $type, int $productId, ?int $target = null): array
    {
        return [
            'client_event_id' => (string) Str::uuid(), 'event_type' => $type,
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order' => [
                    'uuid' => $order->uuid, 'order_type' => 'dine_in', 'source' => 'main_pos',
                    'table_id' => $order->table_id, 'opened_at' => now()->toIso8601String(),
                    'subtotal_baisas' => 1000, 'discount_total_baisas' => 0,
                    'tax_total_baisas' => 0, 'grand_total_baisas' => 1000,
                    'lines' => [['product_id' => $productId, 'qty' => 1,
                        'unit_price_baisas' => 1000, 'line_total_baisas' => 1000]],
                ],
                'target_device_id' => $target,
            ],
        ];
    }

    private function businessRows(): array
    {
        $result = [];
        foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'pos_%' ORDER BY name") as $table) {
            // HTTP authentication and the sync envelope keep their usual audit.
            // Every business row, including pricing/rounds/stock/journal, is compared.
            if (in_array($table->name, ['pos_sync_events', 'pos_devices'], true)) {
                continue;
            }
            $rows = DB::table($table->name)->get()
                ->map(static fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows);
            $result[$table->name] = $rows;
        }

        return $result;
    }

    private function sharedBill(Device $device, Table $table, int $productId): Order
    {
        $seat = $this->seatingRow($table);
        foreach (['first-staff-round', 'second-staff-round'] as $id) {
            app(AppendStaffRoundAction::class)->handle($device, [
                'seating_key' => (string) Str::uuid(), 'table_id' => $table->id,
                'queued_offline' => false, 'client_request_id' => $id,
                'submitted_at' => now()->toIso8601String(),
                'lines' => [['product_id' => $productId, 'qty' => 1, 'notes' => $id]],
            ], now(), now(), $seat->uuid);
        }

        return Order::findOrFail($seat->fresh()->order_id);
    }

    public static function writers(): iterable
    {
        foreach (['fixed_pos', 'handheld'] as $device) {
            foreach (['order.hold', 'order.create', 'order.transfer'] as $type) {
                foreach (['open', 'held'] as $status) {
                    yield "$device/$type/$status" => [$device, $type, $status];
                }
            }
        }
    }

    #[DataProvider('writers')]
    public function test_late_snapshot_cannot_replace_a_shared_bill_or_its_round_owned_items(string $deviceType, string $type, string $status): void
    {
        $device = $this->seatingDevice($deviceType);
        $target = $this->seatingDevice($deviceType === 'fixed_pos' ? 'handheld' : 'fixed_pos');
        $table = $this->seatingTable();
        $product = $this->seatingProduct();
        $order = $this->sharedBill($device, $table, $product->id);
        $order->update(['status' => $status, 'receipt_number' => 'KEEP-REF', 'note' => 'KEEP-NOTE']);
        $this->assertSame('2.000', (string) $order->fresh()->grand_total);
        $this->assertSame(2, QrOrderRound::where('order_id', $order->id)->count());
        $before = $this->businessRows();
        $event = $this->event($order, $type, $product->id, $target->id);

        foreach ([1, 2] as $attempt) {
            $this->app['auth']->forgetGuards();
            $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [$event]])
                ->assertOk()->assertJsonPath('data.results.0.status', 'failed')
                ->assertJsonPath('data.results.0.result.error', 'shared_table_snapshot_replacement_forbidden');
            $this->assertSame($before, $this->businessRows(), "Attempt $attempt cannot purge children or change any business row.");
        }
    }

    public static function ownershipEvidence(): iterable
    {
        foreach (['order', 'seating', 'round'] as $evidence) {
            foreach (['order.hold', 'order.create', 'order.transfer'] as $type) {
                yield "$evidence/$type" => [$evidence, $type];
            }
        }
    }

    #[DataProvider('ownershipEvidence')]
    public function test_partial_or_historical_links_still_fail_closed(string $evidence, string $type): void
    {
        $device = $this->seatingDevice();
        $target = $this->seatingDevice('handheld');
        $table = $this->seatingTable();
        $product = $this->seatingProduct();
        $order = $this->sharedBill($device, $table, $product->id);
        $seat = TableSession::findOrFail($order->table_session_id);
        // Each test leaves exactly one independent indication of shared history.
        if ($evidence !== 'order') {
            $order->update(['table_session_id' => null]);
        }
        if ($evidence !== 'seating') {
            $seat->update(['order_id' => null]);
        } else {
            $seat->update(['status' => 'closed', 'closed_at' => now(), 'close_reason' => 'cleared']);
        }
        if ($evidence !== 'round') {
            QrOrderRound::where('order_id', $order->id)->update(['table_session_id' => null]);
        }
        $before = $this->businessRows();
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', [
            'events' => [$this->event($order, $type, $product->id, $target->id)],
        ])->assertOk()->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.0.result.error', 'shared_table_snapshot_replacement_forbidden');
        $this->assertSame($before, $this->businessRows());
    }

    public static function legacyWriters(): array
    {
        return ['hold' => ['order.hold'], 'create' => ['order.create'], 'transfer' => ['order.transfer']];
    }

    public static function payloadDisguises(): array
    {
        return ['quick' => [['order_type' => 'quick']], 'no table' => [['table_id' => null]],
            'source' => [['source' => 'handheld']]];
    }

    #[DataProvider('payloadDisguises')]
    public function test_incoming_fields_cannot_hide_existing_shared_ownership(array $change): void
    {
        $device = $this->seatingDevice();
        $product = $this->seatingProduct();
        $order = $this->sharedBill($device, $this->seatingTable(), $product->id);
        $event = $this->event($order, 'order.hold', $product->id);
        $event['payload']['order'] = array_replace($event['payload']['order'], $change);
        $before = $this->businessRows();
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [$event]])
            ->assertOk()->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.0.result.error', 'shared_table_snapshot_replacement_forbidden');
        $this->assertSame($before, $this->businessRows());
    }

    public static function attendedDevices(): array
    {
        return ['till' => ['fixed_pos'], 'handheld' => ['handheld']];
    }

    #[DataProvider('attendedDevices')]
    public function test_real_customer_adoption_and_followup_rounds_survive_late_staff_snapshot(string $type): void
    {
        $device = $this->seatingDevice($type);
        $station = $this->seatingDevice('payment_station');
        $table = $this->seatingTable();
        $product = $this->seatingProduct();
        $open = app(OpenDineInTableAction::class)->handle($station, $table->id);
        $credential = app(BindQrTableSessionAction::class)->handle($open['table_token'], 'synthetic-secret');
        $seat = TableSession::findOrFail($credential->table_session_id);
        $line = ['product_id' => $product->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null];
        $staff = app(AppendStaffRoundAction::class)->handle($device, [
            'seating_key' => (string) Str::uuid(), 'table_id' => $table->id,
            'queued_offline' => false, 'client_request_id' => 'staff-before-customer',
            'submitted_at' => now()->toIso8601String(), 'lines' => [$line],
        ], now(), now(), $seat->uuid);
        app(SubmitDineInQrRoundAction::class)->handle($credential->id, [
            'client_request_id' => 'customer-adoption', 'phone' => '99000000', 'lines' => [$line],
        ], '127.0.0.1');
        $order = Order::sole();
        $this->assertSame($staff['order_uuid'], $order->uuid);
        $this->assertSame('qr_web', $order->source);
        $this->assertSame(2, QrOrderRound::where('order_id', $order->id)->count());
        $before = $this->businessRows();
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', [
            'events' => [$this->event($order, 'order.create', $product->id)],
        ])->assertOk()->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.0.result.error', 'shared_table_snapshot_replacement_forbidden');
        $this->assertSame($before, $this->businessRows());
        // The guarded append-only path remains usable, on the same UUID.
        $next = app(AppendStaffRoundAction::class)->handle($device, [
            'seating_key' => (string) Str::uuid(), 'table_id' => $table->id,
            'queued_offline' => false, 'client_request_id' => 'staff-after-customer',
            'submitted_at' => now()->toIso8601String(), 'lines' => [$line],
        ], now(), now(), $seat->uuid);
        $this->assertSame('appended', $next['outcome']);
        $this->assertSame($order->uuid, $next['order_uuid']);
        $this->assertSame(1, Order::count());
        $this->assertSame([1, 2, 3], QrOrderRound::where('order_id', $order->id)->orderBy('id')->pluck('round_no')->all());
    }

    public function test_already_processed_hold_replay_remains_a_no_write_ack_after_linking(): void
    {
        $device = $this->seatingDevice();
        $product = $this->seatingProduct();
        $table = $this->seatingTable();
        $prototype = new Order(['uuid' => (string) Str::uuid(), 'table_id' => $table->id]);
        $event = $this->event($prototype, 'order.hold', $product->id);
        $first = $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [$event]])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.duplicate', false);
        $order = Order::sole();
        $seat = $this->seatingRow($table, ['order_id' => $order->id]);
        $order->update(['table_session_id' => $seat->id]);
        $this->seatingRound($seat, $order);
        $before = $this->businessRows();
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [$event]])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.duplicate', true)
            ->assertJsonPath('data.results.0.result', $first->json('data.results.0.result'));
        $this->assertSame($before, $this->businessRows());
    }

    #[DataProvider('legacyWriters')]
    public function test_ordinary_unshared_held_bill_keeps_existing_replace_semantics(string $type): void
    {
        $device = $this->seatingDevice();
        $target = $this->seatingDevice('handheld');
        $product = $this->seatingProduct();
        $table = $this->seatingTable();
        $order = Order::create([
            'uuid' => (string) Str::uuid(), 'company_id' => $device->company_id,
            'branch_id' => $device->branch_id, 'device_id' => $device->id,
            'table_id' => $table->id, 'table_session_id' => null, 'order_type' => 'dine_in',
            'status' => 'held', 'source' => 'handheld', 'subtotal' => '0.000',
            'discount_total' => '0.000', 'tax_total' => '0.000', 'grand_total' => '0.000',
            'opened_at' => now(),
        ]);
        $response = $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', [
            'events' => [$this->event($order, $type, $product->id, $target->id)],
        ])->assertOk()->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.status', 'updated');
        $this->assertSame(1, Order::count());
        $this->assertSame('1.000', (string) $order->fresh()->grand_total);
        $this->assertSame(1, $order->items()->count());
        $this->assertSame($type === 'order.create' ? 'open' : 'held', $order->fresh()->status);
        if ($type === 'order.transfer') {
            $response->assertJsonPath('data.results.0.result.transferred_to_device_id', $target->id);
        }
    }
}
