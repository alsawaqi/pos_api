<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\OpenDineInTableAction;
use App\Actions\Qr\SubmitDineInQrRoundAction;
use App\Actions\Tables\AppendStaffRoundAction;
use App\Actions\Tables\OpenStaffTableSessionAction;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\TableSession;
use App\Models\TableSessionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/** The owner's §0.2(a) choice, including immutable case-17 refusal assertions. */
final class StationOpenOnStaffSeatingTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-05 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
        Cache::flush();
    }

    public function test_case_16_station_attaches_to_waiters_bill_and_customer_adopts_one_identity(): void
    {
        $flow = $this->staffFlow(true, false);
        $bill = $flow['order'];
        $reference = $bill->temp_reference;
        $counterBefore = (array) DB::table('pos_temp_reference_sequences')->sole();
        $seatingBefore = $flow['seating']->fresh()->getRawOriginal();
        $staffItemBefore = OrderItem::query()->sole()->getRawOriginal();
        $this->assertSame('main_pos', $bill->source);
        $this->assertNull($bill->qr_session_id);
        $this->assertNull($bill->customer_id);
        $response = $this->openAs($flow['station'], (int) $flow['table']->id)->assertCreated();
        $this->assertSame(['session_uuid', 'table_token', 'expires_at'], array_keys($response->json('data')));
        $this->assertSame(1, TableSession::query()->count());
        $this->assertSame(1, QrSession::query()->count());
        $session = QrSession::query()->sole();
        $this->assertSame((int) $flow['seating']->id, (int) $session->table_session_id);
        $this->assertSame((int) $flow['station']->id, (int) $session->device_id);
        $this->assertSame(QrSession::STATUS_PENDING, $session->status);
        $this->assertSame($seatingBefore, $flow['seating']->fresh()->getRawOriginal());
        $this->assertSame($counterBefore, (array) DB::table('pos_temp_reference_sequences')->sole());
        $attached = TableSessionEvent::query()->where('event_type', 'attached')->sole();
        $this->assertSame(true, $attached->payload['station_attach']);
        $this->assertSame($session->uuid, $attached->payload['session_uuid']);
        $bound = app(BindQrTableSessionAction::class)->handle($response->json('data.table_token'), 'staff-seating-same-bill');
        $this->assertNotNull($bound);
        $this->assertSame((int) $session->id, (int) $bound->id);
        $result = app(SubmitDineInQrRoundAction::class)->handle((int) $bound->id, [
            'client_request_id' => 'customer-after-waiter', 'phone' => '92004321',
            'lines' => [['product_id' => (int) $flow['product']->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
        ], '127.0.0.1');
        $this->assertSame($bill->uuid, $result['order']->uuid);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, TableSession::query()->count());
        $this->assertSame('qr_web', $result['order']->source);
        $this->assertSame((int) $bound->id, (int) $result['order']->qr_session_id);
        $this->assertSame((int) $flow['station']->id, (int) $result['order']->device_id);
        $this->assertSame('92004321', Customer::findOrFail($result['order']->customer_id)->phone);
        $this->assertNull($result['order']->client_request_id);
        $this->assertSame($reference, $result['order']->temp_reference);
        $this->assertSame($reference, $flow['seating']->fresh()->temp_reference);
        $this->assertSame('2.000', $result['order']->grand_total);
        $this->assertSame($staffItemBefore, OrderItem::findOrFail($staffItemBefore['id'])->getRawOriginal());
        $this->assertSame(2, QrOrderRound::query()->where('order_id', $bill->id)->count());
        $this->assertSame($counterBefore, (array) DB::table('pos_temp_reference_sequences')->sole());
        fwrite(STDOUT, "\nT4_STATION_CASE_16_JSON=".json_encode([
            'open_response' => $response->json(), 'seating_count' => TableSession::query()->count(),
            'order_count' => Order::query()->count(), 'order_uuid' => $result['order']->uuid,
            'seating_uuid' => $flow['seating']->uuid, 'source' => $result['order']->source,
            'qr_session_id' => $result['order']->qr_session_id, 'customer_id' => $result['order']->customer_id,
            'temp_reference' => $reference, 'grand_total' => $result['order']->grand_total,
        ], JSON_THROW_ON_ERROR)."\n");
    }

    /** @return array<string, array{bool}> */
    public static function joinedBillStates(): array
    {
        return ['shared bill exists' => [true], 'party is still bill-less' => [false]];
    }

    #[DataProvider('joinedBillStates')]
    public function test_case_17_joined_member_refuses_without_expiring_it_or_creating_a_credential(bool $hasBill): void
    {
        $flow = $this->staffFlow($hasBill, true);
        $before = TableSession::query()->orderBy('id')->get()->map->getRawOriginal()->all();
        $journalBefore = TableSessionEvent::query()->count();
        $counterBefore = (array) DB::table('pos_temp_reference_sequences')->sole();
        $response = $this->openAs($flow['station'], (int) $flow['joinedTable']->id);
        fwrite(STDOUT, "\nT4_STATION_CASE_17_JSON=".json_encode([
            'has_bill' => $hasBill, 'http_status' => $response->status(),
            'error_code' => $response->json('errors.0.code'), 'message' => $response->json('message'),
            'session_count' => QrSession::query()->count(),
            'seatings' => TableSession::query()->orderBy('id')->get(['id', 'table_id', 'status', 'merged_into_id', 'order_id'])->toArray(),
        ], JSON_THROW_ON_ERROR)."\n");
        $response->assertConflict()->assertJsonPath('errors.0.code', 'qr_table_joined');
        $this->assertSame(0, QrSession::query()->count());
        $this->assertSame($hasBill ? 1 : 0, Order::query()->count());
        $this->assertSame($before, TableSession::query()->orderBy('id')->get()->map->getRawOriginal()->all());
        $this->assertSame($journalBefore, TableSessionEvent::query()->count());
        $this->assertSame($counterBefore, (array) DB::table('pos_temp_reference_sequences')->sole());
    }

    public function test_free_table_keeps_the_original_station_seating_and_response_shape(): void
    {
        $station = $this->seatingDevice('payment_station');
        $table = $this->seatingTable();
        $response = $this->openAs($station, (int) $table->id)->assertCreated();
        $this->assertSame(['session_uuid', 'table_token', 'expires_at'], array_keys($response->json('data')));
        $this->assertSame($table->qr_token, $response->json('data.table_token'));
        $this->assertSame('2026-09-05T18:00:00+00:00', $response->json('data.expires_at'));
        $session = QrSession::query()->sole();
        $seating = TableSession::query()->sole();
        $this->assertSame((int) $seating->id, (int) $session->table_session_id);
        $this->assertSame(TableSession::ORIGIN_STATION, $seating->origin);
        $this->assertSame(TableSession::STATUS_OPEN, $seating->status);
        $this->assertSame('T-0905-001', $seating->temp_reference);
        $this->assertNull($seating->order_id);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(['opened'], TableSessionEvent::query()->pluck('event_type')->all());
    }

    public function test_live_credential_refusal_keeps_its_original_code_and_changes_nothing(): void
    {
        $station = $this->seatingDevice('payment_station');
        $table = $this->seatingTable();
        app(OpenDineInTableAction::class)->handle($station, (int) $table->id);
        $sessionBefore = QrSession::query()->sole()->getRawOriginal();
        $seatingBefore = TableSession::query()->sole()->getRawOriginal();
        $this->openAs($station, (int) $table->id)
            ->assertConflict()->assertJsonPath('errors.0.code', 'qr_table_already_open');
        $this->assertSame($sessionBefore, QrSession::query()->sole()->getRawOriginal());
        $this->assertSame($seatingBefore, TableSession::query()->sole()->getRawOriginal());
        $this->assertSame(1, TableSessionEvent::query()->count());
    }

    /** @return array<string, array{string}> */
    public static function frozenBills(): array
    {
        return ['held' => [Order::STATUS_HELD], 'awaiting payment' => [Order::STATUS_AWAITING_PAYMENT]];
    }

    #[DataProvider('frozenBills')]
    public function test_station_does_not_attach_to_the_live_seatings_frozen_bill(string $status): void
    {
        $flow = $this->staffFlow(true, false);
        $flow['order']->update(['status' => $status]);
        $flow['seating']->update(['status' => TableSession::STATUS_BILLING, 'billing_at' => now()]);
        $billBefore = $flow['order']->fresh()->getRawOriginal();
        $seatingBefore = $flow['seating']->fresh()->getRawOriginal();
        $this->openAs($flow['station'], (int) $flow['table']->id)
            ->assertConflict()->assertJsonPath('errors.0.code', 'qr_table_has_unpaid_order');
        $this->assertSame(0, QrSession::query()->count());
        $this->assertSame($billBefore, $flow['order']->fresh()->getRawOriginal());
        $this->assertSame($seatingBefore, $flow['seating']->fresh()->getRawOriginal());
    }

    /** @return array<string, array{string}> */
    public static function unavailableQrCredentials(): array
    {
        return ['expired QR credential' => ['expired'], 'hard-deleted QR credential' => ['deleted']];
    }

    #[DataProvider('unavailableQrCredentials')]
    public function test_open_qr_bill_is_not_reclassified_as_staff_when_its_credential_is_unavailable(string $credentialState): void
    {
        $station = $this->seatingDevice('payment_station');
        $table = $this->seatingTable();
        $product = $this->seatingProduct();
        $opened = app(OpenDineInTableAction::class)->handle($station, (int) $table->id);
        $session = app(BindQrTableSessionAction::class)->handle($opened['table_token'], 'unavailable-qr-bill-secret');
        $this->assertNotNull($session);
        $result = app(SubmitDineInQrRoundAction::class)->handle((int) $session->id, [
            'client_request_id' => 'qr-bill-before-credential-loss', 'phone' => '92004322',
            'lines' => [['product_id' => (int) $product->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
        ], '127.0.0.1');
        $bill = $result['order'];
        $seating = TableSession::findOrFail($session->table_session_id);
        if ($credentialState === 'expired') {
            $session->update(['status' => QrSession::STATUS_EXPIRED, 'expires_at' => now()->subSecond()]);
        } else {
            // SQLite fixture only: exercise the existing null-on-delete bill
            // FK and cascading QR rounds, without changing the QR bill source.
            $session->delete();
        }
        $billBefore = $bill->fresh()->getRawOriginal();
        $seatingBefore = $seating->fresh()->getRawOriginal();
        $journalBefore = TableSessionEvent::query()->count();
        $this->assertSame(Order::STATUS_OPEN, $bill->fresh()->status);
        $this->assertSame(Order::SOURCE_QR_WEB, $bill->fresh()->source);
        $this->assertSame($credentialState === 'expired' ? (int) $session->id : null, $bill->fresh()->qr_session_id);
        $this->openAs($station, (int) $table->id)
            ->assertConflict()->assertJsonPath('errors.0.code', 'qr_table_has_unpaid_order');
        $this->assertSame($billBefore, $bill->fresh()->getRawOriginal());
        $this->assertSame($seatingBefore, $seating->fresh()->getRawOriginal());
        $this->assertSame($journalBefore, TableSessionEvent::query()->count());
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, TableSession::query()->count());
        $this->assertSame($credentialState === 'expired' ? 1 : 0, QrSession::query()->count());
        $this->assertSame($credentialState === 'expired' ? 1 : 0, QrOrderRound::query()->count());
    }

    public function test_unlinked_legacy_unpaid_bill_still_refuses_even_when_live_seating_owns_an_open_bill(): void
    {
        $flow = $this->staffFlow(true, false);
        $legacy = Order::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $flow['till']->id, 'table_id' => $flow['table']->id,
            'source' => 'main_pos', 'order_type' => 'dine_in', 'status' => Order::STATUS_OPEN,
            'subtotal' => '1.000', 'discount_total' => '0.000', 'comp_total' => '0.000',
            'tax_total' => '0.000', 'grand_total' => '1.000', 'opened_at' => now(),
        ]);
        $before = $legacy->fresh()->getRawOriginal();
        $this->openAs($flow['station'], (int) $flow['table']->id)
            ->assertConflict()->assertJsonPath('errors.0.code', 'qr_table_has_unpaid_order');
        $this->assertSame(0, QrSession::query()->count());
        $this->assertSame(2, Order::query()->count());
        $this->assertSame($before, $legacy->fresh()->getRawOriginal());
        $this->assertSame(TableSession::STATUS_OPEN, $flow['seating']->fresh()->status);
        $this->assertNull($flow['order']->fresh()->qr_session_id);
    }

    public function test_t3_supersede_stays_first_for_a_billless_credentialless_primary_seating(): void
    {
        $flow = $this->staffFlow(false, false);
        $old = $flow['seating'];
        $this->assertSame('T-0905-001', $old->temp_reference);
        $this->openAs($flow['station'], (int) $flow['table']->id)->assertCreated();
        $this->assertSame(TableSession::STATUS_EXPIRED, $old->fresh()->status);
        $this->assertSame(TableSession::CLOSE_ABANDONED, $old->fresh()->close_reason);
        $this->assertSame(now()->toIso8601String(), $old->fresh()->closed_at->toIso8601String());
        $session = QrSession::query()->sole();
        $new = TableSession::findOrFail($session->table_session_id);
        $this->assertNotSame((int) $old->id, (int) $new->id);
        $this->assertSame(TableSession::ORIGIN_STATION, $new->origin);
        $this->assertSame('T-0905-002', $new->temp_reference);
        $this->assertSame(0, Order::query()->count());
        $this->assertSame(['opened', 'expired', 'opened'], TableSessionEvent::query()->orderBy('id')->pluck('event_type')->all());
    }

    public function test_t3_expire_then_supersede_still_replaces_an_expired_station_credential(): void
    {
        $station = $this->seatingDevice('payment_station');
        $table = $this->seatingTable();
        $opened = app(OpenDineInTableAction::class)->handle($station, (int) $table->id);
        $oldSession = $opened['session'];
        $oldSeating = TableSession::findOrFail($oldSession->table_session_id);
        $oldSession->update(['expires_at' => now()->subSecond()]);
        $this->openAs($station, (int) $table->id)->assertCreated();
        $this->assertSame(QrSession::STATUS_EXPIRED, $oldSession->fresh()->status);
        $this->assertSame(TableSession::STATUS_EXPIRED, $oldSeating->fresh()->status);
        $this->assertSame(TableSession::CLOSE_ABANDONED, $oldSeating->fresh()->close_reason);
        $this->assertSame(2, QrSession::query()->count());
        $this->assertSame(2, TableSession::query()->count());
        $this->assertSame('T-0905-002', TableSession::query()->latest('id')->firstOrFail()->temp_reference);
        $this->assertSame(['opened', 'expired', 'opened'], TableSessionEvent::query()->orderBy('id')->pluck('event_type')->all());
    }

    private function staffFlow(bool $hasBill, bool $joined): array
    {
        $till = $this->seatingDevice();
        $station = $this->seatingDevice('payment_station');
        $table = $this->seatingTable('Primary table');
        $joinedTable = $joined ? $this->seatingTable('Joined table') : null;
        $product = $this->seatingProduct();
        $common = ['seating_key' => (string) Str::uuid(), 'table_id' => (int) $table->id, 'queued_offline' => false];
        $opened = app(OpenStaffTableSessionAction::class)->handle($till, $common + [
            'opened_at' => now()->toIso8601String(),
            'joined_table_ids' => $joinedTable === null ? [] : [(int) $joinedTable->id],
        ], now(), now());
        $seating = TableSession::query()->where('uuid', $opened['table_session_uuid'])->sole();
        $order = null;
        if ($hasBill) {
            $round = app(AppendStaffRoundAction::class)->handle($till, $common + [
                'client_request_id' => 'waiter-first-round', 'submitted_at' => now()->toIso8601String(),
                'lines' => [['product_id' => (int) $product->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
            ], now(), now());
            $order = Order::query()->where('uuid', $round['order_uuid'])->sole();
        }

        return compact('till', 'station', 'table', 'joinedTable', 'product', 'seating', 'order');
    }

    private function openAs(Device $device, int $tableId): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($device->plainTextToken)->postJson('/api/v1/device/qr/open-table', ['table_id' => $tableId]);
    }
}
