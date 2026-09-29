<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\QrSession;
use App\Models\RoundupDonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class QrRoundupSettlementTest extends TestCase
{
    use RefreshDatabase;

    private const CLAIM_URL = '/api/v1/device/qr/claim-charge';

    private const RELEASE_URL = '/api/v1/device/qr/release-charge';

    private const FALLBACK_URL = '/api/v1/device/qr/fallback-to-counter';

    private const SYNC_URL = '/api/v1/device/sync/push';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'qr.charge_claim_seconds' => 180,
            'qr.charge_sweep_grace_seconds' => 30,
            'qr.station_geofence_exempt' => true,
            'services.charity.url' => null,
        ]);

        Branch::query()->create([
            'id' => 10,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Round-up Branch',
            'country_id' => 1,
            'region_id' => 2,
            'district_id' => 3,
            'city_id' => 4,
            'latitude' => 23.5880000,
            'longitude' => 58.4060000,
            'geofence_radius_m' => 500,
            'status' => 'active',
        ]);
    }

    private function device(string $token, string $type = 'payment_station'): Device
    {
        return Device::factory()->paired($token)->create([
            'company_id' => 100,
            'branch_id' => 10,
            'device_type' => $type,
            'bank_id' => 5,
            'terminal_id' => 'TID-ROUNDUP',
            'commission_profile_id' => 7,
            'organization_id' => 3,
        ]);
    }

    private function order(Device $station): Order
    {
        $now = now();
        $session = QrSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $station->company_id,
            'branch_id' => $station->branch_id,
            'device_id' => $station->getKey(),
            'token' => hash('sha256', (string) Str::uuid()),
            'token_expires_at' => $now->copy()->addMinute(),
            'status' => QrSession::STATUS_ORDERED,
            'bound_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => $now->copy()->addHour(),
        ]);

        return Order::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => 10,
            'device_id' => $station->getKey(),
            'qr_session_id' => $session->getKey(),
            'client_request_id' => (string) Str::uuid(),
            'order_type' => 'quick',
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'source' => Order::SOURCE_QR_WEB,
            'subtotal' => '4.750',
            'discount_total' => '0.000',
            'comp_total' => '0.000',
            'tax_total' => '0.000',
            'grand_total' => '4.750',
            'opened_at' => $now,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postAs(Device $device, string $url, array $payload): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->withToken((string) $device->plainTextToken)->postJson($url, $payload);
    }

    private function claim(Device $station, Order $order): TestResponse
    {
        return $this->postAs($station, self::CLAIM_URL, [
            'order_uuid' => $order->uuid,
        ]);
    }

    private function release(Device $station, Order $order, string $outcome): TestResponse
    {
        return $this->postAs($station, self::RELEASE_URL, [
            'order_uuid' => $order->uuid,
            'outcome' => $outcome,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(Device $device, array $events): TestResponse
    {
        return $this->postAs($device, self::SYNC_URL, ['events' => $events]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payEvent(Order $order, ?string $clientEventId = null): array
    {
        return [
            'client_event_id' => $clientEventId ?? (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => $order->uuid,
                'paid_at' => now()->toIso8601String(),
                'payments' => [[
                    'method' => Payment::METHOD_CARD,
                    'amount_baisas' => 4750,
                    'status' => Payment::STATUS_SUCCESS,
                    'softpos_reference' => 'SP-'.Str::random(12),
                    'softpos_auth_code' => 'AUTH42',
                    'bank_response' => [
                        'status' => 'success',
                        'approvalCode' => 'AUTH42',
                    ],
                ]],
            ],
        ];
    }

    /**
     * @return array{0:Order,1:Payment,2:array<string,mixed>}
     */
    private function tillDonationFixture(Device $till): array
    {
        $order = Order::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => 10,
            'device_id' => $till->getKey(),
            'order_type' => 'quick',
            'status' => Order::STATUS_PAID,
            'source' => 'main_pos',
            'subtotal' => '4.750',
            'discount_total' => '0.000',
            'comp_total' => '0.000',
            'tax_total' => '0.000',
            'grand_total' => '4.750',
            'opened_at' => now(),
            'closed_at' => now(),
        ]);
        $payment = Payment::query()->create([
            'uuid' => (string) Str::uuid(),
            'order_id' => $order->getKey(),
            'method' => Payment::METHOD_CARD,
            'amount' => '4.750',
            'status' => Payment::STATUS_SUCCESS,
            'pending_reconciliation' => false,
            'device_id' => $till->getKey(),
            'terminal_id' => $till->terminal_id,
            'bank_id' => $till->bank_id,
            'bank_response' => ['status' => 'success', 'approvalCode' => 'AUTH42'],
            'captured_at' => now(),
        ]);
        $event = [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'donation.record',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => $order->uuid,
                'amount_baisas' => 250,
                'payment_index' => 0,
            ],
        ];

        return [$order, $payment, $event];
    }

    private function enableNumbering(): void
    {
        DB::table('pos_company_settings')->insert([
            'company_id' => 100,
            'key' => 'order_numbering',
            'value' => json_encode([
                'enabled' => true,
                'prefix' => 'QR-',
                'pad' => 4,
                'scope' => 'branch',
                'daily_reset' => false,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_claim_without_roundup_key_freezes_and_replays_sale_amount_only(): void
    {
        $station = $this->device('roundup-freeze');
        $order = $this->order($station);

        $this->claim($station, $order)
            ->assertOk()
            ->assertJsonPath('data.charge_amount_baisas', 4750)
            ->assertJsonPath('data.roundup_amount_baisas', 0)
            ->assertJsonPath('data.softpos_amount_baisas', 4750)
            ->assertJsonPath('data.already_claimed_by_this_device', false);

        $order->forceFill(['grand_total' => '9.999'])->save();

        $this->claim($station, $order)
            ->assertOk()
            ->assertJsonPath('data.charge_amount_baisas', 4750)
            ->assertJsonPath('data.roundup_amount_baisas', 0)
            ->assertJsonPath('data.softpos_amount_baisas', 4750)
            ->assertJsonPath('data.already_claimed_by_this_device', true);

        $frozen = $order->fresh();
        $this->assertSame(4750, $frozen->charge_amount_baisas);
        $this->assertSame(0, $frozen->charge_roundup_amount_baisas);
        $this->assertNoQrRoundupArtifacts($order);
    }

    #[DataProvider('presentRoundupValues')]
    public function test_claim_rejects_any_present_roundup_key(mixed $roundupBaisas): void
    {
        $station = $this->device('roundup-present-'.Str::random(8));
        $order = $this->order($station);

        $this->postAs($station, self::CLAIM_URL, [
            'order_uuid' => $order->uuid,
            'roundup_amount_baisas' => $roundupBaisas,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed');

        $unclaimed = $order->fresh();
        $this->assertNull($unclaimed->charge_device_id);
        $this->assertNull($unclaimed->charge_amount_baisas);
        $this->assertNull($unclaimed->charge_roundup_amount_baisas);
        $this->assertNoQrRoundupArtifacts($order);
    }

    /** @return iterable<string, array{mixed}> */
    public static function presentRoundupValues(): iterable
    {
        yield 'zero' => [0];
        yield 'positive' => [250];
        yield 'null' => [null];
    }

    public function test_successful_station_pay_records_no_roundup_and_till_donation_record_is_unchanged(): void
    {
        config(['services.charity.url' => 'http://charity.test']);
        Http::fake(['*' => Http::response(['success' => true], 201)]);

        $station = $this->device('roundup-success');
        $order = $this->order($station);
        $this->claim($station, $order)->assertOk();
        $payEvent = $this->payEvent($order);

        $this->push($station, [$payEvent])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.status', 'paid');

        $qrPayment = Payment::query()->where('order_id', $order->getKey())->sole();
        $this->assertSame('4.750', $qrPayment->amount);
        $this->assertNull($qrPayment->roundup_amount);
        $this->assertNull($qrPayment->charity_transaction_id);
        $this->assertSame(0, $order->fresh()->charge_roundup_amount_baisas);
        $this->assertNoQrRoundupArtifacts($order);
        Http::assertNothingSent();

        $this->push($station, [$payEvent])
            ->assertOk()
            ->assertJsonPath('data.summary.duplicates', 1);
        $this->assertSame(1, Payment::query()->where('order_id', $order->getKey())->count());
        $this->assertNoQrRoundupArtifacts($order);
        Http::assertNothingSent();

        $till = $this->device('roundup-till-parity', 'fixed_pos');
        [$tillOrder, $tillPayment, $donationEvent] = $this->tillDonationFixture($till);
        $this->assertNull($tillPayment->roundup_amount);
        $this->assertNull($tillPayment->charity_transaction_id);
        $this->assertSame(0, RoundupDonation::query()->where('order_id', $tillOrder->getKey())->count());

        $this->push($till, [$donationEvent])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed');

        $tillDonation = RoundupDonation::query()->where('order_id', $tillOrder->getKey())->sole();
        $tillPayment->refresh();
        $this->assertSame('0.250', $tillPayment->roundup_amount);
        $this->assertSame((int) $tillDonation->id, (int) $tillPayment->charity_transaction_id);
        $this->assertSame('0.250', $tillDonation->amount);
        $this->assertSame($donationEvent['client_event_id'], $tillDonation->client_event_id);
        $this->assertNotNull($tillDonation->forwarded_at);
        $this->assertSame((int) $till->getKey(), (int) $tillDonation->device_id);
        $this->assertSame(0, RoundupDonation::query()->where('order_id', $order->getKey())->count());
        $this->assertSame(1, RoundupDonation::query()->where('order_id', $tillOrder->getKey())->count());
        Http::assertSentCount(1);
    }

    public function test_declined_claim_without_success_evidence_records_no_roundup_or_payment(): void
    {
        $station = $this->device('roundup-declined');
        $order = $this->order($station);
        $this->claim($station, $order)->assertOk();
        $this->release($station, $order, Order::CHARGE_OUTCOME_DECLINED)->assertOk();

        $this->assertSame(0, $order->fresh()->charge_roundup_amount_baisas);
        $this->assertNoPaymentOrQrRoundup($order);
    }

    public function test_cancelled_release_without_success_evidence_records_no_roundup_or_payment(): void
    {
        $station = $this->device('roundup-cancelled');
        $order = $this->order($station);
        $this->claim($station, $order)->assertOk();
        $this->release($station, $order, Order::CHARGE_OUTCOME_CANCELLED)->assertOk();

        $this->assertSame(0, $order->fresh()->charge_roundup_amount_baisas);
        $this->assertNoPaymentOrQrRoundup($order);
    }

    public function test_lapsed_claim_without_success_evidence_records_no_roundup_or_payment(): void
    {
        $station = $this->device('roundup-expired');
        $order = $this->order($station);
        $this->claim($station, $order)->assertOk();
        $this->travel(211)->seconds();

        $this->artisan('qr:sweep-stale-charges')->assertSuccessful();
        $expired = $order->fresh();
        $this->assertSame(Order::CHARGE_OUTCOME_LAPSED, $expired->charge_outcome);
        $this->assertSame(0, $expired->charge_roundup_amount_baisas);
        $this->assertNoPaymentOrQrRoundup($order);
    }

    public function test_counter_fallback_retains_zero_roundup_provenance_without_recording_money(): void
    {
        $this->enableNumbering();
        $station = $this->device('roundup-fallback');
        $order = $this->order($station);
        $this->claim($station, $order)->assertOk();
        $claimed = $order->fresh();
        $this->travel(181)->seconds();

        $this->postAs($station, self::FALLBACK_URL, ['order_uuid' => $order->uuid])
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'device_not_attended');

        $till = $this->device('roundup-fallback-till', 'fixed_pos');
        $this->postAs($till, self::FALLBACK_URL, ['order_uuid' => $order->uuid])
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_HELD);

        $fallback = $order->fresh();
        $this->assertSame((int) $claimed->charge_device_id, (int) $fallback->charge_device_id);
        $this->assertSame($claimed->charge_amount_baisas, $fallback->charge_amount_baisas);
        $this->assertSame($claimed->charge_roundup_amount_baisas, $fallback->charge_roundup_amount_baisas);
        $this->assertTrue($claimed->charge_claimed_at->equalTo($fallback->charge_claimed_at));
        $this->assertTrue($claimed->charge_deadline_at->equalTo($fallback->charge_deadline_at));
        $this->assertSame($claimed->charge_outcome, $fallback->charge_outcome);
        $this->assertSame(0, $fallback->charge_roundup_amount_baisas);
        $this->assertNoPaymentOrQrRoundup($order);
    }

    public function test_accepted_late_and_replayed_evidence_records_one_payment_but_no_roundup(): void
    {
        config(['services.charity.url' => 'http://charity.test']);
        Http::fake(['*' => Http::response(['success' => true], 201)]);

        $station = $this->device('roundup-late-success');
        $order = $this->order($station);
        $this->claim($station, $order)->assertOk();
        $this->release($station, $order, Order::CHARGE_OUTCOME_CANCELLED)->assertOk();
        $payEvent = $this->payEvent($order);

        $this->push($station, [$payEvent])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.orphan_tender', true)
            ->assertJsonPath('data.results.0.result.duplicate_softpos_evidence', false);

        $payment = Payment::query()->where('order_id', $order->getKey())->sole();
        $this->assertSame(Payment::STATUS_PENDING_RECONCILIATION, $payment->status);
        $this->assertTrue((bool) $payment->pending_reconciliation);
        $this->assertSame('4.750', $payment->amount);
        $this->assertNull($payment->roundup_amount);
        $this->assertNull($payment->charity_transaction_id);
        $this->assertSame(0, $order->fresh()->charge_roundup_amount_baisas);
        $this->assertNoQrRoundupArtifacts($order);
        Http::assertNothingSent();

        $sameEvidence = $payEvent;
        $sameEvidence['client_event_id'] = (string) Str::uuid();
        $sameEvidenceResponse = $this->push($station, [$sameEvidence]);
        $sameEvidenceResponse->assertOk();
        $sameEvidenceResponse
            ->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath(
                'data.results.0.result.error',
                'cannot settle an uncertain charge outcome: '.$order->uuid,
            );

        $this->push($station, [$payEvent])
            ->assertOk()
            ->assertJsonPath('data.summary.duplicates', 1);

        $this->assertSame(1, Payment::query()->where('order_id', $order->getKey())->count());
        $this->assertNoQrRoundupArtifacts($order);
        Http::assertNothingSent();
    }

    private function assertNoPaymentOrQrRoundup(Order $order): void
    {
        $this->assertSame(0, Payment::query()->where('order_id', $order->getKey())->count());
        $this->assertNoQrRoundupArtifacts($order);
    }

    private function assertNoQrRoundupArtifacts(Order $order): void
    {
        $this->assertSame(0, RoundupDonation::query()->where('order_id', $order->getKey())->count());

        Payment::query()
            ->where('order_id', $order->getKey())
            ->each(function (Payment $payment): void {
                $this->assertNull($payment->roundup_amount);
                $this->assertNull($payment->charity_transaction_id);
            });
    }
}
