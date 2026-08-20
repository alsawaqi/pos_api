<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Device\Sync\Handlers\VoidOrderHandler;
use App\Models\Device;
use App\Models\Payment;
use App\Models\RoundupDonation;
use App\Models\SyncEvent;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

    protected function setUp(): void
    {
        parent::setUp();

        // This suite creates a fresh ledger with no historical quarantine.
        config(['sync.stranded_sweep_after_id' => 0]);
    }

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

    private function assertUnacceptedCharityResponseLeavesDonationRetriable(mixed $charityResponse): void
    {
        config(['services.charity.url' => 'http://charity.test']);
        Http::fake(['*' => $charityResponse]);

        $this->device();
        $this->seedBranch();
        [, $paymentId] = $this->seedOrderAndCard();
        $event = $this->donationEvent();

        $response = $this->push('mdev_x', [$event])->assertOk();

        // Charity acceptance is best-effort and post-commit. An ambiguous or
        // negative reply must not roll back the sale, local donation, payment
        // breadcrumb, or processed ACK; only the durable forward marker stays
        // NULL so the admin sweep can safely retry the same donation UUID.
        $response
            ->assertJsonPath('data.results.0.status', SyncEvent::STATUS_PROCESSED)
            ->assertJsonPath('data.results.0.result.status', 'success');

        $donation = RoundupDonation::query()
            ->where('client_event_id', $event['client_event_id'])
            ->firstOrFail();
        $payment = Payment::findOrFail($paymentId);

        $this->assertSame('success', $donation->status);
        $this->assertNull($donation->forwarded_at);
        $this->assertSame((int) $donation->id, (int) $payment->charity_transaction_id);
        $this->assertSame(
            SyncEvent::STATUS_PROCESSED,
            SyncEvent::query()->where('client_event_id', $event['client_event_id'])->value('ack_status'),
        );
        Http::assertSentCount(1);
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
            'organization_id' => 3, 'branch_name' => 'Main',
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

    public function test_payment_index_attaches_each_roundup_to_its_exact_card_leg(): void
    {
        $this->device();
        $this->seedBranch();
        [$orderId] = $this->seedOrderAndCard();
        // A 3-leg split: cash + two card guests, each rounding up their own
        // share. payment_index addresses legs by insertion order (0-based).
        DB::table('pos_payments')->where('order_id', $orderId)->delete();
        $legIds = [];
        foreach ([['cash', '1.000'], ['card', '2.000'], ['card', '1.800']] as [$method, $amount]) {
            $legIds[] = DB::table('pos_payments')->insertGetId([
                'uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'method' => $method,
                'amount' => $amount, 'status' => 'success', 'pending_reconciliation' => false,
                'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $res = $this->push('mdev_x', [
            $this->donationEvent(['payment_index' => 1, 'amount_baisas' => 300]),
            $this->donationEvent(['payment_index' => 2, 'amount_baisas' => 500]),
        ])->assertOk();

        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertSame('processed', $res->json('data.results.1.status'));
        $this->assertDatabaseCount('pos_roundup_donations', 2);
        // Each donation rides ITS OWN card leg, with its own breadcrumb.
        $this->assertDatabaseHas('pos_roundup_donations', ['payment_id' => $legIds[1], 'amount' => '0.300']);
        $this->assertDatabaseHas('pos_roundup_donations', ['payment_id' => $legIds[2], 'amount' => '0.500']);
        $this->assertSame('0.300', Payment::findOrFail($legIds[1])->roundup_amount);
        $this->assertSame('0.500', Payment::findOrFail($legIds[2])->roundup_amount);
    }

    public function test_payment_index_addressing_a_cash_leg_fails_loud(): void
    {
        $this->device();
        $this->seedBranch();
        [$orderId] = $this->seedOrderAndCard();
        DB::table('pos_payments')->where('order_id', $orderId)->delete();
        foreach ([['cash', '2.000'], ['card', '2.800']] as [$method, $amount]) {
            DB::table('pos_payments')->insert([
                'uuid' => (string) Str::uuid(), 'order_id' => $orderId, 'method' => $method,
                'amount' => $amount, 'status' => 'success', 'pending_reconciliation' => false,
                'captured_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Index 0 = the CASH leg — a round-up can only ride card money.
        $res = $this->push('mdev_x', [$this->donationEvent(['payment_index' => 0])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('does not address a card tender', $res->json('data.results.0.result.error'));
        $this->assertDatabaseCount('pos_roundup_donations', 0);
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

    public function test_donation_and_external_forward_roll_back_when_the_processed_stamp_fails(): void
    {
        config(['services.charity.url' => 'http://charity.test']);
        Http::fake(['*' => Http::response(['success' => true], 201)]);

        $this->device();
        $this->seedBranch();
        [, $paymentId] = $this->seedOrderAndCard();
        $event = $this->donationEvent();
        $clientEventId = $event['client_event_id'];

        SyncEvent::updating(function (SyncEvent $syncEvent) use ($clientEventId): void {
            if ($syncEvent->client_event_id === $clientEventId
                && $syncEvent->ack_status === SyncEvent::STATUS_PROCESSED) {
                throw new \RuntimeException('simulated donation ACK failure');
            }
        });

        $response = $this->push('mdev_x', [$event])->assertOk();

        $response
            ->assertJsonPath('data.results.0.status', SyncEvent::STATUS_FAILED)
            ->assertJsonPath('data.results.0.result.error', 'simulated donation ACK failure');
        $this->assertDatabaseCount('pos_roundup_donations', 0);
        $payment = Payment::findOrFail($paymentId);
        $this->assertNull($payment->roundup_amount);
        $this->assertNull($payment->charity_transaction_id);
        Http::assertNothingSent();
    }

    public function test_stranded_donation_reuses_its_committed_row_and_forwards_after_processing(): void
    {
        config(['services.charity.url' => null]);

        $device = $this->device();
        $device->forceFill(['assigned_at' => now()->subHour()])->save();
        $this->seedBranch();
        $this->seedOrderAndCard();
        $event = $this->donationEvent();

        $this->push('mdev_x', [$event])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', SyncEvent::STATUS_PROCESSED);

        $donation = RoundupDonation::firstOrFail();
        $syncEvent = SyncEvent::query()
            ->where('device_id', $device->id)
            ->where('client_event_id', $event['client_event_id'])
            ->firstOrFail();

        // Recreate the historical crash state: local effects committed but
        // the worker died before the processed event stamp committed.
        DB::table('pos_sync_events')->where('id', $syncEvent->id)->update([
            'ack_status' => SyncEvent::STATUS_RECEIVED,
            'processed_at' => null,
            'result_json' => null,
            'server_received_at' => now()->subMinutes(11),
        ]);

        // Reassignment and branch edits after the sale must not rewrite who
        // receives the donation or the origin shown in charity reporting.
        $device->forceFill([
            'bank_id' => 105,
            'terminal_id' => 'CURRENT-TID',
            'commission_profile_id' => 107,
            'organization_id' => 103,
        ])->save();
        DB::table('pos_branches')->where('id', 10)->update([
            'name' => 'Current Branch',
            'country_id' => 11,
            'region_id' => 12,
            'district_id' => 13,
            'city_id' => 14,
            'latitude' => 24.0000000,
            'longitude' => 59.0000000,
            'updated_at' => now(),
        ]);

        config(['services.charity.url' => 'http://charity.test']);
        $statusWhenForwarded = null;
        $transactionLevelWhenForwarded = null;
        $baselineTransactionLevel = DB::transactionLevel();
        Http::fake(function () use (&$statusWhenForwarded, &$transactionLevelWhenForwarded, $syncEvent) {
            $statusWhenForwarded = SyncEvent::findOrFail($syncEvent->id)->ack_status;
            $transactionLevelWhenForwarded = DB::transactionLevel();

            return Http::response(['success' => true], 201);
        });

        $this->artisan('sync:sweep-stranded-events', ['--older-than' => 10, '--limit' => 1])
            ->assertSuccessful();

        $syncEvent->refresh();
        $donation->refresh();
        $this->assertSame(SyncEvent::STATUS_PROCESSED, $syncEvent->ack_status);
        $this->assertSame((int) $donation->id, (int) $syncEvent->result_json['roundup_donation_id']);
        $this->assertSame(SyncEvent::STATUS_PROCESSED, $statusWhenForwarded);
        // The event stamp is already committed, but the bounded idempotent
        // forward holds a new order/donation serialization transaction.
        $this->assertSame($baselineTransactionLevel + 1, $transactionLevelWhenForwarded);
        $this->assertNotNull($donation->forwarded_at);
        $this->assertDatabaseCount('pos_roundup_donations', 1);
        Http::assertSentCount(1);
        Http::assertSent(
            fn ($request): bool => $request['pos_device_id'] === $device->id
                && $request['pos_branch_id'] === 10
                && $request['pos_branch_name'] === 'Main'
                && $request['commission_profile_id'] === 7
                && $request['organization_id'] === 3
                && $request['amount'] === '0.200'
                && $request['status'] === 'success'
                && $request['terminal_id'] === 'TID-9'
                && $request['bank_id'] === 5
                && (string) $request['pos_reference'] === (string) $donation->uuid
                && $request['country_id'] === 1
                && $request['region_id'] === 2
                && $request['district_id'] === 3
                && $request['city_id'] === 4
                && $request['latitude'] === '23.5880000'
                && $request['longitude'] === '58.4060000'
                && ($request['receipt']['status'] ?? null) === 'success',
        );

        // If an operator encounters the same ambiguous ledger state again,
        // the durable forwarded marker prevents even an idempotent HTTP retry.
        DB::table('pos_sync_events')->where('id', $syncEvent->id)->update([
            'ack_status' => SyncEvent::STATUS_RECEIVED,
            'processed_at' => null,
            'result_json' => null,
            'server_received_at' => now()->subMinutes(11),
        ]);
        Http::fake();

        $this->artisan('sync:sweep-stranded-events', ['--older-than' => 10, '--limit' => 1])
            ->assertSuccessful();

        $this->assertSame(SyncEvent::STATUS_PROCESSED, $syncEvent->fresh()->ack_status);
        $this->assertDatabaseCount('pos_roundup_donations', 1);
        Http::assertNothingSent();
    }

    public function test_voided_stranded_donation_settles_without_forwarding_again(): void
    {
        config(['services.charity.url' => null]);
        Http::fake();

        $device = $this->device();
        $device->forceFill(['assigned_at' => now()->subHour()])->save();
        $this->seedBranch();
        [, $paymentId] = $this->seedOrderAndCard();
        $event = $this->donationEvent();

        $this->push('mdev_x', [$event])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', SyncEvent::STATUS_PROCESSED);

        $donation = RoundupDonation::firstOrFail();
        $syncEvent = SyncEvent::query()
            ->where('device_id', $device->id)
            ->where('client_event_id', $event['client_event_id'])
            ->firstOrFail();

        DB::table('pos_sync_events')->where('id', $syncEvent->id)->update([
            'ack_status' => SyncEvent::STATUS_RECEIVED,
            'processed_at' => null,
            'result_json' => null,
            'server_received_at' => now()->subMinutes(11),
        ]);

        $voidEvent = [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => 'order-uuid-1',
                'reason' => 'customer cancellation',
            ],
        ];
        $this->push('mdev_x', [$voidEvent])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', SyncEvent::STATUS_PROCESSED);

        $donation->refresh();
        $payment = Payment::findOrFail($paymentId);
        $this->assertSame('void', $donation->status);
        $this->assertNull($payment->roundup_amount);
        $this->assertNull($payment->charity_transaction_id);

        $this->artisan('sync:sweep-stranded-events', ['--older-than' => 10, '--limit' => 1])
            ->assertSuccessful();

        $syncEvent->refresh();
        $this->assertSame(SyncEvent::STATUS_PROCESSED, $syncEvent->ack_status);
        $this->assertSame((int) $donation->id, (int) $syncEvent->result_json['roundup_donation_id']);
        $this->assertSame('void', $syncEvent->result_json['status']);
        $this->assertDatabaseCount('pos_roundup_donations', 1);
        Http::assertNothingSent();
    }

    public function test_stranded_donation_cannot_be_created_after_the_order_was_voided(): void
    {
        config(['services.charity.url' => 'http://charity.test']);
        Http::fake(['*' => Http::response(['success' => true], 201)]);

        $device = $this->device();
        $device->forceFill(['assigned_at' => now()->subHour()])->save();
        $this->seedBranch();
        [, $paymentId] = $this->seedOrderAndCard();
        $event = $this->donationEvent();

        // The event reached the durable ledger, then its worker died before
        // running the handler. A later order.void is now the terminal decision.
        $syncEvent = SyncEvent::create([
            'client_event_id' => $event['client_event_id'],
            'device_id' => $device->id,
            'event_type' => $event['event_type'],
            'payload_json' => $event['payload'],
            'client_timestamp' => now()->subMinutes(12),
            'server_received_at' => now()->subMinutes(11),
            'ack_status' => SyncEvent::STATUS_RECEIVED,
        ]);

        $this->push('mdev_x', [[
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => 'order-uuid-1',
                'reason' => 'customer cancellation',
            ],
        ]])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', SyncEvent::STATUS_PROCESSED);

        $this->artisan('sync:sweep-stranded-events', ['--older-than' => 10, '--limit' => 1])
            ->expectsOutput('processed=0 failed=1 skipped=0')
            ->assertSuccessful();

        $syncEvent->refresh();
        $payment = Payment::findOrFail($paymentId);
        $this->assertSame(SyncEvent::STATUS_FAILED, $syncEvent->ack_status);
        $this->assertStringContainsString('order already void', (string) $syncEvent->result_json['error']);
        $this->assertDatabaseCount('pos_roundup_donations', 0);
        $this->assertNull($payment->roundup_amount);
        $this->assertNull($payment->charity_transaction_id);
        Http::assertNothingSent();
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
        config([
            'services.charity.url' => 'http://charity.test',
            'services.charity.roundup_hmac_secret' => 'roundup-test-secret',
        ]);
        Http::fake(['*' => Http::response(['success' => true], 201)]);

        $device = $this->device();
        $this->seedBranch();
        $this->seedOrderAndCard();

        $res = $this->push('mdev_x', [$this->donationEvent()])->assertOk();
        $this->assertSame('processed', $res->json('data.results.0.status'));

        // Forwarded to the POS round-up endpoint, linking the POS device + branch
        // with the branch geo + the device's charity commission profile. The
        // donation's uuid rides as pos_reference — the charity-side dedupe key
        // that makes the hourly retry sweep safe to replay.
        $donationUuid = (string) RoundupDonation::firstOrFail()->uuid;
        Http::assertSent(function (Request $request) use ($device, $donationUuid) {
            $timestamp = $request->header('X-Pos-Timestamp')[0] ?? null;
            $signature = $request->header('X-Pos-Signature')[0] ?? null;
            $body = $request->body();

            return is_string($timestamp)
                && preg_match('/^\d+$/', $timestamp) === 1
                && is_string($signature)
                && hash_equals(
                    'v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'roundup-test-secret'),
                    $signature,
                )
                && $request->hasHeader('Content-Type', 'application/json')
                && str_contains($request->url(), '/api/donations-pos-roundup')
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
                && $request['pos_reference'] === $donationUuid
                && ($request['receipt']['status'] ?? null) === 'success';
        });

        // The POS round-up still records normally, stamped as forwarded so
        // the admin reconciliation paths never forward it twice (P-F7).
        $this->assertDatabaseCount('pos_roundup_donations', 1);
        $this->assertNotNull(RoundupDonation::firstOrFail()->forwarded_at);
    }

    public function test_a_2xx_charity_refusal_leaves_the_donation_retriable(): void
    {
        $this->assertUnacceptedCharityResponseLeavesDonationRetriable(
            Http::response(['success' => false, 'message' => 'receiver validation failed'], 200),
        );
    }

    public function test_a_2xx_charity_reply_missing_success_leaves_the_donation_retriable(): void
    {
        $this->assertUnacceptedCharityResponseLeavesDonationRetriable(
            Http::response(['message' => 'ambiguous acknowledgement'], 200),
        );
    }

    public function test_a_2xx_malformed_charity_reply_leaves_the_donation_retriable(): void
    {
        $this->assertUnacceptedCharityResponseLeavesDonationRetriable(
            Http::response('{not-json', 200, ['Content-Type' => 'application/json']),
        );
    }

    public function test_a_2xx_charity_reply_with_a_non_boolean_success_leaves_the_donation_retriable(): void
    {
        $this->assertUnacceptedCharityResponseLeavesDonationRetriable(
            Http::response(['success' => 'true'], 200),
        );
    }

    public function test_inline_forward_rechecks_void_after_its_unlocked_candidate_read(): void
    {
        config(['services.charity.url' => 'http://charity.test']);
        Http::fake(['*' => Http::response(['success' => true], 201)]);

        $device = $this->device();
        $this->seedBranch();
        [, $paymentId] = $this->seedOrderAndCard();
        $voidTriggered = false;

        // Deterministically interleave a real void immediately after the
        // post-commit candidate is hydrated but before the locked recheck.
        RoundupDonation::retrieved(function (RoundupDonation $_candidate) use (&$voidTriggered, $device): void {
            if ($voidTriggered) {
                return;
            }

            $voidTriggered = true;
            $voidEvent = new SyncEvent;
            $voidEvent->payload_json = [
                'order_uuid' => 'order-uuid-1',
                'reason' => 'void won before charity forward',
            ];

            app(VoidOrderHandler::class)->handle($voidEvent, $device);
        });

        $this->push('mdev_x', [$this->donationEvent()])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', SyncEvent::STATUS_PROCESSED);

        $donation = RoundupDonation::firstOrFail();
        $payment = Payment::findOrFail($paymentId);
        $this->assertTrue($voidTriggered);
        $this->assertSame('void', $donation->status);
        $this->assertNull($donation->forwarded_at);
        $this->assertNull($payment->roundup_amount);
        $this->assertNull($payment->charity_transaction_id);
        Http::assertNothingSent();
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

    public function test_pending_approval_at_the_local_transaction_boundary_is_observed(): void
    {
        config(['services.charity.url' => 'http://charity.test']);
        Http::fake(['*' => Http::response(['success' => true], 201)]);

        $this->device();
        $this->seedBranch();
        [, $paymentId] = $this->seedOrderAndCard(pending: true);
        $transactionBegins = 0;
        $approvalApplied = false;

        // The first begin is the dispatcher transaction. Apply approval at the
        // handler's nested transaction boundary: an unlocked pre-read remains
        // stale/pending, while the locked payment snapshot observes success.
        Event::listen(TransactionBeginning::class, function () use (
            &$transactionBegins,
            &$approvalApplied,
            $paymentId,
        ): void {
            $transactionBegins++;
            if ($transactionBegins !== 2) {
                return;
            }

            DB::table('pos_payments')->where('id', $paymentId)->update([
                'status' => Payment::STATUS_SUCCESS,
                'pending_reconciliation' => false,
                'reconciled_at' => now(),
                'updated_at' => now(),
            ]);
            $approvalApplied = true;
        });

        $response = $this->push('mdev_x', [$this->donationEvent()])->assertOk();

        $response
            ->assertJsonPath('data.results.0.status', SyncEvent::STATUS_PROCESSED)
            ->assertJsonPath('data.results.0.result.status', 'success');
        $donation = RoundupDonation::firstOrFail();
        $this->assertTrue($approvalApplied);
        $this->assertGreaterThanOrEqual(2, $transactionBegins);
        $this->assertSame('success', $donation->status);
        $this->assertNotNull($donation->forwarded_at);
        $this->assertFalse(Payment::findOrFail($paymentId)->pending_reconciliation);
        Http::assertSentCount(1);
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
