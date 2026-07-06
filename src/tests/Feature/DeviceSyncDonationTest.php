<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Payment;
use App\Models\RoundupDonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 8 — donation.record sync handler (card-payment round-up → charity).
 *
 * A paired device (company 100 / branch 10) emits donation.record after a
 * card payment; it lands in pos_roundup_donations and links the payment.
 */
class DeviceSyncDonationTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $token = 'mdev_x'): Device
    {
        return Device::factory()->paired($token)->create([
            'company_id' => 100,
            'branch_id' => 10,
            'bank_id' => 5,
            'terminal_id' => 'TID-9',
            'commission_profile_id' => 7,
            'organization_id' => 3,
        ]);
    }

    private function seedBranch(): void
    {
        DB::table('pos_branches')->insert([
            'id' => 10, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Main',
            'latitude' => 23.5880000, 'longitude' => 58.4060000,
            'country_id' => 1, 'region_id' => 2, 'district_id' => 3, 'city_id' => 4,
            'geofence_radius_m' => 500, 'default_order_type' => 'dine_in', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @return array{0:int,1:int}
     */
    private function seedOrderAndCard(string $orderUuid = 'order-uuid-1', bool $pending = false): array
    {
        $orderId = DB::table('pos_orders')->insertGetId([
            'uuid' => $orderUuid, 'company_id' => 100, 'branch_id' => 10,
            'order_type' => 'quick', 'status' => 'paid', 'source' => 'main_pos',
            'subtotal' => '4.800', 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => '4.800',
            'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $paymentId = DB::table('pos_payments')->insertGetId([
            'uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'method' => 'card',
            'amount' => '5.000',
            'status' => $pending ? 'pending_reconciliation' : 'success',
            'pending_reconciliation' => $pending,
            'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$orderId, $paymentId];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function donationEvent(array $payload = []): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'donation.record',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => array_merge([
                'order_uuid' => 'order-uuid-1',
                'amount_baisas' => 200,
                'receipt' => ['status' => 'success', 'approvalCode' => 'XYZ'],
            ], $payload),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(string $token, array $events): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }

    public function test_donation_record_writes_a_roundup_donation_and_links_the_payment(): void
    {
        $this->device();
        $this->seedBranch();
        [$orderId, $paymentId] = $this->seedOrderAndCard();

        $res = $this->push('mdev_x', [$this->donationEvent()])->assertOk();
        $r = $res->json('data.results.0');

        $this->assertSame('processed', $r['status']);
        $this->assertNotNull($r['result']['roundup_donation_id']);
        $this->assertSame('success', $r['result']['status']);

        $this->assertDatabaseHas('pos_roundup_donations', [
            'company_id' => 100, 'branch_id' => 10, 'order_id' => $orderId, 'payment_id' => $paymentId,
            'bank_id' => 5, 'terminal_id' => 'TID-9', 'commission_profile_id' => 7,
            'source' => 'pos_roundup', 'status' => 'success',
            'country_id' => 1, 'region_id' => 2, 'district_id' => 3, 'city_id' => 4,
        ]);

        $donation = RoundupDonation::firstOrFail();
        $this->assertSame('0.200', $donation->amount);
        $this->assertSame('success', $donation->bank_response['status']);

        $payment = Payment::findOrFail($paymentId);
        $this->assertSame('0.200', $payment->roundup_amount);
        $this->assertSame((int) $donation->id, (int) $payment->charity_transaction_id);
    }

    public function test_replaying_a_donation_does_not_duplicate(): void
    {
        $this->device();
        $this->seedBranch();
        $this->seedOrderAndCard();
        $event = $this->donationEvent();

        $this->push('mdev_x', [$event])->assertOk();
        $res = $this->push('mdev_x', [$event])->assertOk();

        $res->assertJsonPath('data.summary.duplicates', 1);
        $this->assertDatabaseCount('pos_roundup_donations', 1);
    }

    public function test_donation_for_an_unknown_order_fails(): void
    {
        $this->device();
        $this->seedBranch();

        $res = $this->push('mdev_x', [$this->donationEvent(['order_uuid' => 'nope'])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('order not found', $res->json('data.results.0.result.error'));
        $this->assertDatabaseCount('pos_roundup_donations', 0);
    }

    public function test_donation_status_reflects_the_card_settlement_not_a_payload_receipt(): void
    {
        $this->device();
        $this->seedBranch();
        $this->seedOrderAndCard(); // settled card

        // A stray payload receipt no longer decides the status — the CONFIRMED
        // card settlement does. The device does not resend a receipt in
        // practice, so a settled ride is always 'success'.
        $res = $this->push('mdev_x', [$this->donationEvent(['receipt' => ['status' => 'timeout']])])->assertOk();

        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertSame('success', $res->json('data.results.0.result.status'));
        $this->assertSame('success', RoundupDonation::firstOrFail()->status);
    }

    public function test_forwarded_receipt_and_status_come_from_the_card_when_the_device_sends_none(): void
    {
        config(['services.charity.url' => 'http://charity.test']);
        Http::fake(['*' => Http::response(['success' => true], 201)]);

        $this->device();
        $this->seedBranch();
        $orderId = DB::table('pos_orders')->insertGetId([
            'uuid' => 'order-uuid-1', 'company_id' => 100, 'branch_id' => 10,
            'order_type' => 'quick', 'status' => 'paid', 'source' => 'main_pos',
            'subtotal' => '4.800', 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => '4.800',
            'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_payments')->insert([
            'uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'method' => 'card',
            'amount' => '5.000', 'status' => 'success', 'pending_reconciliation' => false,
            'bank_response' => json_encode(['rrn' => 'RRN-1', 'approvalCode' => 'A1']),
            'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The device sends NO receipt on donation.record (the real payload).
        $event = $this->donationEvent();
        unset($event['payload']['receipt']);
        $this->push('mdev_x', [$event])->assertOk();

        // The forward carries the CARD's real bank response + an explicit
        // success status, so charity never mis-files the round-up as 'fail'.
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/donations-pos-roundup')
                && $request['status'] === 'success'
                && ($request['receipt']['rrn'] ?? null) === 'RRN-1';
        });

        $donation = RoundupDonation::firstOrFail();
        $this->assertSame('success', $donation->status);
        $this->assertSame('RRN-1', $donation->bank_response['rrn']);
        $this->assertNotNull($donation->forwarded_at);
    }

    public function test_donation_is_forwarded_to_the_charity_pos_roundup_endpoint(): void
    {
        config(['services.charity.url' => 'http://charity.test']);
        Http::fake(['*' => Http::response(['success' => true], 201)]);

        $device = $this->device();
        $this->seedBranch();
        $this->seedOrderAndCard();

        $res = $this->push('mdev_x', [$this->donationEvent()])->assertOk();
        $this->assertSame('processed', $res->json('data.results.0.status'));

        // Forwarded to the POS round-up endpoint, linking the POS device + branch
        // with the branch geo + the device's charity commission profile.
        Http::assertSent(function ($request) use ($device) {
            return str_contains($request->url(), '/api/donations-pos-roundup')
                && $request['pos_device_id'] === $device->id
                && $request['pos_branch_id'] === 10
                && $request['pos_branch_name'] === 'Main'
                && $request['commission_profile_id'] === 7
                && $request['organization_id'] === 3
                && $request['bank_id'] === 5
                && $request['amount'] === '0.200'
                && $request['status'] === 'success'
                && $request['terminal_id'] === 'TID-9'
                && $request['country_id'] === 1
                && ($request['receipt']['status'] ?? null) === 'success';
        });

        // The POS round-up still records normally, stamped as forwarded so
        // the admin reconciliation paths never forward it twice (P-F7).
        $this->assertDatabaseCount('pos_roundup_donations', 1);
        $this->assertNotNull(RoundupDonation::firstOrFail()->forwarded_at);
    }

    /**
     * P-F7 — the round-up rides a force-recorded (pending_reconciliation)
     * card charge: the donation row is still created, but the charity
     * forwarding is DEFERRED (forwarded_at stays NULL) until the platform
     * admin approves the order (pos_admin ApprovePendingReconciliationAction).
     */
    public function test_a_pending_reconciliation_order_records_but_does_not_forward_the_roundup(): void
    {
        config(['services.charity.url' => 'http://charity.test']);
        Http::fake(['*' => Http::response(['success' => true], 201)]);

        $this->device();
        $this->seedBranch();
        [$orderId, $paymentId] = $this->seedOrderAndCard(pending: true);

        $res = $this->push('mdev_x', [$this->donationEvent()])->assertOk();

        // Recorded as usual — payment linked, amount snapshotted, held 'pending'
        // (money not confirmed) until the admin approves it…
        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertDatabaseHas('pos_roundup_donations', [
            'order_id' => $orderId, 'payment_id' => $paymentId, 'status' => 'pending',
        ]);

        // …but nothing went to charity: money not confirmed yet.
        Http::assertNothingSent();
        $this->assertNull(RoundupDonation::firstOrFail()->forwarded_at);
    }

    public function test_a_charity_forward_failure_never_breaks_the_roundup(): void
    {
        config(['services.charity.url' => 'http://charity.test']);
        // A POS-only device → store_dhofar 500s "Device not found".
        Http::fake(['*' => Http::response(['success' => false, 'message' => 'Device not found'], 500)]);

        $this->device(); // random kiosk_id, no charity twin
        $this->seedBranch();
        $this->seedOrderAndCard();

        $res = $this->push('mdev_x', [$this->donationEvent()])->assertOk();

        // Round-up still processed + recorded; the charity miss is swallowed.
        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertSame('success', $res->json('data.results.0.result.status'));
        $this->assertDatabaseCount('pos_roundup_donations', 1);
        // P-F7 — a failed forward leaves the marker NULL so the admin
        // reconciliation paths can retry it later.
        $this->assertNull(RoundupDonation::firstOrFail()->forwarded_at);
    }

    public function test_no_charity_forward_when_the_url_is_unset(): void
    {
        config(['services.charity.url' => null]);
        Http::fake();

        $this->device();
        $this->seedBranch();
        $this->seedOrderAndCard();

        $this->push('mdev_x', [$this->donationEvent()])->assertOk();

        Http::assertNothingSent();
        $this->assertDatabaseCount('pos_roundup_donations', 1);
    }

    public function test_donation_for_a_cross_tenant_order_fails(): void
    {
        $this->device(); // company 100
        $this->seedBranch();
        DB::table('pos_orders')->insert([
            'uuid' => 'foreign-order', 'company_id' => 200, 'branch_id' => 10,
            'order_type' => 'quick', 'status' => 'paid', 'source' => 'main_pos',
            'grand_total' => '4.800', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->push('mdev_x', [$this->donationEvent(['order_uuid' => 'foreign-order'])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertDatabaseCount('pos_roundup_donations', 0);
    }
}
