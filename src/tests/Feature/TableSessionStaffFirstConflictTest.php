<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\OpenDineInTableAction;
use App\Actions\Qr\SubmitDineInQrRoundAction;
use App\Actions\Tables\AppendStaffRoundAction;
use App\Actions\Tables\OpenStaffTableSessionAction;
use App\Models\Order;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/** Desired invariants: these expose remaining Revision 2 contract defects. */
final class TableSessionStaffFirstConflictTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-05 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_staff_first_then_customer_first_round_must_keep_exactly_one_bill_on_qr_seating(): void
    {
        $station = $this->seatingDevice('payment_station');
        $till = $this->seatingDevice();
        $table = $this->seatingTable();
        $product = $this->seatingProduct();
        $openedQr = app(OpenDineInTableAction::class)->handle($station, (int) $table->id);
        $session = app(BindQrTableSessionAction::class)->handle($openedQr['table_token'], 'staff-first-conflict-secret');
        $this->assertNotNull($session);
        $key = '33333333-3333-4333-8333-333333333333';
        $common = ['seating_key' => $key, 'table_id' => (int) $table->id, 'queued_offline' => false];
        $staffOpen = app(OpenStaffTableSessionAction::class)->handle($till, $common + ['opened_at' => now()->toIso8601String()], now(), now());
        $staffRound = app(AppendStaffRoundAction::class)->handle($till, $common + [
            'client_request_id' => 'staff-first-round', 'submitted_at' => now()->toIso8601String(),
            'lines' => [['product_id' => (int) $product->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
        ], now(), now());
        $seating = TableSession::findOrFail($session->table_session_id);
        $before = $this->billSnapshot();
        $customerRound = app(SubmitDineInQrRoundAction::class)->handle((int) $session->id, [
            'client_request_id' => 'customer-first-round', 'phone' => '92001234',
            'lines' => [['product_id' => (int) $product->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
        ], '127.0.0.1');
        $evidence = [
            'staff_open_outcome' => $staffOpen['outcome'], 'staff_round_outcome' => $staffRound['outcome'],
            'staff_order_uuid' => $staffRound['order_uuid'], 'customer_order_uuid' => $customerRound['order']->uuid,
            'customer_round_status' => $customerRound['round']->status,
            'before_orders' => $before, 'after_orders' => $this->billSnapshot(),
            'seating_id' => (int) $seating->id, 'seating_order_id' => (int) $seating->fresh()->order_id,
        ];
        fwrite(STDOUT, "\nT4_STAFF_FIRST_CONFLICT_JSON=".json_encode($evidence, JSON_THROW_ON_ERROR)."\n");

        $this->assertSame(1, Order::query()->where('table_session_id', $seating->id)->count(), 'One seating must not gain separate staff and QR bills.');
        $this->assertSame($staffRound['order_uuid'], $customerRound['order']->uuid);
    }

    public function test_valid_offline_round_whose_product_was_deactivated_must_not_leave_failed_outbox_ack(): void
    {
        $till = $this->seatingDevice();
        $table = $this->seatingTable();
        $product = $this->seatingProduct();
        $key = '44444444-4444-4444-8444-444444444444';
        app(OpenStaffTableSessionAction::class)->handle($till, [
            'seating_key' => $key, 'table_id' => (int) $table->id, 'queued_offline' => false,
            'opened_at' => now()->toIso8601String(),
        ], now(), now());
        $submittedAt = now()->toIso8601String();
        $this->travel(10)->minutes();
        $product->update(['status' => 'inactive']);
        $response = $this->withToken($till->device_token)->postJson('/api/v1/device/sync/push', [
            'events' => [[
                'client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.round',
                'client_timestamp' => $submittedAt,
                'payload' => [
                    'seating_key' => $key, 'table_id' => (int) $table->id, 'queued_offline' => true,
                    'client_request_id' => 'offline-before-deactivation', 'submitted_at' => $submittedAt,
                    'lines' => [['product_id' => (int) $product->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
                ],
            ]],
        ])->assertOk();
        fwrite(STDOUT, "\nT4_UNAVAILABLE_OFFLINE_ROUND_JSON=".$response->getContent()."\n");

        $response->assertJsonPath('data.results.0.status', 'processed');
    }

    private function billSnapshot(): array
    {
        return Order::query()->orderBy('id')->get(['id', 'uuid', 'source', 'qr_session_id', 'table_session_id', 'grand_total'])
            ->map(static fn (Order $order): array => [
                'id' => (int) $order->id, 'uuid' => $order->uuid, 'source' => $order->source,
                'qr_session_id' => $order->qr_session_id === null ? null : (int) $order->qr_session_id,
                'table_session_id' => $order->table_session_id === null ? null : (int) $order->table_session_id,
                'grand_total' => $order->grand_total,
            ])->all();
    }
}
