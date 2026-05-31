<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Payment;
use App\Models\RoundupDonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    private function seedOrderAndCard(string $orderUuid = 'order-uuid-1'): array
    {
        $orderId = DB::table('pos_orders')->insertGetId([
            'uuid' => $orderUuid, 'company_id' => 100, 'branch_id' => 10,
            'order_type' => 'quick', 'status' => 'paid', 'source' => 'main_pos',
            'subtotal' => '4.800', 'discount_total' => 0, 'tax_total' => 0, 'grand_total' => '4.800',
            'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $paymentId = DB::table('pos_payments')->insertGetId([
            'uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'method' => 'card',
            'amount' => '5.000', 'status' => 'success', 'pending_reconciliation' => false,
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

    public function test_donation_status_follows_the_bank_receipt(): void
    {
        $this->device();
        $this->seedBranch();
        $this->seedOrderAndCard();

        $res = $this->push('mdev_x', [$this->donationEvent(['receipt' => ['status' => 'timeout']])])->assertOk();

        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertSame('fail', $res->json('data.results.0.result.status'));
        $this->assertSame('fail', RoundupDonation::firstOrFail()->status);
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
