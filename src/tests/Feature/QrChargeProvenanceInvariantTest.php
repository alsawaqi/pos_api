<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\QrSession;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\TestCase;

final class QrChargeProvenanceInvariantTest extends TestCase
{
    use RefreshDatabase;

    private const CHECKOUT_URL = '/api/v1/public/qr/checkout';

    private const STATUS_URL = '/api/v1/public/qr/status';

    private const AWAITING_URL = '/api/v1/device/qr/awaiting-orders';

    private const CLAIM_URL = '/api/v1/device/qr/claim-charge';

    private const RELEASE_URL = '/api/v1/device/qr/release-charge';

    private const FALLBACK_URL = '/api/v1/device/qr/fallback-to-counter';

    private const SYNC_URL = '/api/v1/device/sync/push';

    /** @var list<string> */
    private const CHARGE_PROVENANCE_FIELDS = [
        'charge_device_id',
        'charge_amount_baisas',
        'charge_roundup_amount_baisas',
        'charge_claimed_at',
        'charge_deadline_at',
        'charge_outcome',
    ];

    private int $deviceSequence = 0;

    private int $checkoutSequence = 0;

    private int $eventSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-29 09:00:00'));
        $this->withoutMiddleware(ThrottleRequests::class);
        config([
            'qr.charge_claim_seconds' => 180,
            'qr.charge_sweep_grace_seconds' => 30,
            'qr.charge_sweep_enabled' => false,
            'qr.session_lifetime_minutes' => 15,
            'qr.station_geofence_exempt' => false,
        ]);

        $this->seedCheckoutProduct();
        $this->enableNumbering();
    }

    public function test_unreported_claim_is_never_reoffered_before_or_after_the_sweep(): void
    {
        $station = $this->device('grace-station');
        $flow = $this->checkout($station);
        $order = $flow['order'];

        $this->assertListed($station, [$order->uuid]);

        $this->claim($station, $order)
            ->assertOk()
            ->assertJsonPath('data.already_claimed_by_this_device', false);
        $claimed = $order->fresh();
        $this->assertSame(0, $claimed->charge_roundup_amount_baisas);

        $this->assertListed($station, []);
        $this->claim($station, $order)
            ->assertOk()
            ->assertJsonPath('data.already_claimed_by_this_device', true)
            ->assertJsonPath('data.charge_deadline_at', $claimed->charge_deadline_at?->toIso8601String());
        $this->assertSame($claimed->getAttributes(), $order->fresh()->getAttributes());

        $this->travelTo($claimed->charge_deadline_at->copy()->addSecond());
        $beforeGraceProbe = $order->fresh()->getAttributes();
        $this->assertListed($station, []);
        $this->claim($station, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_already_claimed')
            ->assertJsonPath(
                'errors.0.message',
                'The order is not available for a new charge claim.',
            );
        $this->assertSame($beforeGraceProbe, $order->fresh()->getAttributes());

        $this->travelTo($claimed->charge_deadline_at->copy()->addSeconds(30));
        $this->artisan('qr:sweep-stale-charges')
            ->expectsOutput('lapsed=1 grace_seconds=30')
            ->assertSuccessful();

        $lapsed = $order->fresh();
        $this->assertSame(Order::CHARGE_OUTCOME_LAPSED, $lapsed->charge_outcome);
        foreach (array_diff(self::CHARGE_PROVENANCE_FIELDS, ['charge_outcome']) as $field) {
            $this->assertSame($claimed->getRawOriginal($field), $lapsed->getRawOriginal($field), $field);
        }
        $this->assertListed($station, []);
        $this->claim($station, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_already_claimed');
        $this->assertNoRoundupMoney();
    }

    public function test_station_declined_and_cancelled_claims_are_reoffered_and_freshly_claimable(): void
    {
        $station = $this->device('safe-release-station');

        foreach ([Order::CHARGE_OUTCOME_DECLINED, Order::CHARGE_OUTCOME_CANCELLED] as $outcome) {
            $flow = $this->checkout($station);
            $order = $flow['order'];
            $this->claim($station, $order)
                ->assertOk()
                ->assertJsonPath('data.already_claimed_by_this_device', false);
            $claimed = $order->fresh();

            $this->release($station, $order, $outcome)
                ->assertOk()
                ->assertJsonPath('data.charge_outcome', $outcome);
            $released = $order->fresh();
            foreach (array_diff(self::CHARGE_PROVENANCE_FIELDS, ['charge_outcome']) as $field) {
                $this->assertSame($claimed->getRawOriginal($field), $released->getRawOriginal($field), $field);
            }

            $this->assertContains($order->uuid, $this->listedUuids($station));
            $this->claim($station, $order)
                ->assertOk()
                ->assertJsonPath('data.already_claimed_by_this_device', false);
            $this->assertNull($order->fresh()->charge_outcome);
        }

        $this->assertNoRoundupMoney();
    }

    public function test_station_cannot_forge_the_server_only_lapsed_outcome(): void
    {
        $station = $this->device('forgery-station');
        $order = $this->checkout($station)['order'];
        $this->claim($station, $order)->assertOk();
        $before = $order->fresh()->getAttributes();

        $this->release($station, $order, Order::CHARGE_OUTCOME_LAPSED)
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed');

        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertNoRoundupMoney();
    }

    public function test_existing_reference_survives_safe_fallback_and_attended_lapsed_recovery(): void
    {
        $station = $this->device('reference-station');
        $till = $this->device('reference-till', 10, 'fixed_pos');

        $safeOrder = $this->checkout($station)['order'];
        $this->assertNull($safeOrder->receipt_number);
        $safeTemp = $safeOrder->temp_reference;
        $this->assertMatchesRegularExpression('/^T-\d{4}-\d{3,}$/', (string) $safeTemp);
        $safeOrder->update(['receipt_number' => 'INV-LEGACY-1']);
        $safeReference = $safeOrder->receipt_number;
        $this->assertDatabaseCount('pos_order_sequences', 0);
        $this->assertDatabaseHas('pos_temp_reference_sequences', [
            'company_id' => 100,
            'branch_id' => 10,
            'seq_date' => now()->toDateString(),
            'next_number' => 2,
        ]);

        $this->fallback($station, $safeOrder)
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_HELD)
            ->assertJsonPath('data.receipt_number', $safeReference)
            ->assertJsonPath('data.temp_reference', $safeTemp);
        $this->assertSame($safeReference, $safeOrder->fresh()->receipt_number);
        $this->assertDatabaseCount('pos_order_sequences', 0);

        $lapsedOrder = $this->checkout($station)['order'];
        $this->assertNull($lapsedOrder->receipt_number);
        $lapsedTemp = $lapsedOrder->temp_reference;
        $this->assertNotSame($safeTemp, $lapsedTemp);
        $lapsedOrder->update(['receipt_number' => 'INV-LEGACY-2']);
        $lapsedReference = $lapsedOrder->receipt_number;
        $this->assertDatabaseCount('pos_order_sequences', 0);

        $this->claim($station, $lapsedOrder)->assertOk();
        $deadline = $lapsedOrder->fresh()->charge_deadline_at;
        $this->assertNotNull($deadline);
        $this->travelTo($deadline->copy()->addSeconds(30));
        $this->artisan('qr:sweep-stale-charges')
            ->expectsOutput('lapsed=1 grace_seconds=30')
            ->assertSuccessful();
        $lapsed = $lapsedOrder->fresh();
        $this->assertSame(Order::CHARGE_OUTCOME_LAPSED, $lapsed->charge_outcome);
        $provenance = $this->chargeProvenance($lapsed);

        $this->fallback($till, $lapsed)
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_HELD)
            ->assertJsonPath('data.receipt_number', $lapsedReference)
            ->assertJsonPath('data.temp_reference', $lapsedTemp);
        $recovered = $lapsedOrder->fresh();
        $this->assertSame($lapsedReference, $recovered->receipt_number);
        $this->assertSame($provenance, $this->chargeProvenance($recovered));
        $this->assertDatabaseCount('pos_order_sequences', 0);
        $this->assertNoRoundupMoney();
    }

    public function test_inflight_and_scoped_orders_are_omitted_from_every_other_station(): void
    {
        $first = $this->device('scope-first', 10);
        $second = $this->device('scope-second', 10);
        $otherBranch = $this->device('scope-other-branch', 20);
        $firstOrder = $this->checkout($first)['order'];
        $secondOrder = $this->checkout($second)['order'];
        $otherBranchOrder = $this->checkout($otherBranch)['order'];

        $this->assertListed($first, [$firstOrder->uuid]);
        $this->assertListed($second, [$secondOrder->uuid]);
        $this->assertListed($otherBranch, [$otherBranchOrder->uuid]);

        $this->claim($first, $firstOrder)->assertOk();
        $this->assertListed($first, []);
        $this->assertListed($second, [$secondOrder->uuid]);
        $this->assertListed($otherBranch, [$otherBranchOrder->uuid]);

        $this->claim($second, $firstOrder)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_already_claimed');
        $this->claim($first, $secondOrder)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'order_not_bound_to_device_session');
        $this->claim($first, $otherBranchOrder)
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'order_not_found');
        $this->assertNoRoundupMoney();
    }

    public function test_attended_fallback_recovers_every_ambiguous_state_with_ordered_expired_and_closed_sessions(): void
    {
        $station = $this->device('recovery-station', 10);
        $till = $this->device('recovery-till', 10, 'fixed_pos');
        $customerTablet = $this->device('recovery-customer-tablet', 10, 'customer_tablet');
        $otherBranchTill = $this->device('recovery-other-branch', 20, 'fixed_pos');

        $cases = [];
        foreach ([QrSession::STATUS_ORDERED, QrSession::STATUS_EXPIRED, QrSession::STATUS_CLOSED] as $sessionState) {
            foreach (['expired_null', 'lapsed', 'uncertain'] as $chargeState) {
                foreach (['cash', 'card', 'void'] as $terminal) {
                    $cases[] = [$sessionState, $chargeState, $terminal];
                }
            }
        }

        $denialsProved = [];
        foreach ($cases as [$sessionState, $chargeState, $terminal]) {
            $flow = $this->ambiguousFlow($station, $chargeState);
            $this->putSessionInRecoveryState($flow, $sessionState);
            $order = $flow['order']->fresh();
            $context = $sessionState.' '.$chargeState.' '.$terminal;
            $before = $this->chargeProvenance($order->fresh());
            $beforeAttributes = $order->fresh()->getAttributes();

            if (! isset($denialsProved[$chargeState])) {
                $this->fallback($station, $order)
                    ->assertConflict()
                    ->assertJsonPath('errors.0.code', 'device_not_attended');
                $this->fallback($customerTablet, $order)
                    ->assertConflict()
                    ->assertJsonPath('errors.0.code', 'device_not_attended');
                $this->fallback($otherBranchTill, $order)
                    ->assertNotFound()
                    ->assertJsonPath('errors.0.code', 'order_not_found');

                $this->assertSame($beforeAttributes, $order->fresh()->getAttributes());
                $denialsProved[$chargeState] = true;
            }

            $fallback = $this->fallback($till, $order)
                ->assertOk()
                ->assertJsonPath('data.order_uuid', $order->uuid)
                ->assertJsonPath('data.status', Order::STATUS_HELD);
            $this->assertNull($fallback->json('data.receipt_number'));
            $reference = $fallback->json('data.temp_reference');
            $this->assertSame($order->temp_reference, $reference);
            $this->assertMatchesRegularExpression('/^T-\d{4}-\d{3,}$/', (string) $reference);
            $held = $order->fresh();
            $this->assertSame($before, $this->chargeProvenance($held), $context);

            if ($terminal === 'cash') {
                $stationPay = $this->push($station, [
                    $this->payEvent($order, [[
                        'method' => Payment::METHOD_CASH,
                        'amount_baisas' => 2500,
                        'status' => Payment::STATUS_SUCCESS,
                    ]]),
                ]);
                $this->assertSyncStatus($stationPay, 'failed');
                $this->assertStringContainsString(
                    'only an attended fixed POS or handheld device may resolve an ambiguous QR charge',
                    (string) $stationPay->json('data.results.0.result.error'),
                );

                $tabletVoid = $this->push($customerTablet, [$this->voidEvent($order)]);
                $this->assertSyncStatus($tabletVoid, 'failed');
                $this->assertStringContainsString(
                    'only an attended fixed POS or handheld device may resolve an ambiguous QR charge',
                    (string) $tabletVoid->json('data.results.0.result.error'),
                );
                $this->assertSame($held->getAttributes(), $order->fresh()->getAttributes());
            }

            $heldAttributes = $held->getAttributes();
            $this->fallback($till, $order)
                ->assertOk()
                ->assertJsonPath('data.receipt_number', null)
                ->assertJsonPath('data.temp_reference', $reference)
                ->assertJsonPath('data.status', Order::STATUS_HELD);
            $this->assertSame($heldAttributes, $order->fresh()->getAttributes());

            if ($terminal === 'void') {
                $this->assertSyncStatus($this->push($till, [$this->voidEvent($order)]), 'processed');
                $this->assertSame(Order::STATUS_VOID, $order->fresh()->status);
                $this->assertDatabaseMissing('pos_payments', ['order_id' => $order->id]);
            } else {
                $tender = [
                    'method' => $terminal === 'cash' ? Payment::METHOD_CASH : Payment::METHOD_CARD,
                    'amount_baisas' => 2500,
                    'status' => Payment::STATUS_SUCCESS,
                ];
                if ($terminal === 'card') {
                    $tender['softpos_reference'] = 'RECOVERY-'.$this->nextEventId();
                    $tender['softpos_auth_code'] = 'RECOVERY-AUTH';
                }
                $pay = $this->push($till, [$this->payEvent($order, [$tender])]);
                $this->assertSyncStatus($pay, 'processed');
                $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
                $payment = Payment::query()->where('order_id', $order->id)->sole();
                $this->assertSame('2.500', $payment->amount);
                $this->assertNull($payment->roundup_amount);
            }

            $this->assertSame($before, $this->chargeProvenance($order->fresh()), $context);
            $this->assertNoRoundupMoney();
        }
    }

    public function test_ambiguous_recovery_uses_an_explicit_attended_device_allowlist(): void
    {
        $station = $this->device('allowlist-station');
        $rescuer = $this->device('allowlist-rescuer', 10, 'fixed_pos');
        $deviceTypes = [
            'fixed_pos' => true,
            'handheld' => true,
            'customer_tablet' => false,
            'payment_station' => false,
            'pos_terminal' => false,
            'unknown_attended_shape' => false,
        ];

        foreach ($deviceTypes as $deviceType => $allowed) {
            $device = $this->device('allowlist-'.$deviceType, 10, $deviceType);
            $order = $this->ambiguousOrder($station, 'lapsed');
            $before = $order->fresh()->getAttributes();
            $fallback = $this->fallback($device, $order);

            if ($allowed) {
                $fallback
                    ->assertOk()
                    ->assertJsonPath('data.status', Order::STATUS_HELD);

                if ($deviceType === 'fixed_pos') {
                    $this->assertSyncStatus($this->push($device, [
                        $this->payEvent($order, [[
                            'method' => Payment::METHOD_CASH,
                            'amount_baisas' => 2500,
                            'status' => Payment::STATUS_SUCCESS,
                        ]]),
                    ]), 'processed');
                    $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
                    $this->assertNull(
                        Payment::query()->where('order_id', $order->id)->sole()->roundup_amount,
                    );
                } else {
                    $this->assertSyncStatus(
                        $this->push($device, [$this->voidEvent($order)]),
                        'processed',
                    );
                    $this->assertSame(Order::STATUS_VOID, $order->fresh()->status);
                }
            } else {
                $fallback
                    ->assertConflict()
                    ->assertJsonPath('errors.0.code', 'device_not_attended');
                $this->assertSame($before, $order->fresh()->getAttributes(), $deviceType);

                $this->fallback($rescuer, $order)
                    ->assertOk()
                    ->assertJsonPath('data.status', Order::STATUS_HELD);
                $held = $order->fresh()->getAttributes();

                $pay = $this->push($device, [
                    $this->payEvent($order, [[
                        'method' => Payment::METHOD_CASH,
                        'amount_baisas' => 2500,
                        'status' => Payment::STATUS_SUCCESS,
                    ]]),
                ]);
                $this->assertSyncStatus($pay, 'failed');
                $this->assertStringContainsString(
                    'only an attended fixed POS or handheld device may resolve an ambiguous QR charge',
                    (string) $pay->json('data.results.0.result.error'),
                );

                $void = $this->push($device, [$this->voidEvent($order)]);
                $this->assertSyncStatus($void, 'failed');
                $this->assertStringContainsString(
                    'only an attended fixed POS or handheld device may resolve an ambiguous QR charge',
                    (string) $void->json('data.results.0.result.error'),
                );
                $this->assertSame($held, $order->fresh()->getAttributes(), $deviceType);

                $this->assertSyncStatus(
                    $this->push($rescuer, [$this->voidEvent($order)]),
                    'processed',
                );
                $this->assertSame(Order::STATUS_VOID, $order->fresh()->status);
            }

            $this->assertNoRoundupMoney();
        }
    }

    public function test_ambiguous_recovery_is_idempotent_without_numbering(): void
    {
        DB::table('pos_company_settings')
            ->where('company_id', 100)
            ->where('key', 'order_numbering')
            ->delete();

        $station = $this->device('unnumbered-station');
        $till = $this->device('unnumbered-till', 10, 'fixed_pos');

        foreach (['lapsed', 'uncertain'] as $state) {
            $order = $this->ambiguousOrder($station, $state);
            $before = $this->chargeProvenance($order);

            $this->fallback($till, $order)
                ->assertOk()
                ->assertJsonPath('data.status', Order::STATUS_HELD)
                ->assertJsonPath('data.receipt_number', null);
            $held = $order->fresh();
            $this->assertNull($held->receipt_number);
            $this->assertSame($before, $this->chargeProvenance($held));

            $heldAttributes = $held->getAttributes();
            $this->fallback($till, $order)
                ->assertOk()
                ->assertJsonPath('data.status', Order::STATUS_HELD)
                ->assertJsonPath('data.receipt_number', null);
            $this->assertSame($heldAttributes, $order->fresh()->getAttributes());

            $this->assertSyncStatus($this->push($till, [$this->voidEvent($order)]), 'processed');
            $this->assertSame(Order::STATUS_VOID, $order->fresh()->status);
        }

        $this->assertNoRoundupMoney();
    }

    public function test_station_and_customer_tablet_cannot_resolve_ambiguous_kitchen_order(): void
    {
        $station = $this->device('kitchen-station');
        $till = $this->device('kitchen-till', 10, 'fixed_pos');
        $tablet = $this->device('kitchen-tablet', 10, 'customer_tablet');
        $order = $this->ambiguousOrder($station, 'lapsed');

        $this->fallback($till, $order)->assertOk();
        $order->update(['status' => Order::STATUS_KITCHEN]);
        $before = $order->fresh()->getAttributes();

        foreach ([$station, $tablet] as $device) {
            $pay = $this->push($device, [
                $this->payEvent($order, [[
                    'method' => Payment::METHOD_CASH,
                    'amount_baisas' => 2500,
                    'status' => Payment::STATUS_SUCCESS,
                ]]),
            ]);
            $this->assertSyncStatus($pay, 'failed');
            $this->assertStringContainsString(
                'only an attended fixed POS or handheld device may resolve an ambiguous QR charge',
                (string) $pay->json('data.results.0.result.error'),
            );

            $void = $this->push($device, [$this->voidEvent($order)]);
            $this->assertSyncStatus($void, 'failed');
            $this->assertStringContainsString(
                'only an attended fixed POS or handheld device may resolve an ambiguous QR charge',
                (string) $void->json('data.results.0.result.error'),
            );
        }

        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->assertNoRoundupMoney();
    }

    #[DataProvider('randomSeeds')]
    public function test_seeded_interleavings_preserve_lifetime_invariants_after_every_call(int $seed): void
    {
        $random = new Randomizer(new Mt19937($seed));
        $stations = [
            $this->device('random-a-'.$seed, 10),
            $this->device('random-b-'.$seed, 10),
            $this->device('random-c-'.$seed, 20),
        ];
        $tills = [
            10 => $this->device('random-till-10-'.$seed, 10, 'fixed_pos'),
            20 => $this->device('random-till-20-'.$seed, 20, 'fixed_pos'),
        ];

        $flows = [];
        $freshClaims = [];
        $safeReleases = [];
        foreach ($stations as $index => $station) {
            $flows[] = $this->checkout($station);
            $uuid = (string) $flows[$index]['order']->uuid;
            $freshClaims[$uuid] = 0;
            $safeReleases[$uuid] = 0;
            $this->assertLifetimeInvariants(
                $flows,
                $stations,
                $freshClaims,
                $safeReleases,
                "seed={$seed} setup-checkout={$index}",
            );
        }

        $operations = $random->shuffleArray([
            'checkout_replay',
            'poll',
            'claim',
            'claim',
            'release_declined',
            'release_cancelled',
            'release_uncertain',
            'station_pay',
            'silence_deadline',
            'silence_grace',
            'sweep',
            'sweep',
            'fallback_station',
            'fallback_till',
            'till_pay',
            'till_void',
        ]);

        foreach ($operations as $step => $operation) {
            $targetIndex = $random->getInt(0, count($flows) - 1);
            $actorIndex = $random->getInt(0, count($stations) - 1);
            $context = "seed={$seed} step={$step} op={$operation} target={$targetIndex} actor={$actorIndex}";

            $this->applyRandomOperation(
                $operation,
                $flows[$targetIndex],
                $stations[$actorIndex],
                $tills,
                $freshClaims,
                $safeReleases,
                $context,
            );
            $this->assertLifetimeInvariants(
                $flows,
                $stations,
                $freshClaims,
                $safeReleases,
                $context,
            );
        }

        $this->exerciseGuaranteedExpiredSessionArm(
            $seed,
            $flows,
            $stations,
            $tills,
            $freshClaims,
            $safeReleases,
        );
        $this->settleEveryReachableFlow(
            $seed,
            $flows,
            $stations,
            $tills,
            $freshClaims,
            $safeReleases,
        );
    }

    /** @return array<string, array{int}> */
    public static function randomSeeds(): array
    {
        return [
            'seed 0x51A7E' => [0x51A7E],
            'seed 0xC0FFEE' => [0xC0FFEE],
        ];
    }

    /**
     * @param  array{station: Device, session: QrSession, secret: string, payload: array<string, mixed>, order: Order}  $flow
     * @param  array<int, Device>  $tills
     * @param  array<string, int>  $freshClaims
     * @param  array<string, int>  $safeReleases
     */
    private function applyRandomOperation(
        string $operation,
        array $flow,
        Device $actor,
        array $tills,
        array &$freshClaims,
        array &$safeReleases,
        string $context,
    ): void {
        $order = $flow['order']->fresh();

        if ($operation === 'checkout_replay') {
            $this->replayCheckout($flow)
                ->assertCreated()
                ->assertJsonPath('data.order.uuid', $order->uuid);

            return;
        }
        if ($operation === 'poll') {
            $this->getAs($actor)->assertOk();

            return;
        }
        if ($operation === 'claim') {
            $response = $this->claim($actor, $order);
            $this->assertKnownClaimResponse($response, $context);
            if ($response->getStatusCode() === 200
                && $response->json('data.already_claimed_by_this_device') === false) {
                $freshClaims[(string) $order->uuid]++;
            }

            return;
        }
        if (str_starts_with($operation, 'release_')) {
            $outcome = match ($operation) {
                'release_declined' => Order::CHARGE_OUTCOME_DECLINED,
                'release_cancelled' => Order::CHARGE_OUTCOME_CANCELLED,
                default => Order::CHARGE_OUTCOME_UNCERTAIN,
            };
            $response = $this->release($actor, $order, $outcome);
            $this->assertKnownReleaseResponse($response, $context);
            if ($response->getStatusCode() === 200
                && in_array($outcome, [
                    Order::CHARGE_OUTCOME_DECLINED,
                    Order::CHARGE_OUTCOME_CANCELLED,
                ], true)) {
                $safeReleases[(string) $order->uuid]++;
            }

            return;
        }
        if ($operation === 'station_pay') {
            $response = $this->push($actor, [
                $this->payEvent($order, [[
                    'method' => Payment::METHOD_CARD,
                    'amount_baisas' => 2500,
                    'status' => Payment::STATUS_SUCCESS,
                    'softpos_reference' => 'RANDOM-'.$this->nextEventId(),
                    'softpos_auth_code' => 'RANDOM-AUTH',
                ]]),
            ]);
            $this->assertKnownSyncResponse($response, $context);

            return;
        }
        if ($operation === 'silence_deadline' || $operation === 'silence_grace') {
            $offset = $operation === 'silence_deadline' ? 1 : 31;
            $target = $order->charge_deadline_at?->copy()->addSeconds($offset);
            $this->travelTo($target !== null && $target->isAfter(now()) ? $target : now()->addSecond());

            return;
        }
        if ($operation === 'sweep') {
            $this->assertSame(Command::SUCCESS, Artisan::call('qr:sweep-stale-charges'), $context);
            $this->assertMatchesRegularExpression(
                '/^lapsed=\d+ grace_seconds=30\s*$/',
                Artisan::output(),
                $context,
            );

            return;
        }
        if ($operation === 'fallback_station') {
            $this->assertKnownFallbackResponse($this->fallback($actor, $order), $context);

            return;
        }
        if ($operation === 'fallback_till') {
            $this->assertKnownFallbackResponse(
                $this->fallback($tills[(int) $order->branch_id], $order),
                $context,
            );

            return;
        }
        if ($operation === 'till_pay') {
            $response = $this->push($tills[(int) $order->branch_id], [
                $this->payEvent($order, [[
                    'method' => Payment::METHOD_CASH,
                    'amount_baisas' => 2500,
                    'status' => Payment::STATUS_SUCCESS,
                ]]),
            ]);
            $this->assertKnownSyncResponse($response, $context);

            return;
        }

        $this->assertKnownSyncResponse(
            $this->push($tills[(int) $order->branch_id], [$this->voidEvent($order)]),
            $context,
        );
    }

    /**
     * This arm is deliberately appended to every random interleaving. It
     * guarantees that each seed crosses the claim deadline, the sweep grace
     * boundary, and the configured session lifetime in that order. The final
     * transition to expired is made by the real public status route with the
     * customer's credential; changing the model directly would miss the
     * production dead end that this regression test exists to prevent.
     *
     * @param  list<array{station: Device, session: QrSession, secret: string, payload: array<string, mixed>, order: Order}>  $flows
     * @param  list<Device>  $stations
     * @param  array<int, Device>  $tills
     * @param  array<string, int>  $freshClaims
     * @param  array<string, int>  $safeReleases
     */
    private function exerciseGuaranteedExpiredSessionArm(
        int $seed,
        array &$flows,
        array $stations,
        array $tills,
        array &$freshClaims,
        array &$safeReleases,
    ): void {
        $station = $stations[0];
        $flow = $this->checkout($station);
        $flows[] = $flow;
        $uuid = (string) $flow['order']->uuid;
        $freshClaims[$uuid] = 0;
        $safeReleases[$uuid] = 0;
        $context = "seed={$seed} guaranteed-expiry";

        $this->assertLifetimeInvariants(
            $flows,
            $stations,
            $freshClaims,
            $safeReleases,
            $context.' checkout',
        );

        $this->claim($station, $flow['order'])
            ->assertOk()
            ->assertJsonPath('data.already_claimed_by_this_device', false);
        $freshClaims[$uuid]++;
        $claimed = $flow['order']->fresh();
        $this->assertLifetimeInvariants(
            $flows,
            $stations,
            $freshClaims,
            $safeReleases,
            $context.' fresh-claim',
        );

        $this->travelTo($claimed->charge_deadline_at->copy()->addSecond());
        $this->assertLifetimeInvariants(
            $flows,
            $stations,
            $freshClaims,
            $safeReleases,
            $context.' after-claim-deadline',
        );

        $this->travelTo($claimed->charge_deadline_at->copy()->addSeconds(31));
        $this->assertSame(Command::SUCCESS, Artisan::call('qr:sweep-stale-charges'), $context);
        $this->assertMatchesRegularExpression(
            '/^lapsed=\d+ grace_seconds=30\s*$/',
            Artisan::output(),
            $context,
        );
        $this->assertSame(Order::CHARGE_OUTCOME_LAPSED, $flow['order']->fresh()->charge_outcome);
        $this->assertLifetimeInvariants(
            $flows,
            $stations,
            $freshClaims,
            $safeReleases,
            $context.' after-sweeper-grace',
        );

        $this->expireSessionThroughPublicStatus($flow);
        $this->assertSame(QrSession::STATUS_EXPIRED, $flow['session']->fresh()->status);
        $this->assertLifetimeInvariants(
            $flows,
            $stations,
            $freshClaims,
            $safeReleases,
            $context.' public-session-expiry',
        );

        $till = $tills[(int) $flow['order']->branch_id];
        $this->fallback($till, $flow['order'])
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_HELD);
        $this->assertLifetimeInvariants(
            $flows,
            $stations,
            $freshClaims,
            $safeReleases,
            $context.' fallback-from-expired-session',
        );

        if ($seed === 0x51A7E) {
            $this->assertSyncStatus($this->push($till, [
                $this->payEvent($flow['order'], [[
                    'method' => Payment::METHOD_CASH,
                    'amount_baisas' => 2500,
                    'status' => Payment::STATUS_SUCCESS,
                ]]),
            ]), 'processed');
            $this->assertSame(Order::STATUS_PAID, $flow['order']->fresh()->status);
            $this->assertNull(
                Payment::query()->where('order_id', $flow['order']->id)->sole()->roundup_amount,
            );
        } else {
            $this->assertSyncStatus(
                $this->push($till, [$this->voidEvent($flow['order'])]),
                'processed',
            );
            $this->assertSame(Order::STATUS_VOID, $flow['order']->fresh()->status);
        }

        $this->assertLifetimeInvariants(
            $flows,
            $stations,
            $freshClaims,
            $safeReleases,
            $context.' terminal',
        );
    }

    /**
     * @param  list<array{station: Device, session: QrSession, secret: string, payload: array<string, mixed>, order: Order}>  $flows
     * @param  list<Device>  $stations
     * @param  array<int, Device>  $tills
     * @param  array<string, int>  $freshClaims
     * @param  array<string, int>  $safeReleases
     */
    private function settleEveryReachableFlow(
        int $seed,
        array $flows,
        array $stations,
        array $tills,
        array $freshClaims,
        array $safeReleases,
    ): void {
        foreach ($flows as $index => $flow) {
            $order = $flow['order']->fresh();
            $context = "seed={$seed} terminal-drain={$index}";
            if (in_array($order->status, [Order::STATUS_PAID, Order::STATUS_VOID], true)) {
                continue;
            }

            if ($order->status === Order::STATUS_AWAITING_PAYMENT) {
                if ($order->charge_claimed_at !== null
                    && $order->charge_outcome === null
                    && $order->charge_deadline_at !== null
                    && $order->charge_deadline_at->isAfter(now())) {
                    $this->travelTo($order->charge_deadline_at->copy()->addSecond());
                    $this->assertLifetimeInvariants(
                        $flows,
                        $stations,
                        $freshClaims,
                        $safeReleases,
                        $context.' crossed-live-deadline',
                    );
                    $order = $flow['order']->fresh();
                }

                $this->assertNotSame(
                    Order::CHARGE_OUTCOME_APPROVED,
                    $order->charge_outcome,
                    $context.' approved charge must already be terminal',
                );
                $recoveryDevice = $this->isAmbiguousForRecovery($order)
                    ? $tills[(int) $order->branch_id]
                    : $flow['station'];
                $this->fallback($recoveryDevice, $order)
                    ->assertOk()
                    ->assertJsonPath('data.status', Order::STATUS_HELD);
                $this->assertLifetimeInvariants(
                    $flows,
                    $stations,
                    $freshClaims,
                    $safeReleases,
                    $context.' fallback',
                );
                $order = $flow['order']->fresh();
            }

            $this->assertContains(
                $order->status,
                [Order::STATUS_HELD, Order::STATUS_OPEN, Order::STATUS_KITCHEN],
                $context,
            );
            $till = $tills[(int) $order->branch_id];
            if (($seed + $index) % 2 === 0) {
                $this->assertSyncStatus($this->push($till, [
                    $this->payEvent($order, [[
                        'method' => Payment::METHOD_CASH,
                        'amount_baisas' => 2500,
                        'status' => Payment::STATUS_SUCCESS,
                    ]]),
                ]), 'processed');
                $this->assertSame(Order::STATUS_PAID, $flow['order']->fresh()->status, $context);
                $this->assertNull(
                    Payment::query()->where('order_id', $order->id)->sole()->roundup_amount,
                );
            } else {
                $this->assertSyncStatus(
                    $this->push($till, [$this->voidEvent($order)]),
                    'processed',
                );
                $this->assertSame(Order::STATUS_VOID, $flow['order']->fresh()->status, $context);
            }

            $this->assertLifetimeInvariants(
                $flows,
                $stations,
                $freshClaims,
                $safeReleases,
                $context.' terminal',
            );
        }

        foreach ($flows as $flow) {
            $this->assertContains(
                $flow['order']->fresh()->status,
                [Order::STATUS_PAID, Order::STATUS_VOID],
                "seed={$seed} every reachable order reached a terminal state",
            );
        }
    }

    /**
     * The list is intentionally narrower than all HTTP 200 claim responses:
     * a same-holder live replay remains 200/idempotent. Agreement therefore
     * means list membership iff a fresh claim would be admitted.
     *
     * @param  list<array{station: Device, session: QrSession, secret: string, payload: array<string, mixed>, order: Order}>  $flows
     * @param  list<Device>  $stations
     * @param  array<string, int>  $freshClaims
     * @param  array<string, int>  $safeReleases
     */
    private function assertLifetimeInvariants(
        array $flows,
        array $stations,
        array $freshClaims,
        array $safeReleases,
        string $context,
    ): void {
        $listed = [];
        foreach ($stations as $station) {
            $actual = $this->listedUuids($station);
            $expected = [];
            foreach ($flows as $flow) {
                if ($this->isFreshlyClaimable(
                    $flow['order']->fresh(),
                    $flow['session']->fresh(),
                    $station,
                )) {
                    $expected[] = (string) $flow['order']->uuid;
                }
            }
            sort($actual);
            sort($expected);
            $this->assertSame($expected, $actual, $context.' list device='.(int) $station->id);
            $listed[(int) $station->id] = $actual;
        }

        foreach ($flows as $flow) {
            $order = $flow['order']->fresh();
            $owner = $flow['station'];
            $expectedFresh = $this->isFreshlyClaimable(
                $order,
                $flow['session']->fresh(),
                $owner,
            );
            $this->assertSame(
                $expectedFresh,
                in_array((string) $order->uuid, $listed[(int) $owner->id] ?? [], true),
                $context.' list/fresh predicate',
            );

            $before = $order->getAttributes();
            $connection = DB::connection();
            $outerLevel = $connection->transactionLevel();
            $connection->beginTransaction();
            try {
                $probe = $this->claim($owner, $order);
                $freshAdmission = $probe->getStatusCode() === 200
                    && $probe->json('data.already_claimed_by_this_device') === false;
                $this->assertSame($expectedFresh, $freshAdmission, $context.' fresh claim probe');

                if (! $expectedFresh && $probe->getStatusCode() === 200) {
                    $this->assertTrue(
                        $probe->json('data.already_claimed_by_this_device'),
                        $context.' omitted 200 must be idempotent replay',
                    );
                    $this->assertNull($order->fresh()->charge_outcome, $context);
                    $this->assertTrue($order->fresh()->charge_deadline_at->isAfter(now()), $context);
                    $this->assertSame((int) $owner->id, (int) $order->fresh()->charge_device_id);
                } elseif (! $expectedFresh) {
                    $probe->assertConflict();
                }
            } finally {
                while ($connection->transactionLevel() > $outerLevel) {
                    $connection->rollBack();
                }
            }
            $this->assertSame($before, $order->fresh()->getAttributes(), $context.' probe rollback');

            $uuid = (string) $order->uuid;
            $this->assertLessThanOrEqual(
                1 + ($safeReleases[$uuid] ?? 0),
                $freshClaims[$uuid] ?? 0,
                $context.' fresh claim count',
            );
            if ($this->isPossiblyCharged($order->fresh())) {
                foreach ($listed as $deviceId => $uuids) {
                    $this->assertNotContains($uuid, $uuids, $context.' unsafe listed by '.$deviceId);
                }
            }
        }

        $this->assertNoRoundupMoney();
    }

    private function isFreshlyClaimable(Order $order, QrSession $session, Device $station): bool
    {
        if ($order->status !== Order::STATUS_AWAITING_PAYMENT
            || $session->status !== QrSession::STATUS_ORDERED
            || (int) $session->device_id !== (int) $station->id
            || (int) $session->company_id !== (int) $station->company_id
            || (int) $session->branch_id !== (int) $station->branch_id) {
            return false;
        }

        return ($order->charge_claimed_at === null && $order->charge_outcome === null)
            || in_array($order->charge_outcome, [
                Order::CHARGE_OUTCOME_DECLINED,
                Order::CHARGE_OUTCOME_CANCELLED,
            ], true);
    }

    private function isPossiblyCharged(Order $order): bool
    {
        return $order->charge_claimed_at !== null
            && ($order->charge_outcome === null
                || in_array($order->charge_outcome, [
                    Order::CHARGE_OUTCOME_LAPSED,
                    Order::CHARGE_OUTCOME_UNCERTAIN,
                    Order::CHARGE_OUTCOME_APPROVED,
                ], true));
    }

    private function isAmbiguousForRecovery(Order $order): bool
    {
        if (in_array($order->charge_outcome, [
            Order::CHARGE_OUTCOME_LAPSED,
            Order::CHARGE_OUTCOME_UNCERTAIN,
        ], true)) {
            return true;
        }

        return $order->charge_claimed_at !== null
            && $order->charge_outcome === null
            && $order->charge_deadline_at !== null
            && $order->charge_deadline_at->lessThanOrEqualTo(now());
    }

    private function assertKnownClaimResponse(TestResponse $response, string $context): void
    {
        if ($response->getStatusCode() === 200) {
            $this->assertContains(
                $response->json('data.already_claimed_by_this_device'),
                [true, false],
                $context,
            );

            return;
        }

        $this->assertContains($response->getStatusCode(), [404, 409], $context);
        $this->assertContains($response->json('errors.0.code'), [
            'order_not_found',
            'order_not_bound_to_device_session',
            'charge_already_claimed',
            'order_not_awaiting_payment',
            'session_not_ordered',
        ], $context);
    }

    private function assertKnownReleaseResponse(TestResponse $response, string $context): void
    {
        if ($response->getStatusCode() === 200) {
            return;
        }

        $this->assertContains($response->getStatusCode(), [404, 409], $context);
        $this->assertContains($response->json('errors.0.code'), [
            'order_not_found',
            'order_not_awaiting_payment',
            'charge_not_claimed_by_device',
            'charge_already_claimed',
            'charge_outcome_uncertain',
        ], $context);
    }

    private function assertKnownFallbackResponse(TestResponse $response, string $context): void
    {
        if ($response->getStatusCode() === 200) {
            return;
        }

        $this->assertContains($response->getStatusCode(), [404, 409], $context);
        $this->assertContains($response->json('errors.0.code'), [
            'order_not_found',
            'order_not_awaiting_payment',
            'order_already_held',
            'charge_already_claimed',
            'device_not_attended',
            'device_not_payment_station',
            'order_not_bound_to_device_session',
            'session_not_ordered',
        ], $context);
    }

    private function assertKnownSyncResponse(TestResponse $response, string $context): void
    {
        $response->assertOk();
        $this->assertContains(
            $response->json('data.results.0.status'),
            ['processed', 'failed'],
            $context,
        );
    }

    /** @return array<string, mixed> */
    private function chargeProvenance(Order $order): array
    {
        return array_intersect_key(
            $order->getAttributes(),
            array_flip(self::CHARGE_PROVENANCE_FIELDS),
        );
    }

    private function ambiguousOrder(Device $station, string $state): Order
    {
        return $this->ambiguousFlow($station, $state)['order'];
    }

    /**
     * @return array{station: Device, session: QrSession, secret: string, payload: array<string, mixed>, order: Order}
     */
    private function ambiguousFlow(Device $station, string $state): array
    {
        $flow = $this->checkout($station);
        $order = $flow['order'];
        $this->claim($station, $order)
            ->assertOk()
            ->assertJsonPath('data.already_claimed_by_this_device', false);
        $deadline = $order->fresh()->charge_deadline_at;

        if ($state === 'expired_null') {
            $this->travelTo($deadline->copy()->addSecond());
        } elseif ($state === 'lapsed') {
            $this->travelTo($deadline->copy()->addSeconds(30));
            $this->artisan('qr:sweep-stale-charges')
                ->expectsOutput('lapsed=1 grace_seconds=30')
                ->assertSuccessful();
            $this->assertSame(Order::CHARGE_OUTCOME_LAPSED, $order->fresh()->charge_outcome);
        } else {
            $this->release($station, $order, Order::CHARGE_OUTCOME_UNCERTAIN)
                ->assertOk()
                ->assertJsonPath('data.charge_outcome', Order::CHARGE_OUTCOME_UNCERTAIN);
        }

        $this->assertSame(Order::STATUS_AWAITING_PAYMENT, $order->fresh()->status);
        $flow['order'] = $order->fresh();

        return $flow;
    }

    /**
     * @param  array{station: Device, session: QrSession, secret: string, payload: array<string, mixed>, order: Order}  $flow
     */
    private function putSessionInRecoveryState(array $flow, string $status): void
    {
        if ($status === QrSession::STATUS_ORDERED) {
            $this->assertSame(QrSession::STATUS_ORDERED, $flow['session']->fresh()->status);
            $this->publicStatus($flow)
                ->assertOk()
                ->assertJsonPath('data.status', QrSession::STATUS_ORDERED);

            return;
        }

        if ($status === QrSession::STATUS_EXPIRED) {
            $this->expireSessionThroughPublicStatus($flow);

            return;
        }

        $this->assertSame(QrSession::STATUS_CLOSED, $status);
        $flow['session']->update([
            'status' => QrSession::STATUS_CLOSED,
            'closed_at' => now(),
        ]);
        $this->assertSame(QrSession::STATUS_CLOSED, $flow['session']->fresh()->status);
        $this->publicStatus($flow)
            ->assertOk()
            ->assertJsonPath('data.status', QrSession::STATUS_CLOSED);
    }

    /**
     * @param  array{station: Device, session: QrSession, secret: string, payload: array<string, mixed>, order: Order}  $flow
     */
    private function expireSessionThroughPublicStatus(array $flow): void
    {
        $expiresAt = $flow['session']->fresh()->expires_at;
        $this->assertNotNull($expiresAt);
        $afterLifetime = $expiresAt->copy()->addSecond();
        if ($afterLifetime->isAfter(now())) {
            $this->travelTo($afterLifetime);
        }

        $this->publicStatus($flow)
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'qr_session_not_found');

        $expired = $flow['session']->fresh();
        $this->assertSame(QrSession::STATUS_EXPIRED, $expired->status);
        $this->assertNotNull($expired->closed_at);
    }

    /**
     * @param  array{station: Device, session: QrSession, secret: string, payload: array<string, mixed>, order: Order}  $flow
     */
    private function publicStatus(array $flow): TestResponse
    {
        return $this->withHeaders([
            'X-QR-Session' => $flow['session']->uuid,
            'X-QR-Client-Secret' => $flow['secret'],
        ])->getJson(self::STATUS_URL);
    }

    /**
     * @return array{station: Device, session: QrSession, secret: string, payload: array<string, mixed>, order: Order}
     */
    private function checkout(Device $station): array
    {
        $this->checkoutSequence++;
        $secret = 'invariant-secret-'.$this->checkoutSequence;
        $session = QrSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $station->company_id,
            'branch_id' => $station->branch_id,
            'device_id' => $station->getKey(),
            'token' => hash('sha256', (string) Str::uuid()),
            'token_expires_at' => now()->addMinute(),
            'client_secret_hash' => QrSession::hashClientSecret($secret),
            'status' => QrSession::STATUS_ACTIVE,
            'bound_at' => now(),
            'last_seen_at' => now(),
            'expires_at' => now()->addMinutes((int) config('qr.session_lifetime_minutes')),
        ]);
        $payload = [
            'client_request_id' => 'invariant-request-'.$this->checkoutSequence,
            'checkout_choice' => 'machine',
            'phone' => sprintf('90%06d', $this->checkoutSequence),
            'lines' => [[
                'product_id' => 1,
                'qty' => 1,
                'addon_ids' => [],
                'notes' => null,
            ]],
        ];

        $response = $this->postCheckout($session, $secret, $payload)
            ->assertCreated()
            ->assertJsonPath('data.order.status', Order::STATUS_AWAITING_PAYMENT);
        $order = Order::query()->where('uuid', $response->json('data.order.uuid'))->sole();
        $this->assertSame(QrSession::STATUS_ORDERED, $session->fresh()->status);

        return compact('station', 'session', 'secret', 'payload', 'order');
    }

    /**
     * @param  array{station: Device, session: QrSession, secret: string, payload: array<string, mixed>, order: Order}  $flow
     */
    private function replayCheckout(array $flow): TestResponse
    {
        $response = $this->postCheckout($flow['session'], $flow['secret'], $flow['payload']);
        $this->assertSame(
            1,
            Order::query()->where('qr_session_id', $flow['session']->id)->count(),
        );

        return $response;
    }

    /** @param array<string, mixed> $payload */
    private function postCheckout(QrSession $session, string $secret, array $payload): TestResponse
    {
        return $this->withHeaders([
            'X-QR-Session' => $session->uuid,
            'X-QR-Client-Secret' => $secret,
        ])->postJson(self::CHECKOUT_URL, $payload);
    }

    private function device(string $label, int $branchId = 10, string $type = 'payment_station'): Device
    {
        $this->deviceSequence++;

        return Device::factory()->paired('invariant-'.$this->deviceSequence.'-'.$label)->create([
            'company_id' => 100,
            'branch_id' => $branchId,
            'device_type' => $type,
        ]);
    }

    private function getAs(Device $device): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->withToken((string) $device->device_token)->getJson(self::AWAITING_URL);
    }

    /** @return list<string> */
    private function listedUuids(Device $device): array
    {
        return collect($this->getAs($device)->assertOk()->json('data.orders'))
            ->pluck('order_uuid')
            ->map(static fn (mixed $uuid): string => (string) $uuid)
            ->all();
    }

    /** @param list<string> $expected */
    private function assertListed(Device $device, array $expected): void
    {
        $actual = $this->listedUuids($device);
        sort($actual);
        sort($expected);
        $this->assertSame($expected, $actual);
    }

    private function claim(Device $device, Order $order): TestResponse
    {
        return $this->postAs($device, self::CLAIM_URL, [
            'order_uuid' => $order->uuid,
        ]);
    }

    private function release(Device $device, Order $order, string $outcome): TestResponse
    {
        return $this->postAs($device, self::RELEASE_URL, [
            'order_uuid' => $order->uuid,
            'outcome' => $outcome,
        ]);
    }

    private function fallback(Device $device, Order $order): TestResponse
    {
        return $this->postAs($device, self::FALLBACK_URL, [
            'order_uuid' => $order->uuid,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function postAs(Device $device, string $url, array $payload): TestResponse
    {
        app('auth')->forgetGuards();

        return $this->withToken((string) $device->device_token)->postJson($url, $payload);
    }

    /** @param list<array<string, mixed>> $events */
    private function push(Device $device, array $events): TestResponse
    {
        return $this->postAs($device, self::SYNC_URL, ['events' => $events]);
    }

    /**
     * @param  list<array<string, mixed>>  $payments
     * @return array<string, mixed>
     */
    private function payEvent(Order $order, array $payments): array
    {
        return [
            'client_event_id' => $this->nextEventId(),
            'event_type' => 'order.pay',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => $order->uuid,
                'paid_at' => now()->toIso8601String(),
                'payments' => $payments,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function voidEvent(Order $order): array
    {
        return [
            'client_event_id' => $this->nextEventId(),
            'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => $order->uuid,
                'voided_at' => now()->toIso8601String(),
                'reason' => 'P3b Step A invariant recovery',
            ],
        ];
    }

    private function nextEventId(): string
    {
        $this->eventSequence++;

        return sprintf('00000000-0000-4000-8000-%012d', $this->eventSequence);
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

    private function assertNoRoundupMoney(): void
    {
        $this->assertDatabaseCount('pos_roundup_donations', 0);
        $this->assertSame(0, Payment::query()->whereNotNull('roundup_amount')->count());
        Order::query()
            ->whereNotNull('charge_roundup_amount_baisas')
            ->each(fn (Order $order) => $this->assertSame(0, $order->charge_roundup_amount_baisas));
    }

    private function seedCheckoutProduct(): void
    {
        DB::table('pos_products')->insert([
            'id' => 1,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'category_id' => null,
            'name' => 'Invariant checkout product',
            'base_price' => '2.500',
            'stock_mode' => 'untracked',
            'display_order' => 1,
            'status' => 'active',
            'show_on_customer_tablet' => true,
            'is_internal' => false,
            'available_from' => null,
            'available_until' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
    }

    private function enableNumbering(): void
    {
        DB::table('pos_company_settings')->insert([
            'company_id' => 100,
            'key' => 'order_numbering',
            'value' => json_encode([
                'enabled' => true,
                'prefix' => 'INV-',
                'pad' => 4,
                'scope' => 'branch',
                'daily_reset' => false,
            ], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
