<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\CloseTableSessionForOrderAction;
use App\Actions\Qr\EnsureTableSessionForQrSessionAction;
use App\Actions\Qr\ExpireAbandonedTableSessionsAction;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Floor;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\Table as PosTable;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class QrTableSessionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-05 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['qr.dine_in_session_lifetime_hours' => 6]);
        foreach ([10, 20] as $branchId) {
            Branch::query()->create([
                'id' => $branchId, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
                'name' => 'Seating branch '.$branchId, 'status' => 'active',
                'latitude' => null, 'longitude' => null, 'geofence_radius_m' => 500,
            ]);
        }
    }

    public function test_lazy_attachment_preserves_legacy_null_and_nonempty_order_references_and_round_bytes(): void
    {
        $station = $this->device();
        foreach ([Order::STATUS_OPEN, Order::STATUS_HELD, Order::STATUS_AWAITING_PAYMENT] as $status) {
            foreach ([null, 'T-0831-047'] as $reference) {
                $session = $this->qrSession($station, $this->table());
                $order = $this->order($session, $status, $reference);
                $round = $this->round($session, $order);
                $pricedBytes = $round->getRawOriginal('priced_lines');
                $receipt = $order->receipt_number;
                $seating = $this->ensure($session, $order, $station);
                $expectedStatus = $status === Order::STATUS_OPEN ? 'open' : 'billing';
                $this->assertSame($expectedStatus, $seating->status);
                $this->assertSame($reference, $seating->temp_reference);
                $this->assertSame($reference, $order->fresh()->temp_reference);
                $this->assertSame($receipt, $order->fresh()->receipt_number);
                $this->assertSame($session->getRawOriginal('created_at'), $seating->getRawOriginal('opened_at'));
                $this->assertSame($session->getRawOriginal('expires_at'), $seating->getRawOriginal('expires_at'));
                $this->assertSame(
                    $expectedStatus === 'billing' ? $order->getRawOriginal('updated_at') : null,
                    $seating->getRawOriginal('billing_at'),
                );
                $this->assertSame((int) $seating->id, (int) $session->fresh()->table_session_id);
                $this->assertSame((int) $seating->id, (int) $order->fresh()->table_session_id);
                $this->assertSame((int) $seating->id, (int) $round->fresh()->table_session_id);
                $this->assertSame($pricedBytes, $round->fresh()->getRawOriginal('priced_lines'));
                $this->assertSame((int) $order->id, (int) $seating->order_id);
                $this->assertSame((int) $station->id, (int) $seating->openedByDevice()->sole()->id);
                $this->assertSame((int) $session->table_id, (int) $seating->table()->sole()->id);
                $this->assertSame((int) $order->id, (int) $seating->order()->sole()->id);
                $this->assertSame((int) $session->id, (int) $seating->qrSessions()->sole()->id);
                $this->assertSame((int) $round->id, (int) $seating->rounds()->sole()->id);
                $this->assertSame((int) $seating->id, (int) $round->fresh()->tableSession()->sole()->id);
                $this->assertLifecycleInvariants($seating);
                $before = $seating->getRawOriginal();
                $this->travel(1)->minutes();
                $this->assertSame((int) $seating->id, (int) $this->ensure($session, $order, $station)->id);
                $this->assertSame($before, $seating->fresh()->getRawOriginal());
            }
        }
        $this->assertDatabaseCount('pos_temp_reference_sequences', 0);
        $this->assertDatabaseCount('pos_order_sequences', 0);
        $this->assertDatabaseCount('pos_table_sessions', 6);
        $this->assertDatabaseCount('pos_table_session_events', 6);
        $this->assertSame(array_fill(0, 6, 'opened'), DB::table('pos_table_session_events')->orderBy('id')->pluck('event_type')->all());
    }

    public function test_lazy_attachment_without_an_order_allocates_once_and_preserves_credential_lifetime(): void
    {
        $station = $this->device();
        $session = $this->qrSession($station, $this->table());
        $this->travel(10)->minutes();
        $seating = $this->ensure($session, null, $station);
        $this->assertSame('T-0905-001', $seating->temp_reference);
        $this->assertSame('open', $seating->status);
        $this->assertSame('station', $seating->origin);
        $this->assertNull($seating->order_id);
        $this->assertSame($session->getRawOriginal('created_at'), $seating->getRawOriginal('opened_at'));
        $this->assertSame($session->getRawOriginal('expires_at'), $seating->getRawOriginal('expires_at'));
        $this->assertDatabaseHas('pos_temp_reference_sequences', [
            'company_id' => 100, 'branch_id' => 10, 'seq_date' => '2026-09-05', 'next_number' => 2,
        ]);
        $before = $seating->getRawOriginal();
        $this->travel(1)->hours();
        $this->ensure($session, null, $station);
        $this->assertSame($before, $seating->fresh()->getRawOriginal());
        $this->assertDatabaseHas('pos_temp_reference_sequences', [
            'company_id' => 100, 'branch_id' => 10, 'seq_date' => '2026-09-05', 'next_number' => 2,
        ]);
        $this->assertDatabaseCount('pos_order_sequences', 0);
        $this->assertLifecycleInvariants($seating->fresh());
    }

    public function test_existing_seating_tenant_or_table_corruption_throws_and_rolls_back_the_writer_transaction(): void
    {
        $station = $this->device();
        $otherTable = $this->table();
        foreach (['company_id' => 200, 'branch_id' => 20, 'table_id' => $otherTable->id] as $column => $wrongValue) {
            $session = $this->qrSession($station, $this->table());
            $order = $this->order($session);
            $seating = $this->ensure($session, $order, $station);
            $seating->update([$column => $wrongValue]);
            $orderBefore = $order->fresh()->getRawOriginal();
            $seatingBefore = $seating->fresh()->getRawOriginal();
            $thrown = false;
            try {
                DB::transaction(function () use ($session, $order, $station): void {
                    $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
                    $lockedSession = QrSession::query()->lockForUpdate()->findOrFail($session->id);
                    $lockedOrder->update(['grand_total' => '99.000']);
                    app(EnsureTableSessionForQrSessionAction::class)->handle($lockedSession, $lockedOrder, $station);
                });
            } catch (RuntimeException) {
                $thrown = true;
            }
            $this->assertTrue($thrown, $column.' corruption must not silently reassign a seating');
            $this->assertSame($orderBefore, $order->fresh()->getRawOriginal());
            $this->assertSame($seatingBefore, $seating->fresh()->getRawOriginal());
            // Release only this deliberately corrupt fixture's live-table key.
            $seating->update(['status' => 'expired', 'closed_at' => now(), 'close_reason' => 'expired']);
        }
        $this->assertDatabaseCount('pos_table_session_events', 3);
        $this->assertSame(['opened', 'opened', 'opened'], DB::table('pos_table_session_events')->orderBy('id')->pluck('event_type')->all());
    }

    public function test_quick_session_cannot_be_lazily_given_a_seating(): void
    {
        $station = $this->device();
        $session = $this->qrSession($station);
        $before = $session->fresh()->getRawOriginal();
        $thrown = false;
        try {
            $this->ensure($session, null, $station);
        } catch (RuntimeException) {
            $thrown = true;
        }
        $this->assertTrue($thrown);
        $this->assertSame($before, $session->fresh()->getRawOriginal());
        $this->assertDatabaseCount('pos_table_sessions', 0);
        $this->assertDatabaseCount('pos_temp_reference_sequences', 0);
    }

    public function test_clear_closes_a_live_bill_less_seating_after_station_deletion_and_never_rewrites_it(): void
    {
        $station = $this->device();
        $till = $this->device('fixed_pos');
        $table = $this->table();
        $session = $this->qrSession($station, $table);
        $seating = $this->ensure($session, null, $station);
        $openedAt = $seating->getRawOriginal('opened_at');
        $expiresAt = $seating->getRawOriginal('expires_at');
        $station->forceDelete();
        app('auth')->forgetGuards();
        $this->withToken((string) $till->plainTextToken)
            ->postJson('/api/v1/device/qr/clear-table', ['table_id' => $table->id])
            ->assertOk()->assertJsonPath('data.status', 'cleared');
        $seating->refresh();
        $this->assertSame('closed', $seating->status);
        $this->assertSame('cleared', $seating->close_reason);
        $this->assertSame((int) $till->id, (int) $seating->closed_by_device_id);
        $this->assertSame((int) $till->id, (int) $seating->closedByDevice()->sole()->id);
        $this->assertSame(now()->toIso8601String(), $seating->closed_at->toIso8601String());
        $this->assertNull($seating->opened_by_device_id);
        $this->assertSame($openedAt, $seating->getRawOriginal('opened_at'));
        $this->assertSame($expiresAt, $seating->getRawOriginal('expires_at'));
        $this->assertLifecycleInvariants($seating);
        $before = $seating->getRawOriginal();
        $this->travel(1)->days();
        app('auth')->forgetGuards();
        $this->withToken((string) $till->plainTextToken)
            ->postJson('/api/v1/device/qr/clear-table', ['table_id' => $table->id])
            ->assertOk();
        $this->assertSame(0, app(ExpireAbandonedTableSessionsAction::class)->handle(now()));
        $this->assertSame($before, $seating->fresh()->getRawOriginal());
        $this->assertDatabaseCount('pos_table_session_events', 2);
        $this->assertSame(['opened', 'closed'], DB::table('pos_table_session_events')->orderBy('id')->pluck('event_type')->all());
    }

    public function test_terminal_close_is_one_way_and_credential_less_orders_keep_their_seating_link(): void
    {
        $station = $this->device();
        $till = $this->device('fixed_pos');
        foreach (['paid', 'voided'] as $reason) {
            $session = $this->qrSession($station, $this->table());
            $order = $this->order($session, Order::STATUS_AWAITING_PAYMENT);
            $seating = $this->ensure($session, $order, $station);
            $session->delete();
            $this->assertNull($order->fresh()->qr_session_id);
            $billingAt = $seating->getRawOriginal('billing_at');
            $closed = DB::transaction(fn (): int => app(CloseTableSessionForOrderAction::class)->handle(
                Order::query()->lockForUpdate()->findOrFail($order->id), now(), $reason, (int) $till->id,
            ));
            $this->assertSame(1, $closed);
            $seating->refresh();
            $this->assertSame('closed', $seating->status);
            $this->assertSame($reason, $seating->close_reason);
            $this->assertSame($billingAt, $seating->getRawOriginal('billing_at'));
            $this->assertLifecycleInvariants($seating);
            $before = $seating->getRawOriginal();
            $this->travel(1)->minutes();
            $closedAgain = DB::transaction(fn (): int => app(CloseTableSessionForOrderAction::class)->handle(
                Order::query()->lockForUpdate()->findOrFail($order->id), now(), 'cleared', null,
            ));
            $this->assertSame(0, $closedAgain);
            $this->assertSame($before, $seating->fresh()->getRawOriginal());
        }
    }

    public function test_prune_never_expires_any_unpaid_status_or_a_seating_with_a_live_credential(): void
    {
        $station = $this->device();
        $seatings = [];
        foreach ([Order::STATUS_OPEN, Order::STATUS_HELD, Order::STATUS_AWAITING_PAYMENT] as $status) {
            $session = $this->qrSession($station, $this->table());
            $order = $this->order($session, $status);
            $seating = $this->ensure($session, $order, $station);
            $session->delete();
            $seatings[] = $seating;
        }
        $liveSession = $this->qrSession($station, $this->table());
        $seatings[] = $this->ensure($liveSession, null, $station);
        $snapshots = array_map(static fn (TableSession $seating): array => $seating->getRawOriginal(), $seatings);
        $this->travel(7)->hours();
        $this->assertSame(0, app(ExpireAbandonedTableSessionsAction::class)->handle(now()));
        foreach ($seatings as $index => $seating) {
            $this->assertSame($snapshots[$index], $seating->fresh()->getRawOriginal());
            $this->assertLifecycleInvariants($seating->fresh());
        }
    }

    public function test_requests_cannot_choose_a_seating_id_at_open_or_first_round(): void
    {
        $station = $this->device();
        $foreignSession = $this->qrSession($station, $this->table());
        $foreignSeating = $this->ensure($foreignSession, null, $station);
        $foreignBefore = $foreignSeating->getRawOriginal();
        $table = $this->table();
        app('auth')->forgetGuards();
        $opened = $this->withToken((string) $station->plainTextToken)
            ->postJson('/api/v1/device/qr/open-table', [
                'table_id' => $table->id, 'table_session_id' => $foreignSeating->id,
            ])->assertCreated()->assertJsonMissingPath('data.table_session_id');
        $session = QrSession::query()->where('uuid', $opened->json('data.session_uuid'))->sole();
        $this->assertNotSame((int) $foreignSeating->id, (int) $session->table_session_id);
        $this->postJson('/api/v1/public/qr/table-bind', [
            'table_token' => $table->qr_token, 'client_secret' => 'lifecycle-request-secret',
        ])->assertOk();
        $productId = DB::table('pos_products')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'category_id' => null,
            'name' => 'Seating request coffee', 'base_price' => '1.000', 'stock_mode' => 'untracked',
            'display_order' => 1, 'status' => 'active', 'show_on_customer_tablet' => true,
            'is_internal' => false, 'available_from' => null, 'available_until' => null,
            'created_at' => now(), 'updated_at' => now(), 'deleted_at' => null,
        ]);
        DB::table('pos_branch_product')->insert([
            'branch_id' => 10, 'product_id' => $productId, 'is_available' => true, 'stock_qty' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withHeaders([
            'X-QR-Session' => $session->uuid, 'X-QR-Client-Secret' => 'lifecycle-request-secret',
        ])->postJson('/api/v1/public/qr/table-round', [
            'client_request_id' => 'seating-request-injection', 'phone' => '92000071',
            'table_session_id' => $foreignSeating->id,
            'lines' => [['product_id' => $productId, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
        ])->assertCreated()->assertJsonMissingPath('data.order.table_session_id')
            ->assertJsonMissingPath('data.round.table_session_id');
        $order = Order::query()->where('qr_session_id', $session->id)->sole();
        $round = QrOrderRound::query()->where('qr_session_id', $session->id)->sole();
        $this->assertSame((int) $session->table_session_id, (int) $order->table_session_id);
        $this->assertSame((int) $session->table_session_id, (int) $round->table_session_id);
        $this->assertSame($foreignBefore, $foreignSeating->fresh()->getRawOriginal());
        $this->assertDatabaseCount('pos_table_sessions', 2);
    }

    public function test_retention_pruning_deletes_the_terminal_credential_but_never_the_seating(): void
    {
        $station = $this->device();
        $till = $this->device('fixed_pos');
        $table = $this->table();
        $session = $this->qrSession($station, $table);
        $seating = $this->ensure($session, null, $station);
        app('auth')->forgetGuards();
        $this->withToken((string) $till->plainTextToken)
            ->postJson('/api/v1/device/qr/clear-table', ['table_id' => $table->id])
            ->assertOk()->assertJsonPath('data.status', 'cleared');
        $before = $seating->fresh()->getRawOriginal();
        $this->assertSame('closed', $before['status']);
        $this->travel(31)->days();
        $this->artisan('qr:prune-sessions')
            ->expectsOutput('expired=0 deleted=1 seatings_expired=0')
            ->assertSuccessful();
        $this->assertDatabaseMissing('pos_qr_sessions', ['id' => $session->id]);
        $this->assertDatabaseCount('pos_table_sessions', 1);
        $this->assertSame($before, $seating->fresh()->getRawOriginal());
        $this->assertLifecycleInvariants($seating->fresh());
    }

    private function device(string $type = 'payment_station'): Device
    {
        return Device::factory()->paired()->create([
            'company_id' => 100, 'branch_id' => 10, 'device_type' => $type,
        ]);
    }

    private function table(): PosTable
    {
        $floor = Floor::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'name' => 'Lifecycle floor', 'display_order' => 1, 'status' => 'active',
        ]);

        return PosTable::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'floor_id' => $floor->id,
            'label' => 'LIFECYCLE-'.Str::random(8), 'seats' => 4, 'shape' => 'square',
            'qr_token' => hash('sha256', (string) Str::uuid()), 'status' => 'active', 'display_order' => 1,
        ]);
    }

    private function qrSession(Device $station, ?PosTable $table = null): QrSession
    {
        return QrSession::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $station->id, 'table_id' => $table?->id,
            'token' => hash('sha256', (string) Str::uuid()), 'token_expires_at' => now()->addMinute(),
            'client_secret_hash' => QrSession::hashClientSecret('lifecycle-secret'),
            'status' => QrSession::STATUS_ACTIVE, 'bound_at' => now(),
            'expires_at' => now()->addHours(6),
        ]);
    }

    private function order(
        QrSession $session,
        string $status = Order::STATUS_OPEN,
        ?string $reference = null,
    ): Order {
        return Order::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $session->device_id, 'qr_session_id' => $session->id,
            'table_id' => $session->table_id, 'order_type' => 'dine_in',
            'status' => $status, 'source' => Order::SOURCE_QR_WEB,
            'client_request_id' => (string) Str::uuid(), 'temp_reference' => $reference,
            'receipt_number' => 'LEGACY-0047', 'subtotal' => '1.000', 'discount_total' => '0.000',
            'comp_total' => '0.000', 'tax_total' => '0.000', 'grand_total' => '1.000', 'opened_at' => now(),
        ]);
    }

    private function round(QrSession $session, Order $order): QrOrderRound
    {
        return QrOrderRound::query()->create([
            'qr_session_id' => $session->id, 'order_id' => $order->id, 'round_no' => 1,
            'status' => QrOrderRound::STATUS_ACCEPTED, 'client_request_id' => (string) Str::uuid(),
            'priced_lines' => [['line_total_baisas' => 1000]], 'subtotal_baisas' => 1000,
            'tax_baisas' => 0, 'total_baisas' => 1000, 'submitted_at' => now(), 'resolved_at' => now(),
        ]);
    }

    private function ensure(QrSession $session, ?Order $order, Device $device): TableSession
    {
        return DB::transaction(function () use ($session, $order, $device): TableSession {
            $lockedOrder = $order === null ? null : Order::query()->lockForUpdate()->findOrFail($order->id);
            $lockedSession = QrSession::query()->lockForUpdate()->findOrFail($session->id);

            return app(EnsureTableSessionForQrSessionAction::class)
                ->handle($lockedSession, $lockedOrder, $device)->refresh();
        });
    }

    private function assertLifecycleInvariants(TableSession $seating): void
    {
        $terminal = in_array($seating->status, ['closed', 'expired'], true);
        $this->assertSame($terminal, $seating->closed_at !== null);
        if (in_array($seating->status, ['open', 'billing'], true)) {
            $this->assertNull($seating->closed_at);
            $this->assertNull($seating->close_reason);
        }
        if ($seating->billing_at !== null) {
            $this->assertContains($seating->status, ['billing', 'closed', 'expired']);
        }
    }
}
