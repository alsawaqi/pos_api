<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\QrSession;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class QrChargeProtocolTest extends TestCase
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
            'qr.charge_sweep_enabled' => false,
            'qr.station_geofence_exempt' => false,
        ]);

        Branch::query()->create([
            'id' => 10,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'QR Branch',
            'latitude' => null,
            'longitude' => null,
            'geofence_radius_m' => 500,
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function device(
        string $token,
        string $type = 'payment_station',
        array $attributes = [],
    ): Device {
        return Device::factory()->paired($token)->create($attributes + [
            'company_id' => 100,
            'branch_id' => 10,
            'device_type' => $type,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function qrSession(Device $device, array $attributes = []): QrSession
    {
        $now = now();

        return QrSession::query()->create($attributes + [
            'uuid' => (string) Str::uuid(),
            'company_id' => $device->company_id,
            'branch_id' => $device->branch_id,
            'device_id' => $device->getKey(),
            'token' => hash('sha256', (string) Str::uuid()),
            'token_expires_at' => $now->copy()->addMinute(),
            'status' => QrSession::STATUS_ORDERED,
            'bound_at' => $now,
            'last_seen_at' => $now,
            'expires_at' => $now->copy()->addHour(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(?QrSession $session = null, array $attributes = []): Order
    {
        return Order::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $session?->company_id ?? 100,
            'branch_id' => $session?->branch_id ?? 10,
            'device_id' => $session?->device_id,
            'qr_session_id' => $session?->getKey(),
            'client_request_id' => $session !== null ? (string) Str::uuid() : null,
            'staff_id' => null,
            'customer_id' => null,
            'table_id' => null,
            'order_type' => 'quick',
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'source' => $session !== null ? Order::SOURCE_QR_WEB : 'main_pos',
            'temp_reference' => 'T-'.now()->format('md').'-001',
            'subtotal' => '4.750',
            'discount_total' => '0.000',
            'comp_total' => '0.000',
            'tax_total' => '0.000',
            'grand_total' => '4.750',
            'opened_at' => now(),
        ], $attributes));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postAs(Device $device, string $url, array $payload): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->withToken((string) $device->device_token)->postJson($url, $payload);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function claim(Device $device, Order $order, array $extra = []): TestResponse
    {
        return $this->postAs($device, self::CLAIM_URL, [
            'order_uuid' => $order->uuid,
        ] + $extra);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function release(
        Device $device,
        Order $order,
        string $outcome,
        array $extra = [],
    ): TestResponse {
        return $this->postAs($device, self::RELEASE_URL, [
            'order_uuid' => $order->uuid,
            'outcome' => $outcome,
        ] + $extra);
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function payEvent(
        Order $order,
        array $payments,
        array $payload = [],
        ?string $clientEventId = null,
    ): array {
        return [
            'client_event_id' => $clientEventId ?? (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => $order->uuid,
                'paid_at' => now()->toIso8601String(),
                'payments' => $payments,
            ] + $payload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function voidEvent(Order $order): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => $order->uuid,
                'voided_at' => now()->toIso8601String(),
                'reason' => 'P3 protocol test',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(Device $device, array $events): TestResponse
    {
        return $this->postAs($device, self::SYNC_URL, ['events' => $events]);
    }

    private function assertSyncStatus(TestResponse $response, string $status): void
    {
        $response->assertOk();
        $this->assertSame(
            $status,
            $response->json('data.results.0.status'),
            (string) json_encode($response->json('data.results.0')),
        );
    }

    private function successfulCard(int $amount = 4750): array
    {
        return [
            'method' => Payment::METHOD_CARD,
            'amount_baisas' => $amount,
            'status' => Payment::STATUS_SUCCESS,
            'softpos_reference' => 'SP-'.Str::random(12),
            'softpos_auth_code' => 'AUTH'.Str::random(6),
        ];
    }

    private function seedDeviceOrderProduct(): void
    {
        DB::table('pos_products')->insert([
            'id' => 9001,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'P3 overwrite probe',
            'base_price' => '3.000',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function deviceOrderEvent(
        Order $order,
        string $eventType,
        ?int $targetDeviceId = null,
    ): array {
        $payload = [
            'order' => [
                'uuid' => $order->uuid,
                'order_type' => 'quick',
                'source' => 'main_pos',
                'opened_at' => now()->toIso8601String(),
                'subtotal_baisas' => 3000,
                'discount_total_baisas' => 0,
                'tax_total_baisas' => 0,
                'grand_total_baisas' => 3000,
                'lines' => [[
                    'product_id' => 9001,
                    'qty' => 1,
                    'unit_price_baisas' => 3000,
                    'line_total_baisas' => 3000,
                ]],
            ],
        ];
        if ($eventType === 'order.transfer') {
            $payload['target_device_id'] = $targetDeviceId;
        }

        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => $eventType,
            'client_timestamp' => now()->toIso8601String(),
            'payload' => $payload,
        ];
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

    public function test_happy_claim_freezes_charge_provenance_and_returns_sale_zero_sale_amounts(): void
    {
        Log::spy();
        $now = Carbon::parse('2026-08-27 12:00:00');
        $this->travelTo($now);
        $station = $this->device('station-happy');
        $order = $this->order($this->qrSession($station));

        $this->claim($station, $order)
            ->assertOk()
            ->assertJsonPath('data.order_uuid', $order->uuid)
            ->assertJsonPath('data.charge_amount_baisas', 4750)
            ->assertJsonPath('data.roundup_amount_baisas', 0)
            ->assertJsonPath('data.softpos_amount_baisas', 4750)
            ->assertJsonPath('data.already_claimed_by_this_device', false)
            ->assertJsonPath('meta.money_unit', 'baisas');

        $claimed = $order->fresh();
        $this->assertSame((int) $station->id, (int) $claimed->charge_device_id);
        $this->assertSame(4750, $claimed->charge_amount_baisas);
        $this->assertSame(0, $claimed->charge_roundup_amount_baisas);
        $this->assertTrue($now->equalTo($claimed->charge_claimed_at));
        $this->assertTrue($now->copy()->addSeconds(180)->equalTo($claimed->charge_deadline_at));
        $this->assertNull($claimed->charge_outcome);
        Log::shouldHaveReceived('info')->withArgs(
            static fn (string $message, array $context): bool => $message === 'qr-charge claim'
                && $context['order_uuid'] === $order->uuid
                && $context['device_id'] === (int) $station->id
                && $context['charge_amount_baisas'] === 4750
                && $context['outcome'] === null
                && $context['already_claimed_by_this_device'] === false,
        )->once();
    }

    public function test_claimed_and_never_claimed_awaiting_payment_orders_refuse_every_device_upsert(): void
    {
        $this->seedDeviceOrderProduct();
        $till = $this->device('overwrite-source-till', 'fixed_pos');
        $target = $this->device('overwrite-target', 'handheld');
        $probe = 0;

        foreach ([false, true] as $claimed) {
            foreach (['order.hold', 'order.create', 'order.transfer'] as $eventType) {
                $probe++;
                $station = $this->device('overwrite-station-'.$probe);
                $order = $this->order($this->qrSession($station));
                if ($claimed) {
                    $this->claim($station, $order)->assertOk();
                }
                $before = $order->fresh()->getAttributes();

                $response = $this->push($till, [
                    $this->deviceOrderEvent($order, $eventType, (int) $target->id),
                ]);

                $this->assertSyncStatus($response, 'failed');
                $this->assertStringContainsString(
                    'status awaiting_payment',
                    $response->json('data.results.0.result.error'),
                );
                $this->assertSame($before, $order->fresh()->getAttributes());
                $this->assertDatabaseMissing('pos_order_items', ['order_id' => $order->id]);
            }
        }
    }

    /**
     * t0 customer chooses machine; t1 station claims; t2 fallback clears it;
     * t3 cashier starts cash; t4 queued station retry arrives; t5 would double
     * charge without the positive awaiting_payment admission clause.
     */
    public function test_post_fallback_held_order_cannot_be_reclaimed_even_with_null_charge_columns(): void
    {
        $station = $this->device('station-held-refusal');
        $order = $this->order($this->qrSession($station), [
            'status' => Order::STATUS_HELD,
            'charge_device_id' => null,
            'charge_amount_baisas' => null,
            'charge_claimed_at' => null,
            'charge_deadline_at' => null,
            'charge_outcome' => null,
        ]);

        $this->claim($station, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'order_not_awaiting_payment');

        $unchanged = $order->fresh();
        $this->assertNull($unchanged->charge_device_id);
        $this->assertNull($unchanged->charge_amount_baisas);
        $this->assertNull($unchanged->charge_claimed_at);
        $this->assertNull($unchanged->charge_deadline_at);
        $this->assertNull($unchanged->charge_outcome);
    }

    public function test_paid_order_is_refused_before_any_charge_is_written(): void
    {
        $station = $this->device('station-paid-refusal');
        $order = $this->order($this->qrSession($station), [
            'status' => Order::STATUS_PAID,
            'closed_at' => now(),
        ]);

        $this->claim($station, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'order_not_awaiting_payment');

        $this->assertNull($order->fresh()->charge_claimed_at);
    }

    public function test_different_station_cannot_replace_a_live_claim(): void
    {
        $first = $this->device('station-first');
        $second = $this->device('station-second');
        $order = $this->order($this->qrSession($first));
        $this->claim($first, $order)->assertOk();
        $original = $order->fresh();

        $this->claim($second, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_already_claimed');

        $unchanged = $order->fresh();
        $this->assertSame((int) $original->charge_device_id, (int) $unchanged->charge_device_id);
        $this->assertSame($original->charge_amount_baisas, $unchanged->charge_amount_baisas);
        $this->assertTrue($original->charge_deadline_at->equalTo($unchanged->charge_deadline_at));
    }

    public function test_declined_claim_is_dead_and_can_be_reclaimed(): void
    {
        $station = $this->device('station-declined-reclaim');
        $order = $this->order($this->qrSession($station), [
            'charge_device_id' => $station->id,
            'charge_amount_baisas' => 4750,
            'charge_claimed_at' => now()->subMinute(),
            'charge_deadline_at' => now()->addMinute(),
            'charge_outcome' => Order::CHARGE_OUTCOME_DECLINED,
        ]);

        $this->claim($station, $order)
            ->assertOk()
            ->assertJsonPath('data.already_claimed_by_this_device', false);

        $this->assertNull($order->fresh()->charge_outcome);
    }

    public function test_uncertain_claim_stays_live_regardless_of_deadline(): void
    {
        $station = $this->device('station-uncertain-reclaim');
        $order = $this->order($this->qrSession($station), [
            'charge_device_id' => $station->id,
            'charge_amount_baisas' => 4750,
            'charge_claimed_at' => now()->subDay(),
            'charge_deadline_at' => now()->subDay(),
            'charge_outcome' => Order::CHARGE_OUTCOME_UNCERTAIN,
        ]);
        $this->travel(30)->days();

        $this->claim($station, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_already_claimed');

        $this->assertSame(Order::CHARGE_OUTCOME_UNCERTAIN, $order->fresh()->charge_outcome);
    }

    public function test_claim_requires_persisted_payment_station_type_and_bound_ordered_session(): void
    {
        $till = $this->device('ordinary-device', 'fixed_pos');
        $tillOrder = $this->order($this->qrSession($till));

        $this->claim($till, $tillOrder)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'device_not_payment_station');

        $boundStation = $this->device('bound-station');
        $otherStation = $this->device('other-station');
        $order = $this->order($this->qrSession($boundStation));

        $this->claim($otherStation, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'order_not_bound_to_device_session');

        $notOrdered = $this->order($this->qrSession($boundStation, [
            'status' => QrSession::STATUS_ACTIVE,
        ]));
        $this->claim($boundStation, $notOrdered)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'session_not_ordered');
    }

    public function test_same_station_reclaim_is_idempotent_without_extending_or_repricing(): void
    {
        $station = $this->device('station-idempotent');
        $order = $this->order($this->qrSession($station));
        $first = $this->claim($station, $order)->assertOk();
        $deadline = $first->json('data.charge_deadline_at');
        $amount = $first->json('data.charge_amount_baisas');

        $this->travel(60)->seconds();
        $order->update(['grand_total' => '9.999']);

        $second = $this->claim($station, $order)->assertOk();
        $this->assertTrue($second->json('data.already_claimed_by_this_device'));
        $this->assertSame($deadline, $second->json('data.charge_deadline_at'));
        $this->assertSame($amount, $second->json('data.charge_amount_baisas'));
        $this->assertSame(4750, $order->fresh()->charge_amount_baisas);
    }

    public function test_idempotent_reclaim_still_requires_an_ordered_bound_station_session(): void
    {
        $station = $this->device('station-idempotent-session');
        $session = $this->qrSession($station);
        $order = $this->order($session);
        $this->claim($station, $order)->assertOk();
        $before = $order->fresh()->getAttributes();
        $session->update(['status' => QrSession::STATUS_CLOSED]);

        $this->claim($station, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'session_not_ordered');
        $this->assertSame($before, $order->fresh()->getAttributes());
    }

    public function test_claim_rejects_roundup_key_when_value_is_zero_or_null(): void
    {
        foreach ([0, null] as $index => $roundupAmount) {
            $station = $this->device('station-roundup-absent-only-'.$index);
            $order = $this->order($this->qrSession($station));

            $this->claim($station, $order, ['roundup_amount_baisas' => $roundupAmount])
                ->assertUnprocessable()
                ->assertJsonPath('errors.0.code', 'validation_failed');

            $unchanged = $order->fresh();
            $this->assertNull($unchanged->charge_device_id);
            $this->assertNull($unchanged->charge_amount_baisas);
            $this->assertNull($unchanged->charge_roundup_amount_baisas);
            $this->assertNull($unchanged->charge_claimed_at);
        }
    }

    public function test_claim_rejects_positive_roundup_key_regardless_of_numeric_representation(): void
    {
        foreach ([
            ['value' => 250, 'options' => 0],
            ['value' => '250', 'options' => 0],
            ['value' => 250.0, 'options' => JSON_PRESERVE_ZERO_FRACTION],
        ] as $index => $case) {
            $station = $this->device('station-roundup-representation-'.$index);
            $order = $this->order($this->qrSession($station));
            app('auth')->forgetGuards();

            $response = $this->withToken((string) $station->device_token)->json(
                'POST',
                self::CLAIM_URL,
                [
                    'order_uuid' => $order->uuid,
                    'roundup_amount_baisas' => $case['value'],
                ],
                [],
                $case['options'],
            );

            $response->assertUnprocessable()
                ->assertJsonPath('errors.0.code', 'validation_failed');

            $unchanged = $order->fresh();
            $this->assertNull($unchanged->charge_device_id);
            $this->assertNull($unchanged->charge_amount_baisas);
            $this->assertNull($unchanged->charge_roundup_amount_baisas);
            $this->assertNull($unchanged->charge_claimed_at);
        }
    }

    public function test_declined_release_preserves_audit_fields_and_is_reclaimable(): void
    {
        Log::spy();
        $station = $this->device('station-release-declined');
        $order = $this->order($this->qrSession($station));
        $this->claim($station, $order)->assertOk();
        $before = $order->fresh();

        $this->release($station, $order, Order::CHARGE_OUTCOME_DECLINED)
            ->assertOk()
            ->assertJsonPath('data.charge_outcome', Order::CHARGE_OUTCOME_DECLINED);

        $released = $order->fresh();
        $this->assertSame(Order::CHARGE_OUTCOME_DECLINED, $released->charge_outcome);
        $this->assertSame((int) $before->charge_device_id, (int) $released->charge_device_id);
        $this->assertSame($before->charge_amount_baisas, $released->charge_amount_baisas);
        $this->assertTrue($before->charge_claimed_at->equalTo($released->charge_claimed_at));
        $this->assertTrue($before->charge_deadline_at->equalTo($released->charge_deadline_at));
        Log::shouldHaveReceived('info')->withArgs(
            static fn (string $message, array $context): bool => $message === 'qr-charge release'
                && $context['order_uuid'] === $order->uuid
                && $context['device_id'] === (int) $station->id
                && $context['charge_amount_baisas'] === 4750
                && $context['outcome'] === Order::CHARGE_OUTCOME_DECLINED,
        )->once();

        $releasedAttributes = $released->getAttributes();
        $overwrite = $this->release($station, $order, Order::CHARGE_OUTCOME_CANCELLED)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_already_claimed');
        $this->assertStringContainsString(
            Order::CHARGE_OUTCOME_DECLINED,
            $overwrite->json('errors.0.message'),
        );
        $this->assertSame($releasedAttributes, $order->fresh()->getAttributes());

        $this->claim($station, $order)
            ->assertOk()
            ->assertJsonPath('data.already_claimed_by_this_device', false);
        $this->assertNull($order->fresh()->charge_outcome);
        $this->release($station, $order, Order::CHARGE_OUTCOME_CANCELLED)
            ->assertOk()
            ->assertJsonPath('data.charge_outcome', Order::CHARGE_OUTCOME_CANCELLED);
    }

    public function test_cancelled_release_preserves_audit_fields_and_is_reclaimable(): void
    {
        $station = $this->device('station-release-cancelled');
        $order = $this->order($this->qrSession($station));
        $this->claim($station, $order)->assertOk();
        $before = $order->fresh();

        $this->release($station, $order, Order::CHARGE_OUTCOME_CANCELLED)
            ->assertOk()
            ->assertJsonPath('data.charge_outcome', Order::CHARGE_OUTCOME_CANCELLED);

        $released = $order->fresh();
        $this->assertSame(Order::CHARGE_OUTCOME_CANCELLED, $released->charge_outcome);
        $this->assertSame((int) $before->charge_device_id, (int) $released->charge_device_id);
        $this->assertSame($before->charge_amount_baisas, $released->charge_amount_baisas);
        $this->assertTrue($before->charge_claimed_at->equalTo($released->charge_claimed_at));
        $this->assertTrue($before->charge_deadline_at->equalTo($released->charge_deadline_at));

        $releasedAttributes = $released->getAttributes();
        $overwrite = $this->release($station, $order, Order::CHARGE_OUTCOME_UNCERTAIN)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_already_claimed');
        $this->assertStringContainsString(
            Order::CHARGE_OUTCOME_CANCELLED,
            $overwrite->json('errors.0.message'),
        );
        $this->assertSame($releasedAttributes, $order->fresh()->getAttributes());

        $this->claim($station, $order)
            ->assertOk()
            ->assertJsonPath('data.already_claimed_by_this_device', false);
    }

    public function test_uncertain_release_freezes_claim_settlement_and_void_and_logs_evidence(): void
    {
        Log::spy();
        $station = $this->device('station-release-uncertain');
        $order = $this->order($this->qrSession($station));
        $this->claim($station, $order)->assertOk();

        $this->release($station, $order, Order::CHARGE_OUTCOME_UNCERTAIN, [
            'softpos_reference' => 'UNCERTAIN-REF',
            'softpos_auth_code' => 'UNC-AUTH',
            'bank_response' => ['result' => 'TIMEOUT'],
        ])->assertOk();

        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message, array $context): bool => $message === 'qr-charge release'
                && $context['order_uuid'] === $order->uuid
                && $context['device_id'] === (int) $station->id
                && $context['charge_amount_baisas'] === 4750
                && $context['outcome'] === Order::CHARGE_OUTCOME_UNCERTAIN
                && $context['softpos_reference'] === 'UNCERTAIN-REF'
                && $context['softpos_auth_code'] === 'UNC-AUTH',
        )->once();

        $repeat = $this->release($station, $order, Order::CHARGE_OUTCOME_UNCERTAIN)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_outcome_uncertain');
        $this->assertStringContainsString(
            Order::CHARGE_OUTCOME_UNCERTAIN,
            $repeat->json('errors.0.message'),
        );

        $this->claim($station, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_already_claimed');

        $pay = $this->push($station, [
            $this->payEvent($order, [$this->successfulCard()]),
        ]);
        $this->assertSyncStatus($pay, 'failed');
        $this->assertStringContainsString(
            'uncertain charge outcome',
            $pay->json('data.results.0.result.error'),
        );

        $void = $this->push($station, [$this->voidEvent($order)]);
        $this->assertSyncStatus($void, 'failed');
        $this->assertStringContainsString(
            'ambiguous QR charge requires fallback-to-counter before void',
            $void->json('data.results.0.result.error'),
        );
        $this->assertSame(Order::CHARGE_OUTCOME_UNCERTAIN, $order->fresh()->charge_outcome);
    }

    public function test_approved_outcome_cannot_be_overwritten_and_names_the_current_outcome(): void
    {
        $station = $this->device('release-approved-existing');
        $order = $this->order($this->qrSession($station), [
            'charge_device_id' => $station->id,
            'charge_amount_baisas' => 4750,
            'charge_claimed_at' => now()->subMinute(),
            'charge_deadline_at' => now()->addMinute(),
            'charge_outcome' => Order::CHARGE_OUTCOME_APPROVED,
        ]);
        $before = $order->fresh()->getAttributes();

        $response = $this->release($station, $order, Order::CHARGE_OUTCOME_DECLINED)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_already_claimed');
        $this->assertStringContainsString(
            Order::CHARGE_OUTCOME_APPROVED,
            $response->json('errors.0.message'),
        );
        $this->assertSame($before, $order->fresh()->getAttributes());
    }

    public function test_unresolved_claim_can_be_released_after_its_deadline(): void
    {
        $station = $this->device('release-after-deadline');
        $order = $this->order($this->qrSession($station));
        $this->claim($station, $order)->assertOk();
        $claimed = $order->fresh();
        $this->travelTo($claimed->charge_deadline_at->copy()->addSecond());

        $this->release($station, $order, Order::CHARGE_OUTCOME_DECLINED)
            ->assertOk()
            ->assertJsonPath('data.charge_outcome', Order::CHARGE_OUTCOME_DECLINED);

        $released = $order->fresh();
        $this->assertSame(Order::CHARGE_OUTCOME_DECLINED, $released->charge_outcome);
        $this->assertTrue($claimed->charge_claimed_at->equalTo($released->charge_claimed_at));
        $this->assertTrue($claimed->charge_deadline_at->equalTo($released->charge_deadline_at));
    }

    public function test_only_claim_holder_can_release_and_refusal_writes_nothing(): void
    {
        $holder = $this->device('release-holder');
        $other = $this->device('release-other');
        $order = $this->order($this->qrSession($holder));
        $this->claim($holder, $order)->assertOk();
        $before = $order->fresh()->getAttributes();

        $this->release($other, $order, Order::CHARGE_OUTCOME_DECLINED)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_not_claimed_by_device');

        $this->assertSame($before, $order->fresh()->getAttributes());
    }

    public function test_release_rejects_approved_as_an_invalid_request_outcome(): void
    {
        $station = $this->device('release-approved');
        $order = $this->order($this->qrSession($station));
        $this->claim($station, $order)->assertOk();

        $this->release($station, $order, Order::CHARGE_OUTCOME_APPROVED)
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed');

        $this->assertNull($order->fresh()->charge_outcome);
    }

    public function test_station_settles_its_live_claim_and_closes_the_qr_session(): void
    {
        $station = $this->device('station-settle', attributes: [
            'terminal_id' => 'TERM-STATION',
            'bank_id' => 42,
        ]);
        $session = $this->qrSession($station);
        $order = $this->order($session);
        $this->claim($station, $order)->assertOk();

        $response = $this->push($station, [
            $this->payEvent($order, [$this->successfulCard()]),
        ]);
        $this->assertSyncStatus($response, 'processed');
        $this->assertSame('paid', $response->json('data.results.0.result.status'));
        $this->assertArrayNotHasKey(
            'orphan_tender',
            (array) $response->json('data.results.0.result'),
        );

        $paid = $order->fresh();
        $this->assertSame(Order::STATUS_PAID, $paid->status);
        $this->assertSame(Order::CHARGE_OUTCOME_APPROVED, $paid->charge_outcome);
        $this->assertNotNull($paid->closed_at);
        $this->assertDatabaseHas('pos_payments', [
            'order_id' => $order->id,
            'method' => Payment::METHOD_CARD,
            'status' => Payment::STATUS_SUCCESS,
            'device_id' => $station->id,
        ]);
        $this->assertDatabaseHas('pos_qr_sessions', [
            'id' => $session->id,
            'status' => QrSession::STATUS_CLOSED,
        ]);
        $payment = Payment::query()->where('order_id', $order->id)->sole();
        $this->assertSame(Payment::STATUS_SUCCESS, $payment->status);
        $this->assertFalse((bool) $payment->pending_reconciliation);
    }

    public function test_claimed_path_rejects_frozen_amount_minus_or_plus_one_baisa(): void
    {
        $station = $this->device('station-exact-total');

        foreach ([4749, 4751] as $amount) {
            $order = $this->order($this->qrSession($station));
            $this->claim($station, $order)->assertOk();

            $response = $this->push($station, [
                $this->payEvent($order, [$this->successfulCard($amount)]),
            ]);
            $this->assertSyncStatus($response, 'failed');
            $this->assertStringContainsString(
                'charge_amount_baisas 4750',
                $response->json('data.results.0.result.error'),
            );
            $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
            $this->assertDatabaseMissing('pos_payments', ['order_id' => $order->id]);
        }
    }

    public function test_claimed_path_requires_a_real_integer_tender_amount(): void
    {
        $station = $this->device('station-integer-total');
        $order = $this->order($this->qrSession($station));
        $this->claim($station, $order)->assertOk();
        $tender = $this->successfulCard();
        $tender['amount_baisas'] = '4750junk';

        $response = $this->push($station, [
            $this->payEvent($order, [$tender]),
        ]);
        $this->assertSyncStatus($response, 'failed');
        $this->assertStringContainsString(
            'requires an integer tender amount',
            $response->json('data.results.0.result.error'),
        );
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
        $this->assertDatabaseMissing('pos_payments', ['order_id' => $order->id]);
    }

    public function test_legacy_non_qr_till_keeps_the_one_baisa_tolerance(): void
    {
        $till = $this->device('legacy-till', 'fixed_pos');
        $order = $this->order(null, [
            'device_id' => $till->id,
            'status' => Order::STATUS_OPEN,
        ]);

        $response = $this->push($till, [
            $this->payEvent($order, [[
                'method' => Payment::METHOD_CASH,
                'amount_baisas' => 4749,
                'status' => Payment::STATUS_SUCCESS,
            ]]),
        ]);
        $this->assertSyncStatus($response, 'processed');
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_different_device_in_same_branch_cannot_settle_station_claim(): void
    {
        $station = $this->device('station-owner');
        $till = $this->device('same-branch-till', 'fixed_pos');
        $order = $this->order($this->qrSession($station));
        $this->claim($station, $order)->assertOk();

        $response = $this->push($till, [
            $this->payEvent($order, [$this->successfulCard()]),
        ]);
        $this->assertSyncStatus($response, 'failed');
        $this->assertStringContainsString(
            'held by another device',
            $response->json('data.results.0.result.error'),
        );
        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
    }

    public function test_persisted_payment_station_type_refuses_cash_but_accepts_card(): void
    {
        $station = $this->device('station-card-only');
        $order = $this->order($this->qrSession($station));
        $this->claim($station, $order)->assertOk();
        $baseTender = [
            'amount_baisas' => 4750,
            'status' => Payment::STATUS_SUCCESS,
            'softpos_reference' => 'CARD-ONLY-REF',
            'softpos_auth_code' => 'CARD-ONLY-AUTH',
        ];

        $cash = $this->push($station, [
            $this->payEvent($order, [['method' => Payment::METHOD_CASH] + $baseTender]),
        ]);
        $this->assertSyncStatus($cash, 'failed');
        $this->assertStringContainsString(
            'card tenders only',
            $cash->json('data.results.0.result.error'),
        );
        $this->assertSame('payment_station', $station->fresh()->device_type);

        $card = $this->push($station, [
            $this->payEvent($order, [['method' => Payment::METHOD_CARD] + $baseTender]),
        ]);
        $this->assertSyncStatus($card, 'processed');
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_station_without_a_live_claim_or_softpos_evidence_is_refused(): void
    {
        $station = $this->device('station-no-claim');
        $order = $this->order($this->qrSession($station));
        $tender = $this->successfulCard();
        unset($tender['softpos_reference'], $tender['softpos_auth_code']);

        $response = $this->push($station, [
            $this->payEvent($order, [$tender]),
        ]);
        $this->assertSyncStatus($response, 'failed');
        $this->assertStringContainsString(
            'must hold a live charge claim',
            $response->json('data.results.0.result.error'),
        );
        $this->assertDatabaseMissing('pos_payments', ['order_id' => $order->id]);
    }

    public function test_claimed_path_refuses_every_non_success_tender_status(): void
    {
        foreach ([Payment::STATUS_FAILED, Payment::STATUS_PENDING_RECONCILIATION] as $status) {
            $station = $this->device('station-'.$status);
            $order = $this->order($this->qrSession($station));
            $this->claim($station, $order)->assertOk();
            $tender = $this->successfulCard();
            $tender['status'] = $status;

            $response = $this->push($station, [
                $this->payEvent($order, [$tender]),
            ]);
            $this->assertSyncStatus($response, 'failed');
            $this->assertStringContainsString(
                'requires a successful tender',
                $response->json('data.results.0.result.error'),
            );
            $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
            $this->assertDatabaseMissing('pos_payments', ['order_id' => $order->id]);
        }
    }

    public function test_station_geofence_is_fail_closed_at_claim_and_not_repeated_after_tap(): void
    {
        Branch::query()->whereKey(10)->update([
            'latitude' => '23.5800000',
            'longitude' => '58.3800000',
            'geofence_radius_m' => 100,
        ]);
        $station = $this->device('station-geofence');
        $order = $this->order($this->qrSession($station));

        $this->claim($station, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'geofence_fix_required');

        $this->claim($station, $order, [
            'gps' => ['lat' => 0, 'lng' => 0],
        ])->assertConflict()
            ->assertJsonPath('errors.0.code', 'geofence_outside');

        config(['qr.station_geofence_exempt' => true]);
        $this->claim($station, $order)->assertOk();

        $response = $this->push($station, [
            $this->payEvent($order, [$this->successfulCard()]),
        ]);
        $this->assertSyncStatus($response, 'processed');
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_counter_path_of_held_qr_order_still_requires_and_enforces_geofence(): void
    {
        Branch::query()->whereKey(10)->update([
            'latitude' => '23.5800000',
            'longitude' => '58.3800000',
            'geofence_radius_m' => 100,
        ]);
        $station = $this->device('station-counter-fence');
        $till = $this->device('till-counter-fence', 'fixed_pos');
        $order = $this->order($this->qrSession($station), [
            'status' => Order::STATUS_HELD,
        ]);
        $tender = [[
            'method' => Payment::METHOD_CASH,
            'amount_baisas' => 4750,
            'status' => Payment::STATUS_SUCCESS,
        ]];

        $missing = $this->push($till, [$this->payEvent($order, $tender)]);
        $this->assertSyncStatus($missing, 'failed');
        $this->assertStringContainsString(
            'GPS fix is required',
            $missing->json('data.results.0.result.error'),
        );

        $outside = $this->push($till, [
            $this->payEvent($order, $tender, ['gps' => ['lat' => 0, 'lng' => 0]]),
        ]);
        $this->assertSyncStatus($outside, 'failed');
        $this->assertStringContainsString(
            'geofence',
            $outside->json('data.results.0.result.error'),
        );

        $inside = $this->push($till, [
            $this->payEvent($order, $tender, [
                'gps' => ['lat' => 23.5800000, 'lng' => 58.3800000],
            ]),
        ]);
        $this->assertSyncStatus($inside, 'processed');
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }

    public function test_expired_claim_late_authorisation_records_orphan_marks_uncertain_and_blocks_a_second_claim(): void
    {
        $station = $this->device('expired-orphan-station', attributes: [
            'terminal_id' => 'EXPIRED-TERM',
            'bank_id' => 55,
        ]);
        $session = $this->qrSession($station);
        $order = $this->order($session);
        $this->claim($station, $order)->assertOk();
        $before = $order->fresh()->getAttributes();
        $sessionBefore = $session->fresh()->getAttributes();
        $this->travelTo($order->fresh()->charge_deadline_at->copy()->addSecond());
        $tender = $this->successfulCard();
        $tender['softpos_reference'] = 'EXPIRED-LATE-REF';
        $tender['softpos_auth_code'] = 'EXPIRED-AUTH';

        $response = $this->push($station, [
            $this->payEvent($order, [$tender]),
        ]);

        $this->assertSyncStatus($response, 'processed');
        $this->assertTrue($response->json('data.results.0.result.orphan_tender'));
        $payment = Payment::query()->where('order_id', $order->id)->sole();
        $this->assertSame(Payment::METHOD_CARD, $payment->method);
        $this->assertSame('4.750', $payment->amount);
        $this->assertSame(Payment::STATUS_PENDING_RECONCILIATION, $payment->status);
        $this->assertTrue((bool) $payment->pending_reconciliation);
        $this->assertSame((int) $station->id, (int) $payment->device_id);
        $this->assertSame('EXPIRED-TERM', $payment->terminal_id);
        $this->assertSame(55, (int) $payment->bank_id);
        $this->assertSame('EXPIRED-LATE-REF', $payment->softpos_reference);
        $this->assertSame('EXPIRED-AUTH', $payment->softpos_auth_code);

        $after = $order->fresh()->getAttributes();
        $this->assertSame(Order::CHARGE_OUTCOME_UNCERTAIN, $after['charge_outcome']);
        unset($before['charge_outcome'], $before['updated_at']);
        unset($after['charge_outcome'], $after['updated_at']);
        $this->assertSame($before, $after);
        $this->assertSame($sessionBefore, $session->fresh()->getAttributes());
        $this->assertDatabaseCount('pos_stock_movements', 0);
        $this->assertDatabaseCount('pos_sale_commissions', 0);
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);

        $secondStation = $this->device('expired-orphan-second-station');
        $session->update(['device_id' => $secondStation->id]);
        $beforeSecondClaim = $order->fresh()->getAttributes();
        $this->claim($secondStation, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_already_claimed');
        $this->assertSame($beforeSecondClaim, $order->fresh()->getAttributes());
        $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
    }

    public function test_released_claim_late_authorisation_is_orphaned_and_escalated_to_uncertain(): void
    {
        foreach ([Order::CHARGE_OUTCOME_DECLINED, Order::CHARGE_OUTCOME_CANCELLED] as $index => $outcome) {
            $station = $this->device('released-orphan-station-'.$index);
            $order = $this->order($this->qrSession($station));
            $this->claim($station, $order)->assertOk();
            $this->release($station, $order, $outcome)->assertOk();
            $released = $order->fresh();
            $tender = $this->successfulCard();
            $tender['softpos_reference'] = 'RELEASED-LATE-'.$index;

            $response = $this->push($station, [
                $this->payEvent($order, [$tender]),
            ]);

            $this->assertSyncStatus($response, 'processed');
            $this->assertTrue($response->json('data.results.0.result.orphan_tender'));
            $after = $order->fresh();
            $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $after->status);
            $this->assertSame(Order::CHARGE_OUTCOME_UNCERTAIN, $after->charge_outcome);
            $this->assertSame((int) $released->charge_device_id, (int) $after->charge_device_id);
            $this->assertSame($released->charge_amount_baisas, $after->charge_amount_baisas);
            $this->assertTrue($released->charge_claimed_at->equalTo($after->charge_claimed_at));
            $this->assertTrue($released->charge_deadline_at->equalTo($after->charge_deadline_at));
            $this->assertSame(1, Payment::query()->where('order_id', $order->id)->count());
        }
    }

    public function test_station_evidence_is_orphaned_for_nonawaiting_statuses_without_stamping_uncertain(): void
    {
        foreach ([Order::STATUS_HELD, Order::STATUS_PENDING_VERIFICATION] as $index => $status) {
            $station = $this->device('nonawaiting-orphan-station-'.$index);
            $session = $this->qrSession($station);
            $order = $this->order($session, ['status' => $status]);
            $before = $order->fresh()->getAttributes();
            $sessionBefore = $session->fresh()->getAttributes();
            $tender = $this->successfulCard();
            $tender['softpos_reference'] = 'NONAWAITING-LATE-'.$index;

            $response = $this->push($station, [
                $this->payEvent($order, [$tender]),
            ]);

            $this->assertSyncStatus($response, 'processed');
            $this->assertTrue($response->json('data.results.0.result.orphan_tender'));
            $this->assertSame($before, $order->fresh()->getAttributes());
            $this->assertSame($sessionBefore, $session->fresh()->getAttributes());
            $this->assertNull($order->fresh()->charge_outcome);
            $this->assertDatabaseHas('pos_payments', [
                'order_id' => $order->id,
                'status' => Payment::STATUS_PENDING_RECONCILIATION,
                'pending_reconciliation' => true,
            ]);
        }

        $station = $this->device('pending-without-evidence-station');
        $order = $this->order($this->qrSession($station), [
            'status' => Order::STATUS_PENDING_VERIFICATION,
        ]);
        $tender = $this->successfulCard();
        unset($tender['softpos_reference'], $tender['softpos_auth_code']);
        $response = $this->push($station, [
            $this->payEvent($order, [$tender]),
        ]);
        $this->assertSyncStatus($response, 'failed');
        $this->assertStringContainsString(
            'pending-verification',
            $response->json('data.results.0.result.error'),
        );
        $this->assertDatabaseMissing('pos_payments', ['order_id' => $order->id]);
        $this->assertDatabaseCount('pos_stock_movements', 0);
        $this->assertDatabaseCount('pos_sale_commissions', 0);
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);
    }

    public function test_late_authorisation_creates_one_transactional_reconciliation_orphan(): void
    {
        $till = $this->device('orphan-settling-till', 'fixed_pos');
        $failedAttemptDevice = $this->device('orphan-failed-till', 'fixed_pos');
        $station = $this->device('orphan-station', attributes: [
            'terminal_id' => 'ORPHAN-TERM',
            'bank_id' => 77,
        ]);
        $session = $this->qrSession($station, ['status' => QrSession::STATUS_CLOSED]);
        $closedAt = now()->subMinute()->startOfSecond();
        $order = $this->order($session, [
            'status' => Order::STATUS_PAID,
            'closed_at' => $closedAt,
        ]);
        $failedAttempt = Payment::query()->create([
            'uuid' => (string) Str::uuid(),
            'order_id' => $order->id,
            'method' => Payment::METHOD_CARD,
            'amount' => '4.750',
            'status' => Payment::STATUS_FAILED,
            'pending_reconciliation' => false,
            'device_id' => $failedAttemptDevice->id,
            'captured_at' => $closedAt->copy()->subMinute(),
        ]);
        $original = Payment::query()->create([
            'uuid' => (string) Str::uuid(),
            'order_id' => $order->id,
            'method' => Payment::METHOD_CASH,
            'amount' => '4.750',
            'status' => Payment::STATUS_SUCCESS,
            'pending_reconciliation' => false,
            'device_id' => $till->id,
            'captured_at' => $closedAt,
        ]);
        $orderBefore = $order->fresh()->getAttributes();
        $sessionBefore = $session->fresh()->getAttributes();
        $failedAttemptBefore = $failedAttempt->fresh()->getAttributes();
        $originalBefore = $original->fresh()->getAttributes();
        $eventId = (string) Str::uuid();
        $tender = $this->successfulCard();
        $tender['softpos_reference'] = 'LATE-REF-1';
        $tender['softpos_auth_code'] = 'LATE-AUTH-1';
        $tender['bank_response'] = [
            'result' => 'APPROVED',
            'qr_late_auth_orphan' => false,
            'order_uuid' => 'forged',
            'original_settling_device_id' => 999999,
        ];
        $event = $this->payEvent($order, [$tender], clientEventId: $eventId);

        $response = $this->push($station, [$event]);
        $this->assertSyncStatus($response, 'processed');
        $this->assertTrue($response->json('data.results.0.result.orphan_tender'));
        $orphanUuid = $response->json('data.results.0.result.orphan_payment_uuid');

        $this->assertDatabaseCount('pos_payments', 3);
        $orphan = Payment::query()->where('uuid', $orphanUuid)->sole();
        $this->assertSame(Payment::METHOD_CARD, $orphan->method);
        $this->assertSame('4.750', $orphan->amount);
        $this->assertSame(Payment::STATUS_PENDING_RECONCILIATION, $orphan->status);
        $this->assertTrue($orphan->pending_reconciliation);
        $this->assertSame('LATE-REF-1', $orphan->softpos_reference);
        $this->assertSame('LATE-AUTH-1', $orphan->softpos_auth_code);
        $this->assertSame('ORPHAN-TERM', $orphan->terminal_id);
        $this->assertSame(77, (int) $orphan->bank_id);
        $this->assertTrue($orphan->bank_response['qr_late_auth_orphan']);
        $this->assertSame($order->uuid, $orphan->bank_response['order_uuid']);
        $this->assertSame((int) $till->id, $orphan->bank_response['original_settling_device_id']);
        $this->assertSame($orderBefore, $order->fresh()->getAttributes());
        $this->assertSame($sessionBefore, $session->fresh()->getAttributes());
        $this->assertSame($failedAttemptBefore, $failedAttempt->fresh()->getAttributes());
        $this->assertSame($originalBefore, $original->fresh()->getAttributes());
        $this->assertDatabaseCount('pos_stock_movements', 0);
        $this->assertDatabaseCount('pos_sale_commissions', 0);
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);

        $sameEvent = $this->push($station, [$event])->assertOk();
        $this->assertTrue($sameEvent->json('data.results.0.duplicate'));
        $this->assertDatabaseCount('pos_payments', 3);

        $newEventSameEvidence = $this->push($station, [
            $this->payEvent($order, [$tender]),
        ]);
        $this->assertSyncStatus($newEventSameEvidence, 'processed');
        $this->assertTrue(
            $newEventSameEvidence->json('data.results.0.result.duplicate_softpos_evidence'),
        );
        $this->assertDatabaseCount('pos_payments', 3);

        $newReferenceSameAuth = $tender;
        $newReferenceSameAuth['softpos_reference'] = 'LATE-REF-2';
        $newEvidence = $this->push($station, [
            $this->payEvent($order, [$newReferenceSameAuth]),
        ]);
        $this->assertSyncStatus($newEvidence, 'processed');
        $this->assertTrue($newEvidence->json('data.results.0.result.orphan_tender'));
        $this->assertFalse(
            $newEvidence->json('data.results.0.result.duplicate_softpos_evidence'),
        );
        $this->assertDatabaseCount('pos_payments', 4);
        $this->assertDatabaseHas('pos_payments', [
            'order_id' => $order->id,
            'softpos_reference' => 'LATE-REF-2',
            'softpos_auth_code' => 'LATE-AUTH-1',
            'status' => Payment::STATUS_PENDING_RECONCILIATION,
            'pending_reconciliation' => true,
        ]);
    }

    public function test_auth_only_orphans_are_not_deduplicated_across_distinct_events(): void
    {
        $till = $this->device('auth-only-original-till', 'fixed_pos');
        $station = $this->device('auth-only-station');
        $order = $this->order($this->qrSession($station), [
            'status' => Order::STATUS_PAID,
            'closed_at' => now(),
        ]);
        Payment::query()->create([
            'uuid' => (string) Str::uuid(),
            'order_id' => $order->id,
            'method' => Payment::METHOD_CASH,
            'amount' => '4.750',
            'status' => Payment::STATUS_SUCCESS,
            'pending_reconciliation' => false,
            'device_id' => $till->id,
            'captured_at' => $order->closed_at,
        ]);
        $tender = $this->successfulCard();
        unset($tender['softpos_reference']);
        $tender['softpos_auth_code'] = 'REUSED-AUTH';

        $first = $this->push($station, [$this->payEvent($order, [$tender])]);
        $second = $this->push($station, [$this->payEvent($order, [$tender])]);
        $this->assertSyncStatus($first, 'processed');
        $this->assertSyncStatus($second, 'processed');
        $this->assertTrue($first->json('data.results.0.result.orphan_tender'));
        $this->assertTrue($second->json('data.results.0.result.orphan_tender'));
        $this->assertFalse($first->json('data.results.0.result.duplicate_softpos_evidence'));
        $this->assertFalse($second->json('data.results.0.result.duplicate_softpos_evidence'));
        $this->assertNotSame(
            $first->json('data.results.0.result.orphan_payment_uuid'),
            $second->json('data.results.0.result.orphan_payment_uuid'),
        );
        $this->assertDatabaseCount('pos_payments', 3);
    }

    public function test_void_order_late_authorisation_is_preserved_without_other_effects(): void
    {
        $station = $this->device('void-orphan-station');
        $session = $this->qrSession($station);
        $order = $this->order($session, ['status' => Order::STATUS_VOID]);
        $orderBefore = $order->fresh()->getAttributes();
        $sessionBefore = $session->fresh()->getAttributes();
        $tender = $this->successfulCard();
        $tender['softpos_reference'] = 'VOID-LATE-REF';
        $tender['softpos_auth_code'] = 'VOID-LATE-AUTH';

        $response = $this->push($station, [
            $this->payEvent($order, [$tender]),
        ]);
        $this->assertSyncStatus($response, 'processed');
        $this->assertTrue($response->json('data.results.0.result.orphan_tender'));
        $this->assertDatabaseHas('pos_payments', [
            'order_id' => $order->id,
            'method' => Payment::METHOD_CARD,
            'status' => Payment::STATUS_PENDING_RECONCILIATION,
            'pending_reconciliation' => true,
            'softpos_reference' => 'VOID-LATE-REF',
            'softpos_auth_code' => 'VOID-LATE-AUTH',
        ]);
        $this->assertSame($orderBefore, $order->fresh()->getAttributes());
        $this->assertSame($sessionBefore, $session->fresh()->getAttributes());
        $this->assertDatabaseCount('pos_stock_movements', 0);
        $this->assertDatabaseCount('pos_sale_commissions', 0);
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);
    }

    public function test_non_station_still_fast_fails_on_already_paid_order_without_orphan(): void
    {
        $till = $this->device('paid-original-till', 'fixed_pos');
        $otherTill = $this->device('paid-other-till', 'fixed_pos');
        $order = $this->order(null, [
            'device_id' => $till->id,
            'status' => Order::STATUS_PAID,
            'closed_at' => now(),
        ]);
        Payment::query()->create([
            'uuid' => (string) Str::uuid(),
            'order_id' => $order->id,
            'method' => Payment::METHOD_CASH,
            'amount' => '4.750',
            'status' => Payment::STATUS_SUCCESS,
            'pending_reconciliation' => false,
            'device_id' => $till->id,
            'captured_at' => now(),
        ]);

        $response = $this->push($otherTill, [
            $this->payEvent($order, [$this->successfulCard()]),
        ]);
        $this->assertSyncStatus($response, 'failed');
        $this->assertStringContainsString(
            'already paid',
            $response->json('data.results.0.result.error'),
        );
        $this->assertDatabaseCount('pos_payments', 1);
    }

    public function test_void_refuses_live_claim_but_accepts_declined_dead_claim(): void
    {
        $station = $this->device('station-void-claims');
        $live = $this->order($this->qrSession($station));
        $this->claim($station, $live)->assertOk();
        $liveBefore = $live->fresh()->getAttributes();

        $blocked = $this->push($station, [$this->voidEvent($live)]);
        $this->assertSyncStatus($blocked, 'failed');
        $this->assertStringContainsString(
            'live charge claim',
            $blocked->json('data.results.0.result.error'),
        );
        $this->assertSame($liveBefore, $live->fresh()->getAttributes());

        $dead = $this->order($this->qrSession($station));
        $this->claim($station, $dead)->assertOk();
        $this->release($station, $dead, Order::CHARGE_OUTCOME_DECLINED)->assertOk();
        $allowed = $this->push($station, [$this->voidEvent($dead)]);
        $this->assertSyncStatus($allowed, 'processed');
        $this->assertSame(Order::STATUS_VOID, $dead->fresh()->status);
    }

    public function test_fallback_is_idempotent_allocates_once_and_counter_can_settle_cash(): void
    {
        $this->enableNumbering();
        $station = $this->device('station-fallback');
        $session = $this->qrSession($station);
        $order = $this->order($session, [
            'charge_device_id' => $station->id,
            'charge_amount_baisas' => 4750,
            'charge_claimed_at' => now()->subMinute(),
            'charge_deadline_at' => now()->addMinute(),
            'charge_outcome' => Order::CHARGE_OUTCOME_CANCELLED,
        ]);

        $first = $this->postAs($station, self::FALLBACK_URL, [
            'order_uuid' => $order->uuid,
        ])->assertOk();
        $reference = $first->json('data.temp_reference');
        $this->assertNull($first->json('data.receipt_number'));
        $this->assertSame($order->temp_reference, $reference);
        $this->assertSame(Order::STATUS_HELD, $first->json('data.status'));

        $held = $order->fresh();
        $this->assertSame(Order::STATUS_HELD, $held->status);
        $this->assertNull($held->receipt_number);
        $this->assertSame($reference, $held->temp_reference);
        $this->assertSame((int) $session->id, (int) $held->qr_session_id);
        $this->assertNull($held->charge_device_id);
        $this->assertNull($held->charge_amount_baisas);
        $this->assertNull($held->charge_claimed_at);
        $this->assertNull($held->charge_deadline_at);
        $this->assertNull($held->charge_outcome);
        $this->assertSame(QrSession::STATUS_ORDERED, $session->fresh()->status);

        $second = $this->postAs($station, self::FALLBACK_URL, [
            'order_uuid' => $order->uuid,
        ])->assertOk();
        $this->assertNull($second->json('data.receipt_number'));
        $this->assertSame($reference, $second->json('data.temp_reference'));
        $this->assertDatabaseCount('pos_order_sequences', 0);

        $till = $this->device('fallback-cash-till', 'fixed_pos');
        $payment = $this->push($till, [
            $this->payEvent($order, [[
                'method' => Payment::METHOD_CASH,
                'amount_baisas' => 4750,
                'status' => Payment::STATUS_SUCCESS,
            ]]),
        ]);
        $this->assertSyncStatus($payment, 'processed');
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame('QR-0001', $order->fresh()->receipt_number);
        $this->assertSame($reference, $order->fresh()->temp_reference);
        $this->assertDatabaseHas('pos_order_sequences', [
            'company_id' => 100,
            'branch_id' => 10,
            'next_number' => 2,
        ]);
    }

    public function test_fallback_refuses_a_live_claim_without_mutating_it(): void
    {
        $this->enableNumbering();
        $station = $this->device('station-fallback-live');
        $order = $this->order($this->qrSession($station));
        $this->claim($station, $order)->assertOk();
        $before = $order->fresh()->getAttributes();

        $this->postAs($station, self::FALLBACK_URL, [
            'order_uuid' => $order->uuid,
        ])->assertConflict()->assertJsonPath('errors.0.code', 'charge_already_claimed');

        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertDatabaseCount('pos_order_sequences', 0);
    }

    public function test_fallback_accepts_a_never_claimed_awaiting_payment_order(): void
    {
        $this->enableNumbering();
        $station = $this->device('station-fallback-unclaimed');
        $order = $this->order($this->qrSession($station));

        $this->assertNull($order->charge_device_id);
        $this->assertNull($order->charge_amount_baisas);
        $this->assertNull($order->charge_claimed_at);
        $this->assertNull($order->charge_deadline_at);
        $this->assertNull($order->charge_outcome);

        $this->postAs($station, self::FALLBACK_URL, [
            'order_uuid' => $order->uuid,
        ])->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_HELD)
            ->assertJsonPath('data.receipt_number', null)
            ->assertJsonPath('data.temp_reference', $order->temp_reference);
    }

    public function test_sweeper_lapses_past_deadline_plus_grace_and_order_is_not_reclaimable(): void
    {
        $now = Carbon::parse('2026-08-27 14:00:00');
        $this->travelTo($now);
        $station = $this->device('station-sweep');
        $order = $this->order($this->qrSession($station));
        $this->claim($station, $order)->assertOk();
        $deadline = $order->fresh()->charge_deadline_at;
        $this->travelTo($deadline->copy()->addSeconds(30));

        $this->artisan('qr:sweep-stale-charges')
            ->expectsOutput('lapsed=1 grace_seconds=30')
            ->assertSuccessful();

        $this->assertSame(Order::CHARGE_OUTCOME_LAPSED, $order->fresh()->charge_outcome);
        $beforeRetry = $order->fresh()->getAttributes();
        $this->claim($station, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_already_claimed');
        $this->assertSame($beforeRetry, $order->fresh()->getAttributes());
    }

    public function test_sweeper_skips_terminal_outcomes_recent_grace_and_non_awaiting_rows(): void
    {
        $now = Carbon::parse('2026-08-27 15:00:00');
        $this->travelTo($now);
        $station = $this->device('station-sweep-boundaries');
        $uncertain = $this->order($this->qrSession($station), [
            'charge_device_id' => $station->id,
            'charge_amount_baisas' => 4750,
            'charge_claimed_at' => $now->copy()->subDay(),
            'charge_deadline_at' => $now->copy()->subDay(),
            'charge_outcome' => Order::CHARGE_OUTCOME_UNCERTAIN,
        ]);
        $approved = $this->order($this->qrSession($station), [
            'charge_device_id' => $station->id,
            'charge_amount_baisas' => 4750,
            'charge_claimed_at' => $now->copy()->subDay(),
            'charge_deadline_at' => $now->copy()->subDay(),
            'charge_outcome' => Order::CHARGE_OUTCOME_APPROVED,
        ]);
        $lapsed = $this->order($this->qrSession($station), [
            'charge_device_id' => $station->id,
            'charge_amount_baisas' => 4750,
            'charge_claimed_at' => $now->copy()->subDay(),
            'charge_deadline_at' => $now->copy()->subDay(),
            'charge_outcome' => Order::CHARGE_OUTCOME_LAPSED,
        ]);
        $insideDeadline = $this->order($this->qrSession($station), [
            'charge_device_id' => $station->id,
            'charge_amount_baisas' => 4750,
            'charge_claimed_at' => $now->copy()->subMinute(),
            'charge_deadline_at' => $now->copy()->addSecond(),
            'charge_outcome' => null,
        ]);
        $insideGrace = $this->order($this->qrSession($station), [
            'charge_device_id' => $station->id,
            'charge_amount_baisas' => 4750,
            'charge_claimed_at' => $now->copy()->subMinute(),
            'charge_deadline_at' => $now->copy()->subSeconds(20),
            'charge_outcome' => null,
        ]);
        $held = $this->order($this->qrSession($station), [
            'status' => Order::STATUS_HELD,
            'charge_device_id' => $station->id,
            'charge_amount_baisas' => 4750,
            'charge_claimed_at' => $now->copy()->subDay(),
            'charge_deadline_at' => $now->copy()->subDay(),
            'charge_outcome' => null,
        ]);
        $untouched = collect([$uncertain, $approved, $lapsed, $insideDeadline, $insideGrace, $held])
            ->mapWithKeys(static fn (Order $order): array => [
                (int) $order->getKey() => $order->fresh()->getAttributes(),
            ]);

        $this->artisan('qr:sweep-stale-charges')
            ->expectsOutput('lapsed=0 grace_seconds=30')
            ->assertSuccessful();

        $this->assertSame(Order::CHARGE_OUTCOME_UNCERTAIN, $uncertain->fresh()->charge_outcome);
        $this->assertSame(Order::CHARGE_OUTCOME_APPROVED, $approved->fresh()->charge_outcome);
        $this->assertSame(Order::CHARGE_OUTCOME_LAPSED, $lapsed->fresh()->charge_outcome);
        $this->assertNull($insideDeadline->fresh()->charge_outcome);
        $this->assertNull($insideGrace->fresh()->charge_outcome);
        $this->assertNull($held->fresh()->charge_outcome);
        foreach ([$uncertain, $approved, $lapsed, $insideDeadline, $insideGrace, $held] as $order) {
            $this->assertSame(
                $untouched->get((int) $order->getKey()),
                $order->fresh()->getAttributes(),
            );
        }
    }

    public function test_sweeper_rejects_nonpositive_grace_option(): void
    {
        $this->artisan('qr:sweep-stale-charges', ['--grace-seconds' => 0])
            ->expectsOutput('--grace-seconds must be a positive integer.')
            ->assertExitCode(Command::INVALID);
    }

    public function test_sweeper_schedule_and_device_route_guards_are_registered(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains(
                (string) $event->command,
                'qr:sweep-stale-charges',
            ));

        $this->assertNotNull($scheduled);
        $this->assertSame('* * * * *', $scheduled->expression);
        $this->assertTrue($scheduled->withoutOverlapping);
        $this->assertSame(30, $scheduled->expiresAt);
        $this->assertTrue($scheduled->onOneServer);
        $this->assertFalse($scheduled->filtersPass(app()));
        config(['qr.charge_sweep_enabled' => true]);
        $this->assertTrue($scheduled->filtersPass(app()));

        foreach ([
            'device.qr.claim-charge',
            'device.qr.release-charge',
            'device.qr.fallback-to-counter',
        ] as $name) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, $name);
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth:pos_device', $middleware, $name);
            $this->assertContains('throttle:device-api', $middleware, $name);
        }
    }
}
