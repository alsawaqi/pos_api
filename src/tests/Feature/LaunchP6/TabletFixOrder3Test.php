<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP6;

use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Ramsey\Uuid\Uuid;
use Tests\Support\LaunchP6Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P6 Part A fix order 3 (LAUNCH-P6_A_FIX_ORDER_3.md) — F-13 (a lapsed
 * tablet cash claim recovers like a QR quick order's) and F-14 (a payer shift
 * on a deleted device never takes a payment).
 */
final class TabletFixOrder3Test extends TestCase
{
    use LaunchP6Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p6Setup();
    }

    // ---- F-13 — a lapsed tablet cash claim ----

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} the tablet answer and the claim */
    private function lapsedClaim(bool $sweep, ?Device $holder = null): array
    {
        $order = $this->p6Submit()->assertCreated()->json('data');
        $claim = $this->p6As($holder ?? $this->handheld, 'POST', '/api/v1/device/qr/claim-settlement', ['order_uuid' => $order['order_uuid']])
            ->assertOk()->json('data');
        // The holder goes offline past the 300 s claim (and the sweeper's grace).
        $this->travel(6)->minutes();
        if ($sweep) {
            Artisan::call('qr:sweep-stale-charges');
            $this->assertSame(Order::CHARGE_OUTCOME_LAPSED, $this->p6Order($order['order_uuid'])->charge_outcome);
        } else {
            $this->assertNull($this->p6Order($order['order_uuid'])->charge_outcome);
        }
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $this->p6Order($order['order_uuid'])->status);

        return [$order, $claim];
    }

    /** @return array<string, mixed> the sync result */
    private function pay(Device $device, string $orderUuid, int $amount = 2000): array
    {
        return $this->p6PayCash($device, $orderUuid, $amount)->assertOk()->json('data.results.0');
    }

    private function payments(string $orderUuid): int
    {
        return Payment::query()->where('order_id', $this->p6Order($orderUuid)->id)->count();
    }

    private function fallback(Device $device, string $orderUuid): TestResponse
    {
        return $this->p6As($device, 'POST', '/api/v1/device/qr/fallback-to-counter', ['order_uuid' => $orderUuid]);
    }

    private function review(Device $device, string $orderUuid, string $decision, ?string $pin = '800008'): TestResponse
    {
        return $this->p6As($device, 'POST', "/api/v1/device/qr/pending-orders/{$orderUuid}/payment-review", [
            'client_request_id' => (string) Str::uuid(), 'decision' => $decision, 'reference' => 'Drawer count', 'pin' => $pin,
        ] + ($decision === 'paid' ? ['method' => 'cash', 'amount_baisas' => 2000] : []));
    }

    private function void(string $orderUuid): array
    {
        return $this->p6As($this->till, 'POST', '/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'order.void', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $orderUuid, 'staff_id' => 7, 'voided_at' => now()->toIso8601String()],
        ]]])->assertOk()->json('data.results.0');
    }

    public function test_f13_the_holders_late_cash_pay_is_recorded_once_before_and_after_the_sweeper(): void
    {
        foreach ([false, true] as $sweep) {
            [$order] = $this->lapsedClaim($sweep);
            $uuid = $order['order_uuid'];

            // Another device cannot take the money on the lapsed claim.
            $other = $this->pay($this->till, $uuid);
            $this->assertSame('failed', $other['status']);
            $this->assertStringContainsString('awaiting-payment order has no live charge claim', (string) $other['result']['error']);
            // The holder's late pay is recorded, at the frozen amount only.
            $this->assertSame('failed', $this->pay($this->handheld, $uuid, 3000)['status']);
            $this->assertSame('processed', $this->pay($this->handheld, $uuid)['status'], $sweep ? 'swept' : 'not swept');

            $paid = $this->p6Order($uuid);
            $this->assertSame([Order::STATUS_PAID, 1], [$paid->status, $this->payments($uuid)]);
            // The charge facts stay as evidence.
            $this->assertSame([(int) $this->handheld->id, 2000, $sweep ? Order::CHARGE_OUTCOME_LAPSED : null],
                [(int) $paid->charge_device_id, (int) $paid->charge_amount_baisas, $paid->charge_outcome]);
            // A resent pay (a new event) never records the cash twice.
            $this->assertSame('failed', $this->pay($this->handheld, $uuid)['status']);
            $this->assertSame(1, $this->payments($uuid));
        }
    }

    public function test_f13_another_device_recovers_through_the_counter_fallback_and_the_cash_is_recorded_once(): void
    {
        [$order] = $this->lapsedClaim(true);
        $uuid = $order['order_uuid'];
        $before = $this->p6Order($uuid);

        $this->fallback($this->till, $uuid)->assertOk()->assertJsonPath('data.status', Order::STATUS_HELD)
            ->assertJsonPath('data.temp_reference', $before->temp_reference);
        $held = $this->p6Order($uuid);
        $this->assertSame([$before->charge_device_id, $before->charge_amount_baisas, Order::CHARGE_OUTCOME_LAPSED],
            [$held->charge_device_id, $held->charge_amount_baisas, $held->charge_outcome]);
        // A repeat is idempotent; the order still cannot be edited or re-claimed.
        $this->fallback($this->till, $uuid)->assertOk()->assertJsonPath('data.status', Order::STATUS_HELD);
        $this->p6Staff($this->till, 7, 'PUT', "/api/v1/device/tablet-orders/{$order['tablet_order_uuid']}/lines",
            ['client_request_id' => (string) Str::uuid(), 'lines' => [$this->p6Line($this->coffee, 3)]])
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'tablet_order_paid');
        $this->p6As($this->till, 'POST', '/api/v1/device/qr/claim-settlement', ['order_uuid' => $uuid])->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'qr_charge_recovery_required');

        $this->assertSame('processed', $this->pay($this->till, $uuid)['status']);
        // The holder's late pay now finds the sale paid.
        $late = $this->pay($this->handheld, $uuid);
        $this->assertSame('failed', $late['status']);
        $this->assertStringContainsString('already paid', (string) $late['result']['error']);
        $this->assertSame([Order::STATUS_PAID, 1], [$this->p6Order($uuid)->status, $this->payments($uuid)]);
    }

    public function test_f13_a_manager_review_resolves_a_lapsed_claim_either_way_and_void_follows_where_allowed(): void
    {
        [$taken] = $this->lapsedClaim(false);
        [$notTaken] = $this->lapsedClaim(true);

        // Void is refused while the charge is unresolved.
        $refused = $this->void($notTaken['order_uuid']);
        $this->assertSame('failed', $refused['status']);
        $this->assertStringContainsString('fallback-to-counter before void', (string) $refused['result']['error']);
        $this->review($this->till, $taken['order_uuid'], 'paid', '9999')->assertStatus(401)->assertJsonPath('errors.0.code', 'invalid_pin');

        // Paid: the money is recorded once, by the review.
        $this->review($this->till, $taken['order_uuid'], 'paid')->assertOk()->assertJsonPath('data.status', Order::STATUS_PAID)
            ->assertJsonPath('data.charge_before', 'uncertain')->assertJsonPath('data.approved_by_staff_id', 8);
        $this->assertSame(1, $this->payments($taken['order_uuid']));
        $this->assertSame('failed', $this->pay($this->handheld, $taken['order_uuid'])['status']);
        $this->review($this->till, $taken['order_uuid'], 'paid')->assertStatus(409)->assertJsonPath('errors.0.code', 'order_not_unpaid');
        $this->assertSame(1, $this->payments($taken['order_uuid']));

        // Not paid: the order is free again — it can change and be voided.
        $this->review($this->till, $notTaken['order_uuid'], 'not_paid')->assertOk()->assertJsonPath('data.status', Order::STATUS_HELD);
        $free = $this->p6Order($notTaken['order_uuid']);
        $this->assertSame([null, null, null], [$free->charge_device_id, $free->charge_claimed_at, $free->charge_outcome]);
        $this->p6Staff($this->till, 7, 'PUT', "/api/v1/device/tablet-orders/{$notTaken['tablet_order_uuid']}/lines",
            ['client_request_id' => (string) Str::uuid(), 'lines' => [$this->p6Line($this->coffee, 3)]])->assertOk();
        $this->assertSame('processed', $this->void($notTaken['order_uuid'])['status']);
        $this->assertSame([Order::STATUS_VOID, 0], [$this->p6Order($notTaken['order_uuid'])->status, $this->payments($notTaken['order_uuid'])]);
    }

    public function test_f13_recovery_keeps_tenancy_and_needs_an_attended_device(): void
    {
        [$order] = $this->lapsedClaim(true);
        $uuid = $order['order_uuid'];
        $before = $this->p6Order($uuid)->getAttributes();
        $this->p5Staff(20, 'manager', '200020', overrides: ['company_id' => 200, 'branch_id' => 20]);
        $this->p5Staff(21, 'manager', '200021', overrides: ['company_id' => 100, 'branch_id' => 11]);

        foreach ([[$this->p6Device('mdev_p6_y_till', 'fixed_pos', 200, 20), '200020'], [$this->p6Device('mdev_p6_b11', 'fixed_pos', 100, 11), '200021']] as [$device, $pin]) {
            $this->fallback($device, $uuid)->assertNotFound()->assertJsonPath('errors.0.code', 'order_not_found');
            $this->review($device, $uuid, 'paid', $pin)->assertNotFound()->assertJsonPath('errors.0.code', 'order_not_found');
            $this->assertSame('failed', $this->pay($device, $uuid)['status']);
        }
        // A customer tablet can do none of it.
        $this->fallback($this->tablet, $uuid)->assertForbidden();
        $this->review($this->tablet, $uuid, 'paid')->assertForbidden();

        $this->assertSame($before, $this->p6Order($uuid)->getAttributes());
        $this->assertSame(0, $this->payments($uuid));
    }

    public function test_f13_a_qr_web_quick_order_keeps_todays_rule_the_holders_late_pay_is_still_refused(): void
    {
        $qr = Order::query()->create(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => null, 'source' => Order::SOURCE_QR_WEB, 'order_type' => 'quick', 'status' => Order::STATUS_HELD,
            'subtotal' => '2.000', 'discount_total' => '0.000', 'comp_total' => '0.000', 'tax_total' => '0.000',
            'grand_total' => '2.000', 'opened_at' => now()->subMinutes(5), 'temp_reference' => 'T-1006-090']);
        $this->p6As($this->handheld, 'POST', '/api/v1/device/qr/claim-settlement', ['order_uuid' => $qr->uuid])->assertOk();
        $this->travel(6)->minutes();
        Artisan::call('qr:sweep-stale-charges');

        $late = $this->pay($this->handheld, $qr->uuid);
        $this->assertSame('failed', $late['status']);
        $this->assertStringContainsString('awaiting-payment order has no live charge claim', (string) $late['result']['error']);
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $qr->fresh()->status);
    }

    // ---- F-14 — a payer shift on a deleted device ----

    private function openShift(string $token, int $staffId): string
    {
        $uuid = (string) Str::uuid();
        $this->p5Push($token, [$this->p5Event('shift.open', ['uuid' => $uuid, 'staff_id' => $staffId, 'shared_shift' => true,
            'opening_cash_baisas' => 5000, 'opened_at' => now()->subHour()->toIso8601String()], at: now()->subHour()->toIso8601String())])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        return $uuid;
    }

    public function test_f14_a_payer_shift_whose_device_was_deleted_leaves_the_payment_with_the_paying_devices_shift(): void
    {
        $tillShift = $this->openShift('mdev_p6_till', 7);
        $handheldShift = $this->openShift('mdev_p6_handheld', 9);
        // Staff 7's till is deleted (its shift keeps no device).
        DB::table('pos_shifts')->where('uuid', $tillShift)->update(['device_id' => null]);
        $tablet = $this->p6Submit()->assertCreated()->json('data');
        $this->assertSame('processed', $this->p6PayCash($this->handheld, $tablet['order_uuid'], 2000, 7)->json('data.results.0.status'));

        $close = $this->p5Push('mdev_p6_handheld', [$this->p5Event('shift.close', ['shift_uuid' => $handheldShift, 'closing_cash_baisas' => 7000,
            'closed_at' => now()->toIso8601String(), 'closed_by_staff_id' => 9, 'order_uuids' => [], 'auth_v' => 1],
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'shift-close:'.$handheldShift)->toString())])->assertOk()->json('data.results.0');

        $this->assertSame('processed', $close['status'], json_encode($close));
        $this->assertSame([7000, 0, 1], [$close['result']['expected_cash_baisas'], $close['result']['variance_baisas'],
            $close['result']['summary']['order_count']]);
    }
}
