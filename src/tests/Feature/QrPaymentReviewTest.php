<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\PresentQrPendingOrderAction;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\SyncEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\QrPendingTestCase;

// Payment review (owner 2026-09-29, a1 b1 c1): a manager on the till decides whether
// money was taken for a stuck QR quick order; a reference is required and audited.
final class QrPaymentReviewTest extends QrPendingTestCase
{
    private int $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = DB::table('pos_staff')->insertGetId(['uuid' => Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'name' => 'Review synthetic manager', 'pin_hash' => Hash::make('4321'), 'position' => 'manager', 'status' => 'active']);
        Branch::query()->create(['id' => 10, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Review branch',
            'latitude' => null, 'longitude' => null, 'geofence_radius_m' => 500, 'status' => 'active']);
    }

    private function review(Order $order, array $input, ?Device $device = null): TestResponse
    {
        return $this->postAs($device ?? $this->till, '/api/v1/device/qr/pending-orders/'.$order->uuid.'/payment-review', $input);
    }

    private function input(string $decision, array $extra = []): array
    {
        return $extra + ['client_request_id' => (string) Str::uuid(), 'decision' => $decision, 'reference' => 'REF-1001', 'pin' => '4321']
            + ($decision === 'paid' ? ['method' => 'cash', 'amount_baisas' => 4750] : []);
    }

    private function stuck(string $charge = 'uncertain', array $attributes = []): Order
    {
        return $this->order($attributes + ['status' => 'awaiting_payment'] + $this->charge($charge), 'closed');
    }

    public function test_no_money_taken_frees_an_uncertain_order_and_is_audited_and_idempotent(): void
    {
        $order = $this->stuck();
        $this->getPending()->assertOk()->assertJsonPath('data.orders.0.charge', 'uncertain');
        $input = $this->input('not_paid', ['local_summary' => 'Card cancelled on the station']);
        $this->review($order, $input)->assertOk()->assertJsonPath('data.status', 'held')
            ->assertJsonPath('data.decision', 'not_paid')->assertJsonPath('data.charge_before', 'uncertain')
            ->assertJsonPath('data.approved_by_staff_id', $this->manager)->assertJsonPath('data.replayed', false);
        $raw = $this->raw($order);
        foreach (PresentQrPendingOrderAction::CHARGE_FIELDS as $field) {
            $this->assertNull($raw[$field], $field);
        }
        $after = $this->snapshot();
        $this->review($order, $input)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame($after, $this->snapshot());

        $audit = SyncEvent::query()->sole();
        $this->assertSame('qr.quick.payment_review', $audit->event_type);
        $this->assertSame((int) $this->till->id, (int) $audit->device_id);
        $this->assertArrayNotHasKey('pin', $audit->payload_json);
        $this->assertSame('REF-1001', $audit->result_json['reference']);
        $this->assertSame('Card cancelled on the station', $audit->payload_json['local_summary']);
        $this->assertSame('uncertain', $audit->result_json['previous_charge']['charge_outcome']);
        $this->assertEquals(4750, $audit->result_json['previous_charge']['charge_amount_baisas']);
        $this->assertEquals($this->station->id, $audit->result_json['previous_charge']['charge_device_id']);
        $this->assertDatabaseCount('pos_payments', 0);

        // Freed: it can be settled again, or cancelled because its phone session ended.
        $this->getPending()->assertOk()->assertJsonPath('data.orders.0.charge', 'none')
            ->assertJsonPath('data.orders.0.actions.settle', true);
        $this->app['auth']->forgetGuards();
        $this->withToken($this->till->device_token)->getJson('/api/v1/device/qr/pending-orders/cancel-preview?order_uuid='.$order->uuid)
            ->assertOk()->assertJsonPath('data.count', 1);

        $input['reference'] = 'REF-OTHER';
        $this->review($order, $input)->assertConflict()->assertJsonPath('errors.0.code', 'idempotency_conflict');
    }

    public function test_money_taken_in_cash_is_recorded_through_the_normal_pay_path_once(): void
    {
        // The T3 shape: server says the charge was cancelled, the till holds a refused cash capture.
        $order = $this->stuck('cancelled');
        $input = $this->input('paid', ['local_attempt_ids' => [(string) Str::uuid()],
            'local_summary' => 'Cash 4.750 captured on this till; server refused']);
        $response = $this->review($order, $input)->assertOk()->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.decision', 'paid')->assertJsonPath('data.method', 'cash')->assertJsonPath('data.amount_baisas', 4750);

        $fresh = $order->fresh();
        $this->assertSame('paid', $fresh->status);
        $this->assertNotNull($fresh->closed_at);
        $payment = Payment::query()->sole();
        $this->assertSame('cash', $payment->method);
        $this->assertSame('success', $payment->status);
        $this->assertEquals(4.75, (float) $payment->amount);
        $this->assertSame((int) $this->till->id, (int) $payment->device_id);
        $pay = SyncEvent::query()->where('event_type', 'order.pay')->sole();
        $this->assertSame('processed', $pay->ack_status);
        $this->assertSame($pay->client_event_id, $response->json('data.pay_event_id'));
        $this->assertSame([(int) $payment->id], $response->json('data.payment_ids'));
        $this->assertSame($input['client_request_id'], $pay->payload_json['payment_review_request_id']);
        $this->assertArrayNotHasKey('pin', $pay->payload_json);
        $this->assertSame('closed', $this->sessionRaw($order)['status']);

        $this->review($order, $input)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertSame(1, Payment::query()->count());
        $this->assertSame(2, SyncEvent::query()->count());
        $this->getPending()->assertOk()->assertJsonCount(0, 'data.orders');
        // A second, different review can never pay again.
        $this->review($order, $this->input('paid'))->assertConflict()->assertJsonPath('errors.0.code', 'order_not_unpaid');
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_money_taken_by_card_waits_for_the_bank_match_and_keeps_the_charge_facts(): void
    {
        $order = $this->stuck('lapsed');
        $this->withHeader('X-Mithqal-SoftPos-Capable', '1');
        $this->review($order, $this->input('paid', ['method' => 'card', 'reference' => 'RRN 123456']))
            ->assertOk()->assertJsonPath('data.status', 'paid');
        $payment = Payment::query()->sole();
        $this->assertSame('card', $payment->method);
        $this->assertSame('pending_reconciliation', $payment->status);
        $this->assertTrue((bool) $payment->pending_reconciliation);
        $this->assertSame('RRN 123456', $payment->softpos_reference);
        $this->assertSame('lapsed', $this->raw($order)['charge_outcome'], 'possibly-charged provenance survives');
        $this->assertNull($this->till->fresh()->card_tenders_blocked_reason);
    }

    public function test_a_timed_out_station_claim_with_no_result_can_be_reviewed_either_way(): void
    {
        $taken = $this->stuck('expired_claim');
        $notTaken = $this->stuck('expired_claim');
        $this->getPending()->assertOk()->assertJsonPath('data.orders.0.charge', 'uncertain')
            ->assertJsonPath('data.orders.1.charge', 'uncertain');
        $this->review($taken, $this->input('paid'))->assertOk()->assertJsonPath('data.status', 'paid');
        $this->review($notTaken, $this->input('not_paid'))->assertOk()->assertJsonPath('data.status', 'held');
        $this->assertSame(1, Payment::query()->where('order_id', $taken->id)->count());
        $this->assertSame(0, Payment::query()->where('order_id', $notTaken->id)->count());
        $this->assertNull($this->raw($notTaken)['charge_claimed_at']);
        $this->getPending()->assertOk()->assertJsonCount(1, 'data.orders')->assertJsonPath('data.orders.0.charge', 'none');
    }

    public function test_wrong_pin_and_invalid_input_change_nothing(): void
    {
        $order = $this->stuck();
        $before = $this->snapshot();
        $this->review($order, $this->input('paid', ['pin' => '9999']))->assertStatus(401)->assertJsonPath('errors.0.code', 'invalid_pin');
        $this->review($order, array_diff_key($this->input('paid'), ['reference' => true]))->assertUnprocessable();
        $this->review($order, $this->input('not_paid', ['reference' => '   ']))->assertUnprocessable();
        $this->review($order, array_diff_key($this->input('paid'), ['method' => true]))->assertUnprocessable();
        $this->review($order, $this->input('paid', ['method' => 'gift']))->assertUnprocessable();
        $this->review($order, $this->input('not_paid', ['amount_baisas' => 4750]))->assertUnprocessable();
        $this->review($order, $this->input('maybe'))->assertUnprocessable();
        $this->review($order, $this->input('paid', ['amount_baisas' => 4000]))->assertConflict()
            ->assertJsonPath('errors.0.code', 'amount_mismatch');
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('pos_sync_events', 0);
        $this->assertDatabaseCount('pos_payments', 0);
    }

    public function test_orders_that_are_not_stuck_or_not_ours_are_refused(): void
    {
        $cases = [
            'nothing_to_review' => [$this->order([], 'closed'), null],
            'charge_already_claimed' => [$this->order(['status' => 'awaiting_payment'] + $this->charge('live_claim')), null],
            'order_not_unpaid' => [$this->stuck('uncertain', ['status' => 'paid']), null],
            'order_not_editable' => [$this->stuck('uncertain', ['transferred_to_device_id' => $this->station->id]), null],
            'order_not_found' => [$this->stuck('uncertain', ['branch_id' => 20]), null],
            'device_not_attended' => [$this->stuck(), $this->station],
        ];
        $withPayment = $this->stuck();
        Payment::query()->create(['uuid' => (string) Str::uuid(), 'order_id' => $withPayment->id, 'method' => 'card',
            'amount' => '4.750', 'status' => 'pending_reconciliation', 'pending_reconciliation' => true,
            'device_id' => $this->station->id, 'captured_at' => now()]);
        $cases['payment_already_recorded'] = [$withPayment, null];
        $before = $this->snapshot();
        foreach ($cases as $code => [$order, $device]) {
            // Local evidence would make a plain unpaid order reviewable, so the first case sends none.
            $local = $code === 'nothing_to_review' ? [] : ['local_attempt_ids' => ['attempt-1']];
            $response = $this->review($order, $this->input('not_paid', $local), $device);
            $this->assertSame($code, $response->json('errors.0.code'), $code);
            $this->assertContains($response->status(), [404, 409], $code);
        }
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('pos_sync_events', 0);
        $this->assertSame(1, Payment::query()->count());
    }

    public function test_money_taken_uses_the_normal_geofence_and_rolls_back_when_refused(): void
    {
        Branch::query()->whereKey(10)->update(['latitude' => '23.5800000', 'longitude' => '58.3800000', 'geofence_radius_m' => 100]);
        $order = $this->stuck();
        $before = $this->snapshot();
        $this->review($order, $this->input('paid'))->assertConflict()->assertJsonPath('errors.0.code', 'gps_required');
        $this->review($order, $this->input('paid', ['gps' => ['lat' => 23.70, 'lng' => 58.50]]))->assertConflict()
            ->assertJsonPath('errors.0.code', 'payment_refused');
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('pos_sync_events', 0);
        $this->assertDatabaseCount('pos_payments', 0);

        $this->review($order, $this->input('paid', ['gps' => ['lat' => 23.5801, 'lng' => 58.3801]]))->assertOk()
            ->assertJsonPath('data.status', 'paid');
        $this->assertSame(1, Payment::query()->count());
    }
}
