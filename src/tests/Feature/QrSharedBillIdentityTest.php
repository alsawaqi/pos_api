<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\ClaimQrChargeAction;
use App\Actions\Qr\ClaimQrSettlementAction;
use App\Actions\Qr\ClearDineInQrTableAction;
use App\Actions\Qr\ConfirmDineInQrRoundAction;
use App\Actions\Qr\FinishDineInQrOrderAction;
use App\Actions\Qr\ListDineInQrTableBoardAction;
use App\Actions\Qr\OpenDineInTableAction;
use App\Actions\Qr\QrChargeException;
use App\Actions\Qr\QrDineInException;
use App\Actions\Qr\ReopenDineInQrPaymentAction;
use App\Actions\Qr\SubmitDineInQrRoundAction;
use App\Actions\Tables\AppendStaffRoundAction;
use App\Actions\Tables\OpenStaffTableSessionAction;
use App\Http\Controllers\Api\V1\PublicQr\QrStatusController;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\TableSession;
use App\Models\TableSessionEvent;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/** Revision 3: credential identity arrives with the customer's first round. */
final class QrSharedBillIdentityTest extends TestCase
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

    /** @return array<string, array{bool}> */
    public static function sharedRoundOrigins(): array
    {
        return ['staff first' => [true], 'customer first' => [false]];
    }

    #[DataProvider('sharedRoundOrigins')]
    public function test_shared_bill_round_numbers_follow_bill_order_and_public_status(bool $staffFirst): void
    {
        $flow = $this->flow();
        if ($staffFirst) {
            $staff = $this->staffRound($flow);
            $customer = $this->customerRound($flow);
        } else {
            $customer = $this->customerRound($flow);
            $staff = $this->staffRound($flow);
        }
        $ids = $staffFirst
            ? [$staff['round_id'], (int) $customer['round']->id]
            : [(int) $customer['round']->id, $staff['round_id']];
        $origins = $staffFirst ? ['staff', 'customer'] : ['customer', 'staff'];
        $this->assertSame(1, Order::query()->count());
        $this->assertSame([1, 2], QrOrderRound::query()
            ->where('order_id', $customer['order']->id)->orderBy('id')->pluck('round_no')->all());
        $publicRounds = $this->qrStatus($flow['session'])['data']['dine_in']['rounds'];
        $this->assertSame($ids, array_column($publicRounds, 'id'));
        $this->assertSame([1, 2], array_column($publicRounds, 'round_no'));
        $this->assertSame($origins, array_column($publicRounds, 'entered_by'));
        $replay = $this->customerRound($flow);
        $this->assertTrue($replay['replayed']);
        $this->assertSame((int) $customer['round']->id, (int) $replay['round']->id);
        $this->assertSame(2, QrOrderRound::query()->count());
        fwrite(STDOUT, "\nT6_R2_ROUND_ORDER=".json_encode([
            'staff_first' => $staffFirst, 'round_no' => array_column($publicRounds, 'round_no'),
            'entered_by' => $origins, 'replayed' => $replay['replayed'],
        ], JSON_THROW_ON_ERROR)."\n");
    }

    /** @return array<string, array{string}> */
    public static function duplicateRoundStatuses(): array
    {
        return [
            'accepted tie' => [QrOrderRound::STATUS_ACCEPTED],
            'pending and accepted tie' => [QrOrderRound::STATUS_PENDING_CONFIRMATION],
        ];
    }

    #[DataProvider('duplicateRoundStatuses')]
    public function test_legacy_round_number_ties_are_ordered_by_id_in_status_and_board(string $status): void
    {
        $flow = $this->flow();
        $customer = $this->customerRound($flow);
        $later = $this->seatingRound($flow['seating'], $customer['order'], [
            'qr_session_id' => $flow['session']->id, 'round_no' => 1,
            'status' => $status, 'resolved_at' => now()->addSecond(),
        ]);
        $ids = [(int) $customer['round']->id, (int) $later->id];
        $queries = [];
        DB::listen(function (QueryExecuted $query) use (&$queries): void {
            if (str_contains($query->sql, 'from "pos_qr_order_rounds"')) {
                $queries[] = $query->sql;
            }
        });
        $publicRounds = $this->qrStatus($flow['session'])['data']['dine_in']['rounds'];
        $this->assertSame($ids, array_column($publicRounds, 'id'));
        $this->assertSame([1, 1], array_column($publicRounds, 'round_no'));
        $this->assertTrue(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'order by "round_no" asc, "id" asc'),
        ), 'Public status must explicitly break legacy round-number ties by id.');
        $queries = [];
        $board = app(ListDineInQrTableBoardAction::class)->handle($flow['till']);
        $this->assertCount(1, $board);
        $this->assertSame($ids, array_column($board[0]['rounds'], 'id'));
        $this->assertTrue(collect($queries)->contains(
            static fn (string $sql): bool => str_contains($sql, 'order by "round_no" asc, "id" asc'),
        ), 'The pending-round query must explicitly break legacy ties by id.');
        fwrite(STDOUT, "\nT6_R2_LEGACY_ROUND_ORDER=".json_encode([
            'status' => $status, 'round_no' => array_column($publicRounds, 'round_no'),
            'public_ids' => array_column($publicRounds, 'id'),
            'board_ids' => array_column($board[0]['rounds'], 'id'),
        ], JSON_THROW_ON_ERROR)."\n");
    }

    public function test_case_12_staff_first_bill_is_adopted_with_exact_identity_without_replacing_children(): void
    {
        $flow = $this->flow();
        $staff = $this->staffRound($flow);
        $bill = Order::query()->sole();
        $item = OrderItem::query()->sole()->getRawOriginal();
        $this->assertSame('main_pos', $bill->source);
        $this->assertNull($bill->qr_session_id);
        $this->assertNull($bill->customer_id);
        $this->assertNull($bill->client_request_id);

        $result = $this->customerRound($flow);
        $adopted = $result['order'];
        $this->assertSame($bill->uuid, $adopted->uuid);
        $this->assertSame($staff['order_uuid'], $adopted->uuid);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame((int) $flow['session']->id, (int) $adopted->qr_session_id);
        $this->assertSame('qr_web', $adopted->source);
        $this->assertSame((int) $flow['station']->id, (int) $adopted->device_id);
        $this->assertSame('92001234', Customer::findOrFail($adopted->customer_id)->phone);
        $this->assertSame('OM 77', $adopted->plate_number);
        $this->assertNull($adopted->client_request_id);
        $this->assertNull($adopted->client_event_id);
        $this->assertNull($adopted->receipt_number);
        $this->assertSame($bill->temp_reference, $adopted->temp_reference);
        $this->assertSame('2.000', $adopted->grand_total);
        $this->assertSame((int) $adopted->id, (int) $flow['seating']->fresh()->order_id);
        $this->assertSame($item, OrderItem::findOrFail($item['id'])->getRawOriginal());
        $rounds = QrOrderRound::query()->orderBy('id')->get();
        $this->assertCount(2, $rounds);
        $this->assertNull($rounds[0]->qr_session_id);
        $this->assertSame((int) $flow['session']->id, (int) $rounds[1]->qr_session_id);
        $this->assertSame([(int) $adopted->id], $rounds->pluck('order_id')->unique()->all());
        $events = TableSessionEvent::query()->where('event_type', 'attached')->get()
            ->filter(static fn (TableSessionEvent $event): bool => isset($event->payload['adopted_order_uuid']));
        $this->assertCount(1, $events);
        $this->assertSame($adopted->uuid, $events->first()->payload['adopted_order_uuid']);
        $this->assertSame($flow['session']->uuid, $events->first()->payload['session_uuid']);
        fwrite(STDOUT, "\nT4_SHARED_BILL_CASE_12=".json_encode([
            'bills' => Order::query()->count(), 'order_uuid' => $adopted->uuid,
            'source' => $adopted->source, 'qr_session_id' => $adopted->qr_session_id,
            'device_id' => $adopted->device_id, 'customer_id' => $adopted->customer_id,
            'client_request_id' => $adopted->client_request_id, 'grand_total' => $adopted->grand_total,
        ], JSON_THROW_ON_ERROR)."\n");
    }

    public function test_case_13_pending_customer_first_round_adopts_and_existing_qr_confirmation_stays_authorized(): void
    {
        $flow = $this->flow();
        $staff = $this->staffRound($flow);
        $this->staffConfirmMode();
        $result = $this->customerRound($flow);
        $this->assertSame(QrOrderRound::STATUS_PENDING_CONFIRMATION, $result['round']->status);
        $this->assertSame('qr_web', $result['order']->source);
        $this->assertSame('1.000', $result['order']->grand_total);
        $confirmed = app(ConfirmDineInQrRoundAction::class)->handle($flow['till'], (int) $result['round']->id);
        $this->assertSame(QrOrderRound::STATUS_ACCEPTED, $result['round']->fresh()->status);
        $this->assertSame('2.000', $result['order']->fresh()->grand_total);
        $this->assertSame(1, Order::query()->count());
        fwrite(STDOUT, "\nT4_SHARED_BILL_CASE_13=".json_encode($confirmed, JSON_THROW_ON_ERROR)."\n");

        try {
            app(ConfirmDineInQrRoundAction::class)->handle($flow['till'], $staff['round_id']);
            $this->fail('The protected QR confirmation path must refuse credential-free staff rounds.');
        } catch (QrDineInException $exception) {
            $this->assertSame('qr_round_not_found', $exception->codeName);
        }
    }

    public function test_case_14_customer_first_then_staff_keeps_every_bill_identity_field(): void
    {
        $flow = $this->flow();
        $result = $this->customerRound($flow);
        $before = $result['order']->only([
            'uuid', 'qr_session_id', 'source', 'device_id', 'customer_id',
            'plate_number', 'client_request_id', 'client_event_id', 'temp_reference',
        ]);
        $staff = $this->staffRound($flow);
        $this->assertSame('appended', $staff['outcome']);
        $this->assertSame($before, $result['order']->fresh()->only(array_keys($before)));
        $this->assertSame('2.000', $result['order']->fresh()->grand_total);
        $this->assertSame(1, Order::query()->count());
        fwrite(STDOUT, "\nT4_SHARED_BILL_CASE_14=".json_encode($staff, JSON_THROW_ON_ERROR)."\n");
    }

    public function test_snapshot_restart_adopts_a_staff_bill_created_between_pre_read_and_locks(): void
    {
        $flow = $this->flow();
        $inserted = false;
        $seatingReads = 0;
        $rollbacks = 0;
        $staff = null;
        Event::listen(TransactionRolledBack::class, function () use (&$rollbacks): void {
            $rollbacks++;
        });
        DB::listen(function (QueryExecuted $query) use ($flow, &$inserted, &$seatingReads, &$staff): void {
            if (! str_starts_with($query->sql, 'select * from "pos_table_sessions" where')) {
                return;
            }
            $seatingReads++;
            if ($inserted) {
                return;
            }
            // QueryExecuted fires after SQLite returned its old snapshot but
            // before Submit starts the transaction. The staff commit survives
            // Submit's first-attempt rollback, like an interleaving writer.
            $inserted = true;
            $staff = $this->staffRound($flow, 'staff-during-snapshot');
        });
        $result = $this->customerRound($flow);
        $this->assertTrue($inserted);
        $this->assertSame(1, $rollbacks, 'Submit must roll back its stale snapshot and restart order-first.');
        $this->assertGreaterThanOrEqual(2, $seatingReads);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame($staff['order_uuid'], $result['order']->uuid);
        $this->assertSame('2.000', $result['order']->grand_total);
        $this->assertSame(2, QrOrderRound::query()->count());
        $this->assertSame(1, TableSessionEvent::query()->where('event_type', 'attached')->get()
            ->filter(static fn (TableSessionEvent $event): bool => isset($event->payload['adopted_order_uuid']))->count());
    }

    public function test_other_credential_is_refused_without_repointing_or_changing_money(): void
    {
        $flow = $this->flow();
        $this->staffRound($flow);
        $bill = Order::query()->sole();
        $otherTable = $this->seatingTable('Other party');
        $otherOpen = app(OpenDineInTableAction::class)->handle($flow['station'], (int) $otherTable->id);
        $other = app(BindQrTableSessionAction::class)->handle($otherOpen['table_token'], 'other-secret');
        $bill->update(['qr_session_id' => $other->id]);
        $before = $bill->getRawOriginal();
        try {
            $this->customerRound($flow);
            $this->fail('A different credential must never be adopted.');
        } catch (QrDineInException $exception) {
            $this->assertSame('qr_round_order_not_open', $exception->codeName);
        }
        $this->assertSame($before, $bill->fresh()->getRawOriginal());
        $this->assertSame(1, QrOrderRound::query()->count());
        $this->assertSame(0, Customer::query()->count());
    }

    public function test_staff_bill_is_not_adopted_after_freezing(): void
    {
        $flow = $this->flow();
        $this->staffRound($flow);
        $bill = Order::query()->sole();
        foreach ([Order::STATUS_HELD, Order::STATUS_AWAITING_PAYMENT] as $status) {
            $bill->update(['status' => $status]);
            $before = $bill->getRawOriginal();
            try {
                $this->customerRound($flow);
                $this->fail('A frozen staff bill must not be adopted.');
            } catch (QrDineInException $exception) {
                $this->assertSame('qr_round_order_not_open', $exception->codeName);
            }
            $this->assertSame($before, $bill->fresh()->getRawOriginal());
        }
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(1, QrOrderRound::query()->count());
    }

    public function test_finish_accepts_staff_only_accepted_bill_and_rejects_pending_bill_and_credential_rows(): void
    {
        $flow = $this->flow();
        $this->staffRound($flow);
        $this->staffConfirmMode();
        $result = $this->customerRound($flow);
        $staffPending = $this->seatingRound($flow['seating'], $result['order'], [
            'round_no' => 3,
            'status' => QrOrderRound::STATUS_PENDING_CONFIRMATION, 'needs_review' => true,
            'confirm_payload' => ['private' => 'staff'], 'resolved_at' => null,
        ]);
        $credentialPending = $this->seatingRound($flow['seating'], null, [
            'qr_session_id' => $flow['session']->id, 'round_no' => 2,
            'status' => QrOrderRound::STATUS_PENDING_CONFIRMATION,
            'confirm_payload' => ['private' => 'credential'], 'resolved_at' => null,
        ]);
        $status = $this->qrStatus($flow['session']);
        $this->assertTrue($status['data']['dine_in']['finish_and_pay']['allowed']);
        $this->assertCount(3, $status['data']['dine_in']['rounds']);
        $this->assertSame(['staff', 'customer', 'staff'], array_column($status['data']['dine_in']['rounds'], 'entered_by'));
        $this->assertStringNotContainsString('confirm_payload', json_encode($status, JSON_THROW_ON_ERROR));
        fwrite(STDOUT, "\nT4_SHARED_STATUS_JSON=".json_encode($status, JSON_THROW_ON_ERROR)."\n");
        $finished = app(FinishDineInQrOrderAction::class)->handle((int) $flow['session']->id, 'counter');
        $this->assertSame(Order::STATUS_HELD, $finished['status']);
        $this->assertSame(1000, $finished['grand_total_baisas']);
        foreach ([$result['round'], $staffPending, $credentialPending] as $pending) {
            $this->assertSame(QrOrderRound::STATUS_REJECTED, $pending->fresh()->status);
            $this->assertNull($pending->fresh()->confirm_payload);
            $this->assertNotNull($pending->fresh()->resolved_at);
        }
        $this->assertSame(1, QrOrderRound::query()->where('status', QrOrderRound::STATUS_ACCEPTED)->count());
    }

    public function test_attended_settlement_rejects_pending_staff_and_credential_rows_on_adopted_bill(): void
    {
        $flow = $this->flow();
        $this->staffRound($flow);
        $this->staffConfirmMode();
        $result = $this->customerRound($flow);
        $staffPending = $this->seatingRound($flow['seating'], $result['order'], [
            'status' => QrOrderRound::STATUS_PENDING_CONFIRMATION,
            'confirm_payload' => ['private' => 'staff'], 'resolved_at' => null,
        ]);
        $credentialPending = $this->seatingRound($flow['seating'], null, [
            'qr_session_id' => $flow['session']->id, 'round_no' => 2,
            'status' => QrOrderRound::STATUS_PENDING_CONFIRMATION,
            'confirm_payload' => ['private' => 'credential'], 'resolved_at' => null,
        ]);
        $claim = app(ClaimQrSettlementAction::class)->handle($flow['till'], ['order_uuid' => $result['order']->uuid]);
        $this->assertSame(1000, $claim['charge_amount_baisas']);
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $claim['status']);
        foreach ([$result['round'], $staffPending, $credentialPending] as $pending) {
            $this->assertSame(QrOrderRound::STATUS_REJECTED, $pending->fresh()->status);
            $this->assertNull($pending->fresh()->confirm_payload);
            $this->assertSame((int) $flow['till']->id, (int) $pending->fresh()->resolved_by_device_id);
        }
    }

    public function test_adopted_bill_reopens_and_station_claims_without_any_authorization_widening(): void
    {
        $flow = $this->flow();
        $this->staffRound($flow);
        $result = $this->customerRound($flow);
        app(FinishDineInQrOrderAction::class)->handle((int) $flow['session']->id, 'counter');
        $reopened = app(ReopenDineInQrPaymentAction::class)->handle($flow['till'], $result['order']->uuid);
        $this->assertSame(Order::STATUS_OPEN, $reopened['status']);
        app(FinishDineInQrOrderAction::class)->handle((int) $flow['session']->id, 'station');
        $claim = app(ClaimQrChargeAction::class)->handle($flow['station'], ['order_uuid' => $result['order']->uuid]);
        $this->assertSame(2000, $claim['charge_amount_baisas']);
        $this->assertSame((int) $flow['station']->id, (int) $result['order']->fresh()->charge_device_id);
        $this->assertSame(1, Order::query()->count());
    }

    public function test_case_15_expired_credential_leaves_staff_identity_and_station_cannot_claim_it(): void
    {
        $this->enableReceiptNumbering();
        $flow = $this->flow();
        $flow['session']->update(['status' => QrSession::STATUS_EXPIRED, 'expires_at' => now()->subMinute()]);
        $this->staffRound($flow);
        $bill = Order::query()->sole();
        $this->assertSame('main_pos', $bill->source);
        $this->assertNull($bill->qr_session_id);
        $this->assertNull($bill->customer_id);
        try {
            app(ReopenDineInQrPaymentAction::class)->handle($flow['till'], $bill->uuid);
            $this->fail('Unadopted staff bills must not enter QR reopen.');
        } catch (QrDineInException $exception) {
            $this->assertSame('order_not_found', $exception->codeName);
        }
        $bill->update(['status' => Order::STATUS_AWAITING_PAYMENT]);
        try {
            app(ClaimQrChargeAction::class)->handle($flow['station'], ['order_uuid' => $bill->uuid]);
            $this->fail('An unadopted bill has no station credential.');
        } catch (QrChargeException $exception) {
            $this->assertSame('order_not_bound_to_device_session', $exception->codeName);
            fwrite(STDOUT, "\nT4_SHARED_BILL_CASE_15=".json_encode([
                'source' => $bill->fresh()->source, 'qr_session_id' => $bill->fresh()->qr_session_id,
                'station_refusal' => $exception->codeName,
            ], JSON_THROW_ON_ERROR)."\n");
        }
        // Awaiting-payment above is only the station-admission probe. The
        // staff bill's real state is open; do not bypass Pay's live-claim
        // requirement for an awaiting-payment bill.
        $bill->update(['status' => Order::STATUS_OPEN]);
        $paid = $this->payByUuid($flow['till'], $bill, (string) Str::uuid())
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.receipt_number', 'T4-00001');
        $this->assertSame(Order::STATUS_PAID, $bill->fresh()->status);
        $this->assertSame('main_pos', $bill->fresh()->source);
        $this->assertNull($bill->fresh()->qr_session_id);
        $this->assertNull($bill->fresh()->customer_id);
        $this->assertSame(QrSession::STATUS_EXPIRED, $flow['session']->fresh()->status);
        fwrite(STDOUT, "\nT4_SHARED_BILL_CASE_15_PAID_JSON=".$paid->getContent()."\n");
    }

    public function test_qr_only_status_preserves_every_existing_round_value_with_one_additive_key(): void
    {
        $flow = $this->flow();
        $result = $this->customerRound($flow);
        $round = $result['round'];
        $status = $this->qrStatus($flow['session']);
        $actual = $status['data']['dine_in']['rounds'][0];
        $this->assertSame('customer', $actual['entered_by']);
        unset($actual['entered_by']);
        // LAUNCH review add-on — a second additive key: the accepted round's
        // "ready in" (no line here has a cooking time).
        $this->assertArrayHasKey('ready_in_minutes', $actual);
        $this->assertNull($actual['ready_in_minutes']);
        unset($actual['ready_in_minutes']);
        $expectedPublicLines = $round->priced_lines;
        foreach ($expectedPublicLines as &$expectedLine) {
            unset($expectedLine['order_item_id']);
        }
        unset($expectedLine);
        $this->assertSame([
            'id' => (int) $round->id, 'round_no' => (int) $round->round_no,
            'status' => $round->status, 'priced_lines' => $expectedPublicLines,
            'subtotal_baisas' => (int) $round->subtotal_baisas,
            'tax_baisas' => (int) $round->tax_baisas, 'total_baisas' => (int) $round->total_baisas,
            'submitted_at' => $round->submitted_at->toIso8601String(),
            'resolved_at' => $round->resolved_at->toIso8601String(),
        ], $actual);
        $this->assertSame(['allowed' => true, 'refusal_code' => null], $status['data']['dine_in']['finish_and_pay']);
        $this->assertSame(1000, $status['data']['dine_in']['running_total_baisas']);
        $this->assertSame(1000, $status['data']['dine_in']['bill_totals']['grand_total_baisas']);
        $this->assertSame(0, $status['data']['dine_in']['bill_totals']['manual_discount_baisas']);
        $this->assertNull($status['data']['dine_in']['payment_state']);
    }

    public function test_paying_adopted_bill_with_active_earn_rule_attributes_loyalty_to_the_customer(): void
    {
        $flow = $this->flow();
        $this->staffRound($flow);
        $result = $this->customerRound($flow);
        DB::table('pos_loyalty_rules')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'Shared bill earn', 'type' => 'visit_based', 'status' => 'active',
            'config_json' => json_encode(['min_order_value' => '0.000', 'stamps_required' => 5]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        app(FinishDineInQrOrderAction::class)->handle((int) $flow['session']->id, 'counter');
        $response = $this->withToken($flow['till']->plainTextToken)->postJson('/api/v1/device/sync/push', [
            'events' => [[
                'client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay',
                'client_timestamp' => now()->toIso8601String(),
                'payload' => [
                    'order_uuid' => $result['order']->uuid, 'paid_at' => now()->toIso8601String(),
                    'payments' => [['method' => 'cash', 'amount_baisas' => 2000]],
                ],
            ]],
        ])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame(Order::STATUS_PAID, $result['order']->fresh()->status);
        $this->assertDatabaseHas('pos_loyalty_accounts', ['customer_id' => $result['order']->customer_id, 'stamp_count' => 1]);
        $this->assertSame(1, Order::query()->count());
        fwrite(STDOUT, "\nT4_SHARED_BILL_LOYALTY_PAY_JSON=".$response->getContent()."\n");
    }

    public function test_clear_adopted_terminal_bill_rejects_staff_and_credential_pending_without_touching_other_bills(): void
    {
        $flow = $this->flow();
        $this->staffRound($flow);
        $this->staffConfirmMode();
        $result = $this->customerRound($flow);
        $staffPending = $this->seatingRound($flow['seating'], $result['order'], [
            'status' => QrOrderRound::STATUS_PENDING_CONFIRMATION, 'needs_review' => true,
            'confirm_payload' => ['private' => 'staff'], 'resolved_at' => null,
        ]);
        $credentialPending = $this->seatingRound($flow['seating'], null, [
            'qr_session_id' => $flow['session']->id, 'round_no' => 2,
            'status' => QrOrderRound::STATUS_PENDING_CONFIRMATION,
            'confirm_payload' => ['private' => 'credential'], 'resolved_at' => null,
        ]);
        $otherSeating = $this->seatingRow($this->seatingTable('Other bill'));
        $otherBill = $this->seatingOrder($otherSeating);
        $otherPending = $this->seatingRound($otherSeating, $otherBill, [
            'status' => QrOrderRound::STATUS_PENDING_CONFIRMATION,
            'confirm_payload' => ['private' => 'other bill'], 'resolved_at' => null,
        ]);
        $otherBefore = $otherPending->fresh()->getRawOriginal();
        $result['order']->update(['status' => Order::STATUS_PAID, 'closed_at' => now()]);
        $cleared = app(ClearDineInQrTableAction::class)->handle($flow['till'], (int) $flow['table']->id);
        $this->assertSame(['table_id' => (int) $flow['table']->id, 'status' => 'cleared'], $cleared);
        foreach ([$result['round'], $staffPending, $credentialPending] as $pending) {
            $this->assertSame(QrOrderRound::STATUS_REJECTED, $pending->fresh()->status);
            $this->assertNull($pending->fresh()->confirm_payload);
            $this->assertNotNull($pending->fresh()->resolved_at);
            $this->assertSame((int) $flow['till']->id, (int) $pending->fresh()->resolved_by_device_id);
        }
        $this->assertSame(QrSession::STATUS_CLOSED, $flow['session']->fresh()->status);
        $this->assertSame(TableSession::STATUS_CLOSED, $flow['seating']->fresh()->status);
        $this->assertSame($otherBefore, $otherPending->fresh()->getRawOriginal());
        $this->assertSame(Order::STATUS_OPEN, $otherBill->fresh()->status);
        $this->assertSame(TableSession::STATUS_OPEN, $otherSeating->fresh()->status);
        $this->assertSame('1.000', $result['order']->fresh()->grand_total);
    }

    /** @return array<string, array{string, string}> */
    public static function staffReceiptSources(): array
    {
        return ['till' => ['fixed_pos', 'main_pos'], 'handheld' => ['handheld', 'handheld']];
    }

    #[DataProvider('staffReceiptSources')]
    public function test_staff_seating_bill_paid_by_uuid_gets_one_receipt_without_a_qr_credential(string $deviceType, string $source): void
    {
        $this->enableReceiptNumbering();
        $flow = $this->staffOnlyFlow($deviceType);
        $bill = $flow['order'];
        $this->assertSame($source, $bill->source);
        $this->assertNull($bill->qr_session_id);
        $this->assertNull($bill->customer_id);
        $this->assertNull($bill->receipt_number);
        $this->assertSame(0, QrSession::query()->count());
        $this->assertSame(0, DB::table('pos_order_sequences')->count());
        $beforeReference = $bill->temp_reference;
        $eventId = (string) Str::uuid();
        $response = $this->payByUuid($flow['device'], $bill, $eventId)
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.receipt_number', 'T4-00001');
        $this->assertSame(Order::STATUS_PAID, $bill->fresh()->status);
        $this->assertSame('T4-00001', $bill->fresh()->receipt_number);
        $this->assertSame($beforeReference, $bill->fresh()->temp_reference);
        $this->assertSame($source, $bill->fresh()->source);
        $this->assertNull($bill->fresh()->qr_session_id);
        $this->assertSame(1, Order::query()->count());
        $this->assertSame(0, QrSession::query()->count());
        $this->assertSame(2, (int) DB::table('pos_order_sequences')->sole()->next_number);
        $this->assertSame(TableSession::STATUS_CLOSED, $flow['seating']->fresh()->status);
        $this->assertSame(TableSession::CLOSE_PAID, $flow['seating']->fresh()->close_reason);
        $this->payByUuid($flow['device'], $bill, $eventId)
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.duplicate', true)
            ->assertJsonPath('data.results.0.result.receipt_number', 'T4-00001');
        $this->assertSame(2, (int) DB::table('pos_order_sequences')->sole()->next_number);
        fwrite(STDOUT, "\nT4_STAFF_RECEIPT_".strtoupper($source).'_JSON='.$response->getContent()."\n");
    }

    public function test_existing_staff_receipt_is_preserved_and_unlinked_non_qr_sources_do_not_allocate(): void
    {
        $this->enableReceiptNumbering();
        $flow = $this->staffOnlyFlow('fixed_pos');
        $flow['order']->update(['receipt_number' => 'LEGACY-777']);
        $this->payByUuid($flow['device'], $flow['order'], (string) Str::uuid())
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.receipt_number', 'LEGACY-777');
        $this->assertSame('LEGACY-777', $flow['order']->fresh()->receipt_number);
        $this->assertSame(0, DB::table('pos_order_sequences')->count());
        // LAUNCH-P6 — a customer_tablet order now takes a server receipt number
        // at pay (proven in LaunchP6\TabletStaffFlowTest); the staff sources do not.
        foreach (['main_pos', 'handheld'] as $source) {
            $bill = Order::query()->create([
                'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
                'device_id' => $flow['device']->id, 'source' => $source, 'order_type' => 'quick',
                'status' => Order::STATUS_OPEN, 'subtotal' => '1.000', 'discount_total' => '0.000',
                'comp_total' => '0.000', 'tax_total' => '0.000', 'grand_total' => '1.000', 'opened_at' => now(),
            ]);
            $this->payByUuid($flow['device'], $bill, (string) Str::uuid())
                ->assertOk()->assertJsonPath('data.results.0.status', 'processed')
                ->assertJsonPath('data.results.0.result.receipt_number', null);
            $this->assertSame(Order::STATUS_PAID, $bill->fresh()->status);
            $this->assertSame($source, $bill->fresh()->source);
            $this->assertNull($bill->fresh()->table_session_id);
            $this->assertNull($bill->fresh()->receipt_number);
            $this->assertSame(0, DB::table('pos_order_sequences')->count());
        }
        $tablet = Order::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $flow['device']->id, 'source' => 'customer_tablet', 'order_type' => 'quick',
            'status' => Order::STATUS_HELD, 'subtotal' => '1.000', 'discount_total' => '0.000',
            'comp_total' => '0.000', 'tax_total' => '0.000', 'grand_total' => '1.000', 'opened_at' => now(),
        ]);
        $this->payByUuid($flow['device'], $tablet, (string) Str::uuid())
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertNotNull($tablet->fresh()->receipt_number);
        $this->assertSame(1, DB::table('pos_order_sequences')->count());
    }

    private function staffOnlyFlow(string $deviceType): array
    {
        $device = $this->seatingDevice($deviceType);
        $table = $this->seatingTable();
        $product = $this->seatingProduct();
        $common = ['seating_key' => (string) Str::uuid(), 'table_id' => (int) $table->id, 'queued_offline' => false];
        $opened = app(OpenStaffTableSessionAction::class)->handle($device, $common + [
            'opened_at' => now()->toIso8601String(),
        ], now(), now());
        $seating = TableSession::query()->where('uuid', $opened['table_session_uuid'])->sole();
        $round = app(AppendStaffRoundAction::class)->handle($device, $common + [
            'client_request_id' => 'staff-receipt-round', 'submitted_at' => now()->toIso8601String(),
            'lines' => [['product_id' => (int) $product->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
        ], now(), now());
        $order = Order::query()->where('uuid', $round['order_uuid'])->sole();

        return compact('device', 'table', 'seating', 'order');
    }

    private function enableReceiptNumbering(): void
    {
        DB::table('pos_company_settings')->insert([
            'company_id' => 100, 'key' => 'order_numbering',
            'value' => json_encode([
                'enabled' => true, 'prefix' => 'T4-', 'pad' => 5, 'scope' => 'branch', 'daily_reset' => false,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function payByUuid(Device $device, Order $order, string $eventId): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', [
            'events' => [[
                'client_event_id' => $eventId, 'event_type' => 'order.pay', 'client_timestamp' => now()->toIso8601String(),
                'payload' => [
                    'order_uuid' => $order->uuid, 'paid_at' => now()->toIso8601String(),
                    'payments' => [['method' => 'cash', 'amount_baisas' => 1000]],
                ],
            ]],
        ]);
    }

    private function flow(): array
    {
        $station = $this->seatingDevice('payment_station');
        $till = $this->seatingDevice();
        $table = $this->seatingTable();
        $product = $this->seatingProduct();
        $opened = app(OpenDineInTableAction::class)->handle($station, (int) $table->id);
        $session = app(BindQrTableSessionAction::class)->handle($opened['table_token'], 'shared-bill-secret');
        $this->assertNotNull($session);
        $seating = TableSession::findOrFail($session->table_session_id);
        $common = ['seating_key' => (string) Str::uuid(), 'table_id' => (int) $table->id, 'queued_offline' => false];
        app(OpenStaffTableSessionAction::class)->handle($till, $common + ['opened_at' => now()->toIso8601String()], now(), now());

        return compact('station', 'till', 'table', 'product', 'session', 'seating', 'common');
    }

    private function lines(array $flow): array
    {
        return [['product_id' => (int) $flow['product']->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]];
    }

    private function staffRound(array $flow, string $key = 'staff-round'): array
    {
        return app(AppendStaffRoundAction::class)->handle($flow['till'], $flow['common'] + [
            'client_request_id' => $key, 'submitted_at' => now()->toIso8601String(),
            'lines' => $this->lines($flow),
        ], now(), now());
    }

    private function customerRound(array $flow): array
    {
        return app(SubmitDineInQrRoundAction::class)->handle((int) $flow['session']->id, [
            'client_request_id' => 'customer-round', 'phone' => '92001234', 'plate_number' => 'om 77',
            'lines' => $this->lines($flow),
        ], '127.0.0.1');
    }

    private function staffConfirmMode(): void
    {
        DB::table('pos_branch_settings')->updateOrInsert(
            ['company_id' => 100, 'branch_id' => 10, 'key' => 'dine_in_round_mode'],
            ['value' => json_encode('staff_confirm'), 'created_at' => now(), 'updated_at' => now()],
        );
    }

    private function qrStatus(QrSession $session): array
    {
        $request = Request::create('/api/v1/public/qr/status');
        $request->attributes->set('qr_session', $session->fresh());

        return app(QrStatusController::class)($request)->getData(true);
    }
}
