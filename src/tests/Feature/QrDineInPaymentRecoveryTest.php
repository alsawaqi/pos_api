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
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class QrDineInPaymentRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const FINISH_URL = '/api/v1/public/qr/table-finish';

    private const TABLE_ROUND_URL = '/api/v1/public/qr/table-round';

    private const STATUS_URL = '/api/v1/public/qr/status';

    private const TABLE_BIND_URL = '/api/v1/public/qr/table-bind';

    private const OPEN_TABLE_URL = '/api/v1/device/qr/open-table';

    private const BOARD_URL = '/api/v1/device/qr/table-board';

    private const CLEAR_URL = '/api/v1/device/qr/clear-table';

    private const REOPEN_URL = '/api/v1/device/qr/reopen-payment';

    private const AWAITING_URL = '/api/v1/device/qr/awaiting-orders';

    private const CLAIM_URL = '/api/v1/device/qr/claim-charge';

    private const CLAIM_SETTLEMENT_URL = '/api/v1/device/qr/claim-settlement';

    private const RELEASE_URL = '/api/v1/device/qr/release-charge';

    private const FALLBACK_URL = '/api/v1/device/qr/fallback-to-counter';

    private const SYNC_URL = '/api/v1/device/sync/push';

    /** @var list<string> */
    private const CHARGE_FIELDS = [
        'charge_device_id',
        'charge_amount_baisas',
        'charge_roundup_amount_baisas',
        'charge_claimed_at',
        'charge_deadline_at',
        'charge_outcome',
    ];

    private int $deviceSequence = 0;

    private int $orderSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-30 12:00:00'));
        $this->withoutMiddleware(ThrottleRequests::class);
        config([
            'qr.charge_claim_seconds' => 180,
            'qr.settlement_claim_seconds' => 300,
            'qr.charge_sweep_enabled' => false,
            'qr.dine_in_session_lifetime_hours' => 6,
            'qr.station_geofence_exempt' => false,
        ]);

        foreach ([10, 20] as $branchId) {
            Branch::query()->create([
                'id' => $branchId,
                'uuid' => (string) Str::uuid(),
                'company_id' => 100,
                'name' => 'QR2 Branch '.$branchId,
                'latitude' => null,
                'longitude' => null,
                'geofence_radius_m' => 500,
                'status' => 'active',
            ]);
        }
    }

    public function test_finish_without_an_accepted_round_refuses_stably_and_persists_pending_rejection(): void
    {
        $station = $this->device('payment_station');
        $table = $this->table(10, 'ZERO');
        $secret = 'zero-round-secret';
        $session = $this->qrSession($station, $table, QrSession::STATUS_ACTIVE, $secret);
        $pending = $this->round($session, null, QrOrderRound::STATUS_PENDING_CONFIRMATION);

        $this->finish($session, $secret, 'station')
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_dine_in_order_required');

        $pending->refresh();
        $this->assertSame(QrOrderRound::STATUS_REJECTED, $pending->status);
        $this->assertNotNull($pending->resolved_at);
        $this->assertNull($pending->resolved_by_device_id);
        $this->assertSame(QrSession::STATUS_ACTIVE, $session->fresh()->status);
        $this->assertSame(0, Order::query()->where('qr_session_id', $session->id)->count());
        $this->assertNoRoundupArtifacts();
    }

    public function test_counter_and_station_finish_freeze_the_tab_and_reject_pending_rounds(): void
    {
        $station = $this->device('payment_station');

        foreach ([
            'counter' => Order::STATUS_HELD,
            'station' => Order::STATUS_AWAITING_PAYMENT,
        ] as $choice => $expectedStatus) {
            $table = $this->table(10, strtoupper($choice));
            $secret = $choice.'-finish-secret';
            $session = $this->qrSession($station, $table, QrSession::STATUS_ACTIVE, $secret);
            $order = $this->order($station, $table, $session, Order::STATUS_OPEN);
            $accepted = $this->round($session, $order, QrOrderRound::STATUS_ACCEPTED);
            $pending = $this->round($session, null, QrOrderRound::STATUS_PENDING_CONFIRMATION);
            $frozen = $accepted->getRawOriginal('priced_lines');

            $this->finish($session, $secret, $choice)
                ->assertOk()
                ->assertJsonPath('data.order_uuid', $order->uuid)
                ->assertJsonPath('data.status', $expectedStatus)
                ->assertJsonPath('data.receipt_number', $order->receipt_number)
                ->assertJsonPath('data.grand_total_baisas', 4750);

            $this->assertSame($expectedStatus, $order->fresh()->status);
            $this->assertSame(QrSession::STATUS_ORDERED, $session->fresh()->status);
            $this->assertSame($frozen, $accepted->fresh()->getRawOriginal('priced_lines'));
            $this->assertSame(QrOrderRound::STATUS_REJECTED, $pending->fresh()->status);
            $this->assertNotNull($pending->fresh()->resolved_at);
        }

        $this->assertNoRoundupArtifacts();
    }

    public function test_attended_claim_atomically_freezes_and_blocks_every_live_consumer(): void
    {
        $station = $this->device('payment_station');
        $holder = $this->device('fixed_pos');
        $competitor = $this->device('fixed_pos');
        $table = $this->table(10, 'CLAIM-CONSUMERS');
        $secret = 'claim-consumer-secret';
        $session = $this->qrSession($station, $table, QrSession::STATUS_ACTIVE, $secret);
        $order = $this->order($station, $table, $session, Order::STATUS_OPEN);
        $this->round($session, $order, QrOrderRound::STATUS_ACCEPTED);
        $pending = $this->round(
            $session,
            null,
            QrOrderRound::STATUS_PENDING_CONFIRMATION,
        );

        $claimed = $this->claimSettlement($holder, $order)
            ->assertOk()
            ->assertJsonPath('data.order_uuid', $order->uuid)
            ->assertJsonPath('data.status', Order::STATUS_AWAITING_PAYMENT)
            ->assertJsonPath('data.charge_amount_baisas', 4750)
            ->assertJsonPath('data.already_claimed_by_this_device', false);
        $deadline = Carbon::parse((string) $claimed->json('data.charge_deadline_at'));
        $this->assertSame(300, (int) now()->diffInSeconds($deadline, false));
        $frozen = $order->fresh();
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $frozen->status);
        $this->assertSame((int) $holder->id, (int) $frozen->charge_device_id);
        $this->assertSame(4750, (int) $frozen->charge_amount_baisas);
        $this->assertNull($frozen->charge_roundup_amount_baisas);
        $this->assertNull($frozen->charge_outcome);
        $this->assertSame(QrSession::STATUS_ORDERED, $session->fresh()->status);
        $this->assertSame(QrOrderRound::STATUS_REJECTED, $pending->fresh()->status);
        $this->assertSame((int) $holder->id, (int) $pending->fresh()->resolved_by_device_id);
        $this->assertNotNull($pending->fresh()->resolved_at);
        $this->qrStatus($session, $secret)
            ->assertOk()
            ->assertJsonPath('data.dine_in.payment_state', 'awaiting_counter');

        $claimedAt = $frozen->charge_claimed_at;
        $this->claimSettlement($holder, $order)
            ->assertOk()
            ->assertJsonPath('data.already_claimed_by_this_device', true);
        $this->assertTrue($claimedAt->equalTo($order->fresh()->charge_claimed_at));
        $this->assertTrue($deadline->equalTo($order->fresh()->charge_deadline_at));

        $this->claimSettlement($competitor, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_already_claimed');
        $this->claim($station, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_already_claimed');
        $this->assertNull($this->listedOrder(
            $this->getAs($station, self::AWAITING_URL)->assertOk(),
            $order,
        ));

        $this->withHeaders([
            'X-QR-Session' => $session->uuid,
            'X-QR-Client-Secret' => $secret,
        ])->postJson(self::TABLE_ROUND_URL, [
            'client_request_id' => 'blocked-after-attended-claim',
            'lines' => [[
                'product_id' => 999999,
                'qty' => 1,
                'addon_ids' => [],
                'notes' => null,
            ]],
        ])->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_round_session_not_active');
        $this->finish($session, $secret, 'counter')
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_finish_session_not_active');

        $void = $this->push($holder, [$this->voidEvent($order)])->assertOk();
        $this->assertSame('failed', $void->json('data.results.0.status'));
        $this->assertStringContainsString(
            'cannot void an order with a live charge claim',
            (string) $void->json('data.results.0.result.error'),
        );
        $this->postAs($holder, self::CLEAR_URL, ['table_id' => $table->id])
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_table_charge_live');
        $this->postAs($holder, self::REOPEN_URL, ['order_uuid' => $order->uuid])
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_charge_recovery_required');
        $this->postAs($holder, self::FALLBACK_URL, ['order_uuid' => $order->uuid])
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_already_claimed');
        $this->assertSame($frozen->getRawOriginal(), $order->fresh()->getRawOriginal());
    }

    public function test_cancelled_attended_claim_reopens_and_accepts_another_frozen_round(): void
    {
        $productId = $this->seedStationDeletionRoundPrerequisites();
        $station = $this->device('payment_station');
        $till = $this->device('fixed_pos');
        $table = $this->table(10, 'CLAIM-CANCELLED');
        $secret = 'claim-cancelled-secret';
        $session = $this->qrSession($station, $table, QrSession::STATUS_ORDERED, $secret);
        $order = $this->order($station, $table, $session, Order::STATUS_HELD);
        $this->round($session, $order, QrOrderRound::STATUS_ACCEPTED);

        $this->claimSettlement($till, $order)->assertOk();
        $this->postAs($till, self::RELEASE_URL, [
            'order_uuid' => $order->uuid,
            'outcome' => Order::CHARGE_OUTCOME_CANCELLED,
        ])->assertOk()
            ->assertJsonPath('data.charge_outcome', Order::CHARGE_OUTCOME_CANCELLED);
        $this->postAs($till, self::REOPEN_URL, ['order_uuid' => $order->uuid])
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_OPEN)
            ->assertJsonPath('data.session_status', QrSession::STATUS_ACTIVE);
        $this->assertChargeFieldsNull($order->fresh());

        $this->withHeaders([
            'X-QR-Session' => $session->uuid,
            'X-QR-Client-Secret' => $secret,
        ])->postJson(self::TABLE_ROUND_URL, [
            'client_request_id' => 'round-after-cancelled-settlement',
            'lines' => [[
                'product_id' => $productId,
                'qty' => 1,
                'addon_ids' => [],
                'notes' => null,
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.order.grand_total_baisas', 9500);
    }

    public function test_lapsed_attended_claim_enters_ambiguity_and_manager_recovery(): void
    {
        config([
            'qr.settlement_claim_seconds' => 5,
            'qr.charge_sweep_grace_seconds' => 1,
        ]);
        $station = $this->device('payment_station');
        $holder = $this->device('fixed_pos');
        $competitor = $this->device('fixed_pos');
        $table = $this->table(10, 'CLAIM-LAPSED');
        $session = $this->qrSession($station, $table, QrSession::STATUS_ACTIVE);
        $order = $this->order($station, $table, $session, Order::STATUS_OPEN);

        $this->claimSettlement($holder, $order)->assertOk();
        $this->travel(7)->seconds();
        $this->artisan('qr:sweep-stale-charges')->assertSuccessful();
        $this->assertSame(Order::CHARGE_OUTCOME_LAPSED, $order->fresh()->charge_outcome);

        $this->claimSettlement($competitor, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_charge_recovery_required');
        $this->postAs($holder, self::REOPEN_URL, ['order_uuid' => $order->uuid])
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_charge_recovery_required');

        $this->postAs($holder, self::FALLBACK_URL, ['order_uuid' => $order->uuid])
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_HELD);
        $this->assertSame(Order::CHARGE_OUTCOME_LAPSED, $order->fresh()->charge_outcome);
        $this->assertSyncProcessed($this->push($holder, [
            $this->payEvent($order, $this->cashTender()),
        ]));
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(QrSession::STATUS_CLOSED, $session->fresh()->status);
    }

    public function test_uncertain_attended_release_requires_manager_recovery(): void
    {
        $station = $this->device('payment_station');
        $holder = $this->device('fixed_pos');
        $manager = $this->device('handheld');
        $table = $this->table(10, 'CLAIM-UNCERTAIN');
        $session = $this->qrSession($station, $table, QrSession::STATUS_ACTIVE);
        $order = $this->order($station, $table, $session, Order::STATUS_OPEN);

        $this->claimSettlement($holder, $order)->assertOk();
        $this->postAs($holder, self::RELEASE_URL, [
            'order_uuid' => $order->uuid,
            'outcome' => Order::CHARGE_OUTCOME_UNCERTAIN,
        ])->assertOk()
            ->assertJsonPath('data.charge_outcome', Order::CHARGE_OUTCOME_UNCERTAIN);
        $this->claimSettlement($holder, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_already_claimed');
        $this->postAs($holder, self::REOPEN_URL, ['order_uuid' => $order->uuid])
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_charge_recovery_required');

        $this->postAs($manager, self::FALLBACK_URL, ['order_uuid' => $order->uuid])
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_HELD);
        $this->assertSame(Order::CHARGE_OUTCOME_UNCERTAIN, $order->fresh()->charge_outcome);
        $this->assertSyncProcessed($this->push($manager, [
            $this->payEvent($order, $this->cashTender()),
        ]));
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(QrSession::STATUS_CLOSED, $session->fresh()->status);
    }

    public function test_attended_claim_enforces_geofence_and_exact_frozen_tender(): void
    {
        Branch::query()->whereKey(10)->update([
            'latitude' => 23.5880,
            'longitude' => 58.3829,
            'geofence_radius_m' => 100,
        ]);
        $station = $this->device('payment_station');
        $till = $this->device('fixed_pos');
        $table = $this->table(10, 'CLAIM-GEOFENCE');
        $session = $this->qrSession($station, $table, QrSession::STATUS_ACTIVE);
        $order = $this->order($station, $table, $session, Order::STATUS_OPEN);

        $this->claimSettlement($till, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'geofence_fix_required');
        $this->claimSettlement($till, $order, ['lat' => 24.0, 'lng' => 58.3829])
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'geofence_outside');
        $this->claimSettlement($till, $order, ['lat' => 23.5880, 'lng' => 58.3829])
            ->assertOk()
            ->assertJsonPath('data.charge_amount_baisas', 4750);

        $minusOne = $this->cashTender();
        $minusOne['amount_baisas'] = 4749;
        $refused = $this->push($till, [$this->payEvent($order, $minusOne)])->assertOk();
        $this->assertSame('failed', $refused->json('data.results.0.status'));
        $this->assertStringContainsString(
            'charge_amount_baisas 4750',
            (string) $refused->json('data.results.0.result.error'),
        );
        $this->assertSame(0, Payment::query()->where('order_id', $order->id)->count());

        // The live claim paid the fail-closed fence at reservation time. The
        // inherited order.pay path accepts the exact frozen amount.
        $this->assertSyncProcessed($this->push($till, [
            $this->payEvent($order, $this->cashTender()),
        ]));
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_attended_claim_refusal_and_route_contract_is_stable(): void
    {
        $station = $this->device('payment_station');
        $till = $this->device('fixed_pos');

        $eligibleTable = $this->table(10, 'CLAIM-REFUSALS-ELIGIBLE');
        $eligibleSession = $this->qrSession($station, $eligibleTable, QrSession::STATUS_ACTIVE);
        $eligibleOrder = $this->order(
            $station,
            $eligibleTable,
            $eligibleSession,
            Order::STATUS_OPEN,
        );
        $this->claimSettlement($station, $eligibleOrder)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'device_not_attended');
        $this->postAs($till, self::CLAIM_SETTLEMENT_URL, ['order_uuid' => 'not-a-uuid'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed');
        $this->postAs($till, self::CLAIM_SETTLEMENT_URL, [
            'order_uuid' => (string) Str::uuid(),
        ])->assertNotFound()
            ->assertJsonPath('errors.0.code', 'order_not_found');

        $quickSession = $this->qrSession($station, null, QrSession::STATUS_ACTIVE);
        $quickOrder = $this->order($station, null, $quickSession, Order::STATUS_OPEN);
        $this->claimSettlement($till, $quickOrder)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_order_not_settleable');

        $mismatchTable = $this->table(10, 'CLAIM-REFUSALS-MISMATCH');
        $mismatchSession = $this->qrSession($station, $mismatchTable, QrSession::STATUS_ACTIVE);
        $mismatchOrder = $this->order(
            $station,
            $mismatchTable,
            $mismatchSession,
            Order::STATUS_OPEN,
        );
        $otherTable = $this->table(10, 'CLAIM-REFUSALS-OTHER');
        $mismatchOrder->update(['table_id' => $otherTable->id]);
        $this->claimSettlement($till, $mismatchOrder)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'order_not_bound_to_device_session');

        $expiredTable = $this->table(10, 'CLAIM-REFUSALS-EXPIRED');
        $expiredSession = $this->qrSession($station, $expiredTable, QrSession::STATUS_ACTIVE);
        $expiredOrder = $this->order(
            $station,
            $expiredTable,
            $expiredSession,
            Order::STATUS_OPEN,
        );
        $expiredSession->update(['expires_at' => now()->subSecond()]);
        $this->claimSettlement($till, $expiredOrder)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_session_expired');

        $stateTable = $this->table(10, 'CLAIM-REFUSALS-STATE');
        $stateSession = $this->qrSession($station, $stateTable, QrSession::STATUS_PENDING);
        $stateOrder = $this->order($station, $stateTable, $stateSession, Order::STATUS_OPEN);
        $this->claimSettlement($till, $stateOrder)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_session_not_settleable');

        $residueTable = $this->table(10, 'CLAIM-REFUSALS-RESIDUE');
        $residueSession = $this->qrSession($station, $residueTable, QrSession::STATUS_ORDERED);
        $residueOrder = $this->order(
            $station,
            $residueTable,
            $residueSession,
            Order::STATUS_HELD,
            $this->chargeProvenance($station, Order::CHARGE_OUTCOME_LAPSED, false),
        );
        $this->claimSettlement($till, $residueOrder)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_charge_recovery_required');

        $replayTable = $this->table(10, 'CLAIM-REFUSALS-REPLAY');
        $replaySession = $this->qrSession($station, $replayTable, QrSession::STATUS_ACTIVE);
        $replayOrder = $this->order(
            $station,
            $replayTable,
            $replaySession,
            Order::STATUS_OPEN,
        );
        $this->claimSettlement($till, $replayOrder)->assertOk();
        $replaySession->update(['status' => QrSession::STATUS_CLOSED]);
        $this->claimSettlement($till, $replayOrder)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_session_not_settleable');

        $route = Route::getRoutes()->getByName('device.qr.claim-settlement');
        $this->assertNotNull($route);
        $this->assertContains('throttle:qr-settlement-claim', $route->gatherMiddleware());
    }

    public function test_dine_in_payment_is_branch_wide_while_quick_payment_remains_device_pinned(): void
    {
        $openingStation = $this->device('payment_station');
        $claimingStation = $this->device('payment_station');
        $wrongBranch = $this->device('payment_station', 20);
        $table = $this->table(10, 'BRANCH');
        $dineSession = $this->qrSession($openingStation, $table, QrSession::STATUS_ORDERED);
        $dineOrder = $this->order(
            $openingStation,
            $table,
            $dineSession,
            Order::STATUS_AWAITING_PAYMENT,
        );

        $quickSession = $this->qrSession($openingStation, null, QrSession::STATUS_ORDERED);
        $quickOrder = $this->order(
            $openingStation,
            null,
            $quickSession,
            Order::STATUS_AWAITING_PAYMENT,
        );

        $openingList = $this->getAs($openingStation, self::AWAITING_URL)->assertOk();
        $dineRow = $this->listedOrder($openingList, $dineOrder);
        $quickRow = $this->listedOrder($openingList, $quickOrder);
        $this->assertSame('BRANCH', $dineRow['table_label'] ?? null);
        $this->assertArrayNotHasKey('table_label', $quickRow);

        $claimingList = $this->getAs($claimingStation, self::AWAITING_URL)->assertOk();
        $this->assertNotNull($this->listedOrder($claimingList, $dineOrder));
        $this->assertNull($this->listedOrder($claimingList, $quickOrder));
        $this->assertSame([], $this->getAs($wrongBranch, self::AWAITING_URL)->json('data.orders'));

        $this->claim($wrongBranch, $dineOrder)
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'order_not_found');
        $this->claim($claimingStation, $dineOrder)
            ->assertOk()
            ->assertJsonPath('data.charge_amount_baisas', 4750);
        $this->assertNull($dineOrder->fresh()->charge_roundup_amount_baisas);

        $this->assertNull($this->listedOrder(
            $this->getAs($openingStation, self::AWAITING_URL)->assertOk(),
            $dineOrder,
        ));
        $this->assertNull($this->listedOrder(
            $this->getAs($claimingStation, self::AWAITING_URL)->assertOk(),
            $dineOrder,
        ));
        $this->claim($openingStation, $dineOrder)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_already_claimed');

        $this->postAs($claimingStation, self::RELEASE_URL, [
            'order_uuid' => $dineOrder->uuid,
            'outcome' => Order::CHARGE_OUTCOME_DECLINED,
        ])->assertOk();
        $this->assertNotNull($this->listedOrder(
            $this->getAs($openingStation, self::AWAITING_URL)->assertOk(),
            $dineOrder,
        ));
        $this->assertNotNull($this->listedOrder(
            $this->getAs($claimingStation, self::AWAITING_URL)->assertOk(),
            $dineOrder,
        ));

        $this->postAs($claimingStation, self::FALLBACK_URL, [
            'order_uuid' => $dineOrder->uuid,
        ])->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_HELD);
        $this->assertChargeFieldsNull($dineOrder->fresh());

        $this->claim($claimingStation, $quickOrder)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'order_not_bound_to_device_session');
        $this->assertNotNull($this->listedOrder(
            $this->getAs($openingStation, self::AWAITING_URL)->assertOk(),
            $quickOrder,
        ));
        $this->assertNull($this->listedOrder(
            $this->getAs($claimingStation, self::AWAITING_URL)->assertOk(),
            $quickOrder,
        ));
        $this->assertNoRoundupArtifacts();
    }

    public function test_reopen_matrix_clears_safe_provenance_and_preserves_every_refused_field(): void
    {
        $station = $this->device('payment_station');
        $till = $this->device('fixed_pos');

        $allowed = [
            'never_claimed' => [Order::STATUS_AWAITING_PAYMENT, []],
            'declined' => [
                Order::STATUS_AWAITING_PAYMENT,
                $this->chargeProvenance($station, Order::CHARGE_OUTCOME_DECLINED, true),
            ],
            'cancelled' => [
                Order::STATUS_AWAITING_PAYMENT,
                $this->chargeProvenance($station, Order::CHARGE_OUTCOME_CANCELLED, true),
            ],
            'held' => [Order::STATUS_HELD, []],
        ];

        foreach ($allowed as $name => [$status, $charge]) {
            $table = $this->table(10, 'ALLOW-'.$name);
            $session = $this->qrSession($station, $table, QrSession::STATUS_ORDERED);
            $order = $this->order($station, $table, $session, $status, $charge);

            $this->postAs($till, self::REOPEN_URL, ['order_uuid' => $order->uuid])
                ->assertOk()
                ->assertJsonPath('data.status', Order::STATUS_OPEN)
                ->assertJsonPath('data.session_status', QrSession::STATUS_ACTIVE);

            $this->assertChargeFieldsNull($order->fresh());
            $this->assertSame(QrSession::STATUS_ACTIVE, $session->fresh()->status);
        }

        $refused = [
            'live' => $this->chargeProvenance($station, null, true),
            'lapsed' => $this->chargeProvenance($station, Order::CHARGE_OUTCOME_LAPSED, false),
            'uncertain' => $this->chargeProvenance($station, Order::CHARGE_OUTCOME_UNCERTAIN, false),
            'approved' => $this->chargeProvenance($station, Order::CHARGE_OUTCOME_APPROVED, false),
            'expired_unresolved' => $this->chargeProvenance($station, null, false),
        ];

        foreach ($refused as $name => $charge) {
            $table = $this->table(10, 'REFUSE-'.$name);
            $session = $this->qrSession($station, $table, QrSession::STATUS_ORDERED);
            $order = $this->order(
                $station,
                $table,
                $session,
                Order::STATUS_AWAITING_PAYMENT,
                $charge,
            );
            $before = $this->chargeSnapshot($order);

            $this->postAs($till, self::REOPEN_URL, ['order_uuid' => $order->uuid])
                ->assertConflict()
                ->assertJsonPath('errors.0.code', 'qr_charge_recovery_required');

            $this->assertSame($before, $this->chargeSnapshot($order->fresh()), $name);
            $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
            $this->assertSame(QrSession::STATUS_ORDERED, $session->fresh()->status);
        }

        $this->assertNoRoundupArtifacts();
    }

    public function test_station_till_open_till_held_and_void_all_close_the_session(): void
    {
        $station = $this->device('payment_station');
        $till = $this->device('fixed_pos');

        $stationTable = $this->table(10, 'CLOSE-STATION');
        $stationSession = $this->qrSession($station, $stationTable, QrSession::STATUS_ORDERED);
        $stationOrder = $this->order(
            $station,
            $stationTable,
            $stationSession,
            Order::STATUS_AWAITING_PAYMENT,
        );
        $this->claim($station, $stationOrder)->assertOk();
        $this->assertSyncProcessed($this->push($station, [
            $this->payEvent($stationOrder, $this->cardTender()),
        ]));

        $openTable = $this->table(10, 'CLOSE-OPEN');
        $openSession = $this->qrSession($station, $openTable, QrSession::STATUS_ACTIVE);
        $openOrder = $this->order($station, $openTable, $openSession, Order::STATUS_OPEN);
        $this->assertSyncProcessed($this->push($till, [
            $this->payEvent($openOrder, $this->cashTender()),
        ]));

        $heldTable = $this->table(10, 'CLOSE-HELD');
        $heldSession = $this->qrSession($station, $heldTable, QrSession::STATUS_ORDERED);
        $heldOrder = $this->order($station, $heldTable, $heldSession, Order::STATUS_HELD);
        $this->assertSyncProcessed($this->push($till, [
            $this->payEvent($heldOrder, $this->cashTender()),
        ]));

        $voidTable = $this->table(10, 'CLOSE-VOID');
        $voidSession = $this->qrSession($station, $voidTable, QrSession::STATUS_ACTIVE);
        $voidOrder = $this->order($station, $voidTable, $voidSession, Order::STATUS_OPEN);
        $this->assertSyncProcessed($this->push($till, [$this->voidEvent($voidOrder)]));

        foreach ([$stationSession, $openSession, $heldSession, $voidSession] as $session) {
            $session->refresh();
            $this->assertSame(QrSession::STATUS_CLOSED, $session->status);
            $this->assertNotNull($session->closed_at);
            $this->postJson(self::TABLE_BIND_URL, [
                'table_token' => $session->table?->qr_token,
                'client_secret' => 'closed-session-rescan',
            ])->assertNotFound()
                ->assertJsonPath('errors.0.code', 'qr_bind_failed');
        }
        $this->assertSame(Order::STATUS_PAID, $stationOrder->fresh()->status);
        $this->assertSame(Order::STATUS_PAID, $openOrder->fresh()->status);
        $this->assertSame(Order::STATUS_PAID, $heldOrder->fresh()->status);
        $this->assertSame(Order::STATUS_VOID, $voidOrder->fresh()->status);
        $this->assertNoRoundupArtifacts();
    }

    public function test_expired_orphan_is_boarded_safely_settled_cleared_and_reopened(): void
    {
        $station = $this->device('payment_station');
        $till = $this->device('fixed_pos');
        $table = $this->table(10, 'ORPHAN');
        $secret = 'expired-orphan-secret';
        $session = $this->qrSession($station, $table, QrSession::STATUS_ORDERED, $secret);
        $order = $this->order(
            $station,
            $table,
            $session,
            Order::STATUS_AWAITING_PAYMENT,
        );
        $session->update(['expires_at' => now()->addMinute()]);

        $this->travel(61)->seconds();
        // No customer request performs the lazy flip: the staff board is the
        // recovery boundary for a browser that finished and disappeared.
        $this->assertSame(QrSession::STATUS_ORDERED, $session->fresh()->status);

        $board = $this->getAs($till, self::BOARD_URL)
            ->assertOk()
            ->assertJsonCount(1, 'data.tables')
            ->assertJsonPath('data.tables.0.table_id', $table->id)
            ->assertJsonPath('data.tables.0.session_status', QrSession::STATUS_EXPIRED)
            ->assertJsonPath('data.tables.0.orphaned', true)
            ->assertJsonPath('data.tables.0.order.uuid', $order->uuid);
        $this->assertSame(QrSession::STATUS_EXPIRED, $session->fresh()->status);
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $board->json('data.tables.0.order.status'));

        $this->claimSettlement($till, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_order_not_settleable');
        $this->postAs($till, self::FALLBACK_URL, ['order_uuid' => $order->uuid])
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_HELD);
        $this->assertChargeFieldsNull($order->fresh());
        $this->claimSettlement($station, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'device_not_attended');
        $this->claimSettlement($till, $order)
            ->assertOk()
            ->assertJsonPath('data.charge_amount_baisas', 4750)
            ->assertJsonPath('data.already_claimed_by_this_device', false);
        $this->claimSettlement($till, $order)
            ->assertOk()
            ->assertJsonPath('data.already_claimed_by_this_device', true);
        $this->assertSame(QrSession::STATUS_EXPIRED, $session->fresh()->status);

        $this->assertSyncProcessed($this->push($till, [
            $this->payEvent($order, $this->cashTender()),
        ]));
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(QrSession::STATUS_CLOSED, $session->fresh()->status);

        $this->postAs($till, self::CLEAR_URL, ['table_id' => $table->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'cleared');
        $this->getAs($till, self::BOARD_URL)
            ->assertOk()
            ->assertJsonCount(0, 'data.tables');
        $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $table->id])
            ->assertCreated()
            ->assertJsonPath('data.table_token', $table->qr_token);
        $this->assertSame(
            1,
            QrSession::query()
                ->where('table_id', $table->id)
                ->where('status', QrSession::STATUS_PENDING)
                ->count(),
        );
        $this->assertNoRoundupArtifacts();
    }

    public function test_fallback_and_refused_clear_are_independent_table_scoped_expiry_boundaries(): void
    {
        $station = $this->device('payment_station');
        $till = $this->device('fixed_pos');

        $fallbackTable = $this->table(10, 'DIRECT-FALLBACK');
        $fallbackSession = $this->qrSession(
            $station,
            $fallbackTable,
            QrSession::STATUS_ORDERED,
        );
        $fallbackOrder = $this->order(
            $station,
            $fallbackTable,
            $fallbackSession,
            Order::STATUS_AWAITING_PAYMENT,
        );

        $clearTable = $this->table(10, 'REFUSED-CLEAR');
        $clearSession = $this->qrSession($station, $clearTable, QrSession::STATUS_ORDERED);
        $this->order(
            $station,
            $clearTable,
            $clearSession,
            Order::STATUS_AWAITING_PAYMENT,
        );
        $fallbackSession->update(['expires_at' => now()->addMinute()]);
        $clearSession->update(['expires_at' => now()->addMinute()]);
        $this->travel(61)->seconds();

        $this->postAs($till, self::FALLBACK_URL, ['order_uuid' => $fallbackOrder->uuid])
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_HELD);
        $this->assertSame(QrSession::STATUS_EXPIRED, $fallbackSession->fresh()->status);
        $this->assertSame(QrSession::STATUS_ORDERED, $clearSession->fresh()->status);

        $this->postAs($till, self::CLEAR_URL, ['table_id' => $clearTable->id])
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_table_payment_pending');
        // The classified refusal must not roll the staff-triggered expiry back.
        $this->assertSame(QrSession::STATUS_EXPIRED, $clearSession->fresh()->status);
    }

    public function test_hard_deleted_opening_station_leaves_a_fail_closed_table_scoped_recovery_path(): void
    {
        $productId = $this->seedStationDeletionRoundPrerequisites();
        $openingStation = $this->device('payment_station');
        $unattendedStation = $this->device('payment_station');
        $replacementStation = $this->device('payment_station');
        $till = $this->device('fixed_pos');
        $wrongBranchTill = $this->device('fixed_pos', 20);
        $table = $this->table(10, 'DELETED-STATION');
        $secret = 'deleted-station-browser-secret';

        $opened = $this->postAs($openingStation, self::OPEN_TABLE_URL, [
            'table_id' => $table->id,
        ])->assertCreated();
        $session = QrSession::query()
            ->where('uuid', $opened->json('data.session_uuid'))
            ->sole();
        $this->postJson(self::TABLE_BIND_URL, [
            'table_token' => $table->qr_token,
            'client_secret' => $secret,
        ])->assertOk()
            ->assertJsonPath('data.status', QrSession::STATUS_ACTIVE);

        $created = $this->withHeaders([
            'X-QR-Session' => $session->uuid,
            'X-QR-Client-Secret' => $secret,
        ])->postJson(self::TABLE_ROUND_URL, [
            'client_request_id' => 'deleted-station-round-1',
            'phone' => '92000099',
            'lines' => [[
                'product_id' => $productId,
                'qty' => 1,
                'addon_ids' => [],
                'notes' => null,
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.round.status', QrOrderRound::STATUS_ACCEPTED)
            ->assertJsonPath('data.order.grand_total_baisas', 4750);
        $order = Order::query()->where('uuid', $created->json('data.order.uuid'))->sole();
        $roundId = (int) $created->json('data.round.id');

        $this->finish($session, $secret, 'station')
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_AWAITING_PAYMENT);
        $openingStation->forceDelete();

        $this->assertDatabaseMissing('pos_qr_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('pos_qr_order_rounds', ['id' => $roundId]);
        $this->assertNull($order->fresh()->qr_session_id);
        $this->getAs($till, self::BOARD_URL)
            ->assertOk()
            ->assertJsonCount(1, 'data.tables')
            ->assertJsonPath('data.tables.0.table_id', $table->id)
            ->assertJsonPath('data.tables.0.session_uuid', null)
            ->assertJsonPath('data.tables.0.orphaned', true)
            ->assertJsonPath('data.tables.0.order.uuid', $order->uuid)
            ->assertJsonPath('data.tables.0.order.status', Order::STATUS_AWAITING_PAYMENT);

        $this->claimSettlement($till, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'order_not_bound_to_device_session');

        $safeBefore = $order->fresh()->getRawOriginal();
        $this->postAs($wrongBranchTill, self::FALLBACK_URL, ['order_uuid' => $order->uuid])
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'order_not_found');
        $this->assertSame($safeBefore, $order->fresh()->getRawOriginal());
        $this->postAs($unattendedStation, self::FALLBACK_URL, ['order_uuid' => $order->uuid])
            ->assertConflict();
        $this->assertSame($safeBefore, $order->fresh()->getRawOriginal());

        $order->update($this->chargeProvenance($unattendedStation, null, true));
        $liveBefore = $this->chargeSnapshot($order->fresh());
        $this->postAs($till, self::FALLBACK_URL, ['order_uuid' => $order->uuid])
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_already_claimed');
        $this->assertSame($liveBefore, $this->chargeSnapshot($order->fresh()));

        $order->update($this->chargeProvenance(
            $unattendedStation,
            Order::CHARGE_OUTCOME_UNCERTAIN,
            false,
        ));
        $ambiguousBefore = $this->chargeSnapshot($order->fresh());
        $this->postAs($till, self::FALLBACK_URL, ['order_uuid' => $order->uuid])
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_HELD)
            ->assertJsonPath('data.receipt_number', $order->receipt_number);
        $this->assertSame($ambiguousBefore, $this->chargeSnapshot($order->fresh()));
        $this->claimSettlement($till, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_charge_recovery_required');

        $order->update(array_fill_keys(self::CHARGE_FIELDS, null));
        $this->assertChargeFieldsNull($order->fresh());

        $this->claimSettlement($wrongBranchTill, $order)
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'order_not_found');
        $this->claimSettlement($unattendedStation, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'device_not_attended');
        $this->claimSettlement($till, $order)
            ->assertOk()
            ->assertJsonPath('data.charge_amount_baisas', 4750)
            ->assertJsonPath('data.already_claimed_by_this_device', false);
        $this->claimSettlement($till, $order)
            ->assertOk()
            ->assertJsonPath('data.already_claimed_by_this_device', true);
        $this->assertNull($order->fresh()->qr_session_id);

        $this->assertSyncProcessed($this->push($till, [
            $this->payEvent($order, $this->cashTender()),
        ]));
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->postAs($till, self::CLEAR_URL, ['table_id' => $table->id])
            ->assertOk()
            ->assertJsonPath('data.status', 'cleared');
        $this->postAs($replacementStation, self::OPEN_TABLE_URL, ['table_id' => $table->id])
            ->assertCreated()
            ->assertJsonPath('data.table_token', $table->qr_token);
        $this->assertSame(
            1,
            QrSession::query()
                ->where('table_id', $table->id)
                ->where('status', QrSession::STATUS_PENDING)
                ->count(),
        );
        $this->assertNoRoundupArtifacts();
    }

    private function device(string $type, int $branchId = 10): Device
    {
        $this->deviceSequence++;

        return Device::factory()->paired('mdev_qr2_money_'.$this->deviceSequence)->create([
            'company_id' => 100,
            'branch_id' => $branchId,
            'device_type' => $type,
            'terminal_id' => $type === 'payment_station' ? 'TERM-'.$this->deviceSequence : null,
        ]);
    }

    private function seedStationDeletionRoundPrerequisites(): int
    {
        $productId = 88001;
        $timestamps = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_products')->insert([
            'id' => $productId,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'category_id' => null,
            'name' => 'Station deletion coffee',
            'base_price' => '4.750',
            'stock_mode' => 'untracked',
            'display_order' => 1,
            'status' => 'active',
            'show_on_customer_tablet' => true,
            'is_internal' => false,
            'available_from' => null,
            'available_until' => null,
            'deleted_at' => null,
        ] + $timestamps);
        DB::table('pos_branch_product')->insert([
            'branch_id' => 10,
            'product_id' => $productId,
            'is_available' => true,
            'stock_qty' => null,
        ] + $timestamps);
        DB::table('pos_company_settings')->insert([
            'company_id' => 100,
            'key' => 'order_numbering',
            'value' => json_encode([
                'enabled' => true,
                'prefix' => 'QR2-',
                'pad' => 5,
                'scope' => 'branch',
                'daily_reset' => false,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $productId;
    }

    private function table(int $branchId, string $label): PosTable
    {
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
            'qr_token' => hash('sha256', 'table-'.$label),
            'status' => 'active',
            'display_order' => 1,
        ]);
    }

    private function qrSession(
        Device $station,
        ?PosTable $table,
        string $status,
        string $secret = 'qr2-money-secret',
    ): QrSession {
        return QrSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $station->company_id,
            'branch_id' => $station->branch_id,
            'device_id' => $station->id,
            'table_id' => $table?->id,
            'token' => hash('sha256', (string) Str::uuid()),
            'token_expires_at' => now()->addHours(6),
            'status' => $status,
            'client_secret_hash' => QrSession::hashClientSecret($secret),
            'bound_at' => now(),
            'last_seen_at' => now(),
            'expires_at' => now()->addHours(6),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function order(
        Device $openingDevice,
        ?PosTable $table,
        QrSession $session,
        string $status,
        array $attributes = [],
    ): Order {
        $this->orderSequence++;

        return Order::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $openingDevice->company_id,
            'branch_id' => $openingDevice->branch_id,
            'device_id' => $openingDevice->id,
            'qr_session_id' => $session->id,
            'client_request_id' => (string) Str::uuid(),
            'table_id' => $table?->id,
            'order_type' => $table === null ? 'quick' : 'dine_in',
            'status' => $status,
            'source' => Order::SOURCE_QR_WEB,
            'subtotal' => '4.750',
            'discount_total' => '0.000',
            'comp_total' => '0.000',
            'tax_total' => '0.000',
            'grand_total' => '4.750',
            'opened_at' => now(),
            'receipt_number' => 'QR-'.str_pad((string) $this->orderSequence, 4, '0', STR_PAD_LEFT),
        ], $attributes));
    }

    private function round(QrSession $session, ?Order $order, string $status): QrOrderRound
    {
        $roundNo = QrOrderRound::query()->where('qr_session_id', $session->id)->count() + 1;

        return QrOrderRound::query()->create([
            'qr_session_id' => $session->id,
            'order_id' => $order?->id,
            'round_no' => $roundNo,
            'status' => $status,
            'client_request_id' => (string) Str::uuid(),
            'priced_lines' => [['line_total_baisas' => 4750]],
            'subtotal_baisas' => 4750,
            'tax_baisas' => 0,
            'total_baisas' => 4750,
            'submitted_at' => now(),
            'resolved_at' => $status === QrOrderRound::STATUS_PENDING_CONFIRMATION ? null : now(),
        ]);
    }

    /** @return array<string, mixed> */
    private function chargeProvenance(Device $station, ?string $outcome, bool $future): array
    {
        return [
            'charge_device_id' => $station->id,
            'charge_amount_baisas' => 4750,
            'charge_roundup_amount_baisas' => null,
            'charge_claimed_at' => now()->subMinute(),
            'charge_deadline_at' => $future ? now()->addMinute() : now()->subSecond(),
            'charge_outcome' => $outcome,
        ];
    }

    /** @return array<string, mixed> */
    private function chargeSnapshot(Order $order): array
    {
        return array_intersect_key($order->getRawOriginal(), array_flip(self::CHARGE_FIELDS));
    }

    private function assertChargeFieldsNull(Order $order): void
    {
        foreach (self::CHARGE_FIELDS as $field) {
            $this->assertNull($order->getRawOriginal($field), $field);
        }
    }

    private function assertNoRoundupArtifacts(): void
    {
        $this->assertSame(0, DB::table('pos_roundup_donations')->count());
        $this->assertSame(
            0,
            Order::query()
                ->where('source', Order::SOURCE_QR_WEB)
                ->where('order_type', 'dine_in')
                ->whereNotNull('charge_roundup_amount_baisas')
                ->count(),
        );
        $this->assertSame(0, DB::table('pos_payments')->whereNotNull('roundup_amount')->count());
    }

    /** @return array<string, mixed>|null */
    private function listedOrder(TestResponse $response, Order $order): ?array
    {
        foreach ((array) $response->json('data.orders') as $row) {
            if (is_array($row) && ($row['order_uuid'] ?? null) === $order->uuid) {
                return $row;
            }
        }

        return null;
    }

    private function finish(
        QrSession $session,
        string $secret,
        string $paymentChoice,
    ): TestResponse {
        return $this->withHeaders([
            'X-QR-Session' => $session->uuid,
            'X-QR-Client-Secret' => $secret,
        ])->postJson(self::FINISH_URL, ['payment_choice' => $paymentChoice]);
    }

    private function qrStatus(QrSession $session, string $secret): TestResponse
    {
        return $this->withHeaders([
            'X-QR-Session' => $session->uuid,
            'X-QR-Client-Secret' => $secret,
        ])->getJson(self::STATUS_URL);
    }

    private function claim(Device $device, Order $order): TestResponse
    {
        return $this->postAs($device, self::CLAIM_URL, ['order_uuid' => $order->uuid]);
    }

    /** @param array{lat: float, lng: float}|null $gps */
    private function claimSettlement(
        Device $device,
        Order $order,
        ?array $gps = null,
    ): TestResponse {
        $payload = ['order_uuid' => $order->uuid];
        if ($gps !== null) {
            $payload['gps'] = $gps;
        }

        return $this->postAs($device, self::CLAIM_SETTLEMENT_URL, $payload);
    }

    /** @param list<array<string, mixed>> $events */
    private function push(Device $device, array $events): TestResponse
    {
        return $this->postAs($device, self::SYNC_URL, ['events' => $events]);
    }

    private function assertSyncProcessed(TestResponse $response): void
    {
        $response->assertOk()->assertJsonPath('data.results.0.status', 'processed');
    }

    /** @return array<string, mixed> */
    private function payEvent(Order $order, array $tender): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => $order->uuid,
                'paid_at' => now()->toIso8601String(),
                'payments' => [$tender],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function voidEvent(Order $order): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => $order->uuid,
                'voided_at' => now()->toIso8601String(),
                'reason' => 'QR2 deterministic closure test',
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function cashTender(): array
    {
        return [
            'method' => Payment::METHOD_CASH,
            'amount_baisas' => 4750,
            'status' => Payment::STATUS_SUCCESS,
        ];
    }

    /** @return array<string, mixed> */
    private function cardTender(): array
    {
        return [
            'method' => Payment::METHOD_CARD,
            'amount_baisas' => 4750,
            'status' => Payment::STATUS_SUCCESS,
            'softpos_reference' => 'QR2-'.Str::random(12),
            'softpos_auth_code' => 'AUTH'.Str::random(6),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function postAs(Device $device, string $url, array $payload = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken((string) $device->device_token)->postJson($url, $payload);
    }

    private function getAs(Device $device, string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken((string) $device->device_token)->getJson($url);
    }
}
