<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Floor;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\Payment;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\Table as PosTable;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\TestCase;

/** Seeded, cross-call safety net for the QR2 dine-in lifetime. */
final class QrDineInLifetimeInvariantTest extends TestCase
{
    use RefreshDatabase;

    private const OPEN_TABLE_URL = '/api/v1/device/qr/open-table';

    private const TABLE_BOARD_URL = '/api/v1/device/qr/table-board';

    private const CLEAR_TABLE_URL = '/api/v1/device/qr/clear-table';

    private const REOPEN_URL = '/api/v1/device/qr/reopen-payment';

    private const ROTATE_URL = '/api/v1/device/qr/rotate';

    private const TABLE_MENU_URL = '/api/v1/public/qr/table-menu';

    private const TABLE_BIND_URL = '/api/v1/public/qr/table-bind';

    private const TABLE_ROUND_URL = '/api/v1/public/qr/table-round';

    private const TABLE_FINISH_URL = '/api/v1/public/qr/table-finish';

    private const QUICK_CHECKOUT_URL = '/api/v1/public/qr/checkout';

    private const QR_STATUS_URL = '/api/v1/public/qr/status';

    private const AWAITING_URL = '/api/v1/device/qr/awaiting-orders';

    private const CLAIM_URL = '/api/v1/device/qr/claim-charge';

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

    /** @var array<int, array<string, mixed>> */
    private array $frozenRoundSnapshots = [];

    private int $deviceSequence = 0;

    private int $tableSequence = 0;

    private int $phoneSequence = 0;

    private int $eventSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-30 09:00:00'));
        $this->withoutMiddleware(ThrottleRequests::class);
        config([
            'qr.dine_in_session_lifetime_hours' => 6,
            'qr.session_lifetime_minutes' => 30,
            'qr.charge_claim_seconds' => 180,
            'qr.charge_sweep_grace_seconds' => 30,
            'qr.charge_sweep_enabled' => false,
            'qr.station_geofence_exempt' => false,
        ]);

        $this->seedPricingCatalogue();
        $this->enableOrderNumbering();
    }

    public function test_seating_finish_replay_and_reopen_preserve_the_original_lifetime(): void
    {
        $station = $this->deviceFixture('payment_station', 10, 'seating-lifetime');
        $till = $this->deviceFixture('fixed_pos', 10, 'seating-reopen');
        $flow = $this->paymentFlow($station, 'SEATING-LIFETIME', 'station');
        $seating = $flow['order']->fresh()->tableSession()->sole();
        $openedAt = $seating->getRawOriginal('opened_at');
        $expiresAt = $seating->getRawOriginal('expires_at');
        $this->assertSame('billing', $seating->status);
        $this->assertSame(now()->toIso8601String(), $seating->billing_at->toIso8601String());
        $this->assertNull($seating->closed_at);
        $this->assertNull($seating->close_reason);
        $before = $seating->getRawOriginal();
        $this->travel(10)->minutes();
        $this->finishAtStation($flow)->assertOk();
        $this->assertSame($before, $seating->fresh()->getRawOriginal());
        $this->reopen($till, $flow['order'])->assertOk();
        $seating->refresh();
        $this->assertSame('open', $seating->status);
        $this->assertNull($seating->billing_at);
        $this->assertNull($seating->closed_at);
        $this->assertNull($seating->close_reason);
        $this->assertSame($openedAt, $seating->getRawOriginal('opened_at'));
        $this->assertSame($expiresAt, $seating->getRawOriginal('expires_at'));
        $this->finishAtStation($flow)->assertOk();
        $this->assertSame('billing', $seating->fresh()->status);
        $this->assertSame(now()->toIso8601String(), $seating->fresh()->billing_at->toIso8601String());
        $this->assertSame($expiresAt, $seating->fresh()->getRawOriginal('expires_at'));
        $this->assertSame([
            'opened', 'round_appended', 'customer_order_arrived', 'billing', 'customer_order_arrived',
            'reopened', 'billing', 'customer_order_arrived',
        ], DB::table('pos_table_session_events')->orderBy('id')->pluck('event_type')->all());
    }

    public function test_deleted_station_bill_less_seating_is_superseded_before_its_horizon(): void
    {
        $station = $this->deviceFixture('payment_station', 10, 'superseded-station');
        $replacement = $this->deviceFixture('payment_station', 10, 'superseding-station');
        $flow = $this->openAndBindFlow($station, 'SUPERSEDE-NO-AGE');
        $seating = $flow['session']->tableSession()->sole();
        $openedAt = $seating->getRawOriginal('opened_at');
        $expiresAt = $seating->getRawOriginal('expires_at');
        $station->forceDelete();
        $this->travel(10)->minutes();
        $this->assertTrue($seating->expires_at->isFuture());
        $this->postAs($replacement, self::OPEN_TABLE_URL, ['table_id' => $flow['table']->id])
            ->assertCreated();
        $seating->refresh();
        $this->assertSame('expired', $seating->status);
        $this->assertSame('abandoned', $seating->close_reason);
        $this->assertSame((int) $replacement->id, (int) $seating->closed_by_device_id);
        $this->assertSame(now()->toIso8601String(), $seating->closed_at->toIso8601String());
        $this->assertNull($seating->billing_at);
        $this->assertSame($openedAt, $seating->getRawOriginal('opened_at'));
        $this->assertSame($expiresAt, $seating->getRawOriginal('expires_at'));
        $this->assertSame(2, DB::table('pos_table_sessions')->where('table_id', $flow['table']->id)->count());
        $this->assertSame(1, DB::table('pos_table_sessions')->where('table_id', $flow['table']->id)
            ->whereIn('status', ['open', 'billing', 'closing'])->count());
        $this->assertSame(['opened', 'expired', 'opened'], DB::table('pos_table_session_events')->orderBy('id')->pluck('event_type')->all());
        $expiredEvent = DB::table('pos_table_session_events')->where('event_type', 'expired')->sole();
        $this->assertSame((int) $seating->id, (int) $expiredEvent->table_session_id);
        $this->assertSame((int) $replacement->id, (int) $expiredEvent->device_id);
        $this->assertSame('abandoned', json_decode($expiredEvent->payload, true, flags: JSON_THROW_ON_ERROR)['close_reason']);
    }

    public function test_prune_expires_only_bill_less_or_terminal_seatings_and_keeps_billing_history(): void
    {
        $emptyStation = $this->deviceFixture('payment_station', 10, 'prune-empty');
        $unpaidStation = $this->deviceFixture('payment_station', 10, 'prune-unpaid');
        $terminalStation = $this->deviceFixture('payment_station', 10, 'prune-terminal');
        $empty = $this->openAndBindFlow($emptyStation, 'PRUNE-EMPTY');
        $unpaid = $this->paymentFlow($unpaidStation, 'PRUNE-UNPAID', 'station');
        $terminal = $this->paymentFlow($terminalStation, 'PRUNE-TERMINAL', 'station');
        $emptySeating = $empty['session']->tableSession()->sole();
        $unpaidSeating = $unpaid['order']->fresh()->tableSession()->sole();
        $terminalSeating = $terminal['order']->fresh()->tableSession()->sole();
        // Simulate a historical terminal order whose old seating close was missed.
        $terminal['order']->update(['status' => Order::STATUS_PAID]);
        $billingAt = $terminalSeating->getRawOriginal('billing_at');
        foreach ([$emptyStation, $unpaidStation, $terminalStation] as $station) {
            $station->forceDelete();
        }
        $unpaidBefore = $unpaidSeating->fresh()->getRawOriginal();
        $replacement = $this->deviceFixture('payment_station', 10, 'prune-unpaid-replacement');
        $this->assertTrue($unpaidSeating->expires_at->isFuture());
        $this->postAs($replacement, self::OPEN_TABLE_URL, ['table_id' => $unpaid['table']->id])
            ->assertConflict()->assertJsonPath('errors.0.code', 'qr_table_has_unpaid_order');
        $this->assertSame($unpaidBefore, $unpaidSeating->fresh()->getRawOriginal());
        $this->travel(7)->hours();
        $this->artisan('qr:prune-sessions')
            ->expectsOutput('expired=0 deleted=0 seatings_expired=2')
            ->assertSuccessful();
        foreach ([$emptySeating, $terminalSeating] as $seating) {
            $seating->refresh();
            $this->assertSame('expired', $seating->status);
            $this->assertSame('expired', $seating->close_reason);
            $this->assertNull($seating->closed_by_device_id);
            $this->assertSame(now()->toIso8601String(), $seating->closed_at->toIso8601String());
        }
        $this->assertNull($emptySeating->billing_at);
        $this->assertSame($billingAt, $terminalSeating->getRawOriginal('billing_at'));
        $this->assertSame($unpaidBefore, $unpaidSeating->fresh()->getRawOriginal());
        $this->postAs($replacement, self::OPEN_TABLE_URL, ['table_id' => $unpaid['table']->id])
            ->assertConflict()->assertJsonPath('errors.0.code', 'qr_table_has_unpaid_order');
        $this->assertSame($unpaidBefore, $unpaidSeating->fresh()->getRawOriginal());
        $expiredBefore = $terminalSeating->getRawOriginal();
        $this->travel(1)->hours();
        $this->artisan('qr:prune-sessions')
            ->expectsOutput('expired=0 deleted=0 seatings_expired=0')->assertSuccessful();
        $this->assertSame($expiredBefore, $terminalSeating->fresh()->getRawOriginal());
        $this->assertDatabaseCount('pos_table_sessions', 3);
        $this->assertDatabaseCount('pos_table_session_events', 13);
        $this->assertSame([
            'opened', 'opened', 'round_appended', 'customer_order_arrived', 'billing', 'customer_order_arrived',
            'opened', 'round_appended', 'customer_order_arrived', 'billing', 'customer_order_arrived', 'expired', 'expired',
        ], DB::table('pos_table_session_events')->orderBy('id')->pluck('event_type')->all());
    }

    #[DataProvider('randomSeeds')]
    public function test_seeded_round_interleavings_preserve_the_frozen_three_way_ledger(
        int $seed,
    ): void {
        $random = new Randomizer(new Mt19937($seed));
        $station = $this->deviceFixture('payment_station', 10, 'random-open-'.$seed);
        $till = $this->deviceFixture('fixed_pos', 10, 'random-till-'.$seed);
        $flow = $this->openAndBindFlow($station, 'RANDOM-'.$seed);
        $firstPayload = $this->roundPayload(
            requestId: 'seed-'.$seed.'-round-1',
            productId: 1,
            firstRound: true,
            addonIds: [1],
        );

        $first = $this->submitRound($flow, $firstPayload)
            ->assertCreated()
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.round.round_no', 1)
            ->assertJsonPath('data.round.status', QrOrderRound::STATUS_ACCEPTED);
        $order = Order::query()->where('uuid', $first->json('data.order.uuid'))->sole();
        $this->assertGreaterThan(0, Money::toBaisas($order->discount_total));
        $this->assertLifetimeLedger($order, "seed={$seed} first round");

        $quick = $this->quickCheckout($station, 'seed-'.$seed.'-quick');
        $quickPending = $this->quickPendingSession($station);
        $this->assertQuickProjectionIsUnchanged($station, $quick['order']);
        $this->assertLifetimeLedger($order, "seed={$seed} quick interleave");

        $operations = $random->shuffleArray([
            'table_menu',
            'duplicate_round',
            'rebind',
            'wrong_secret',
            'rotate_storm',
            'table_board',
        ]);

        foreach ($operations as $step => $operation) {
            $context = "seed={$seed} step={$step} op={$operation}";

            if ($operation === 'table_menu') {
                $this->getJson(self::TABLE_MENU_URL.'?t='.urlencode((string) $flow['table']->qr_token))
                    ->assertOk()
                    ->assertJsonMissingPath('data.session_uuid')
                    ->assertJsonMissingPath('data.expires_at');
            } elseif ($operation === 'duplicate_round') {
                $before = $order->fresh()->getRawOriginal();
                $this->submitRound($flow, $firstPayload)
                    ->assertCreated()
                    ->assertJsonPath('data.replayed', true)
                    ->assertJsonPath('data.round.id', $first->json('data.round.id'));
                $this->assertSame($before, $order->fresh()->getRawOriginal(), $context);
                $this->assertSame(
                    1,
                    QrOrderRound::query()->where('qr_session_id', $flow['session']->id)->count(),
                    $context,
                );
            } elseif ($operation === 'rebind') {
                $oldSecret = $flow['secret'];
                $candidateSecret = 'replacement-'.$seed;
                $this->postJson(self::TABLE_BIND_URL, [
                    'table_token' => $flow['table']->qr_token,
                    'client_secret' => $candidateSecret,
                ])->assertOk()->assertJsonPath('data.read_only', true);
                $this->sendQrStatus($flow['session'], $oldSecret)
                    ->assertOk()->assertJsonPath('data.order.uuid', $order->uuid);
                $this->sendQrStatus($flow['session'], $candidateSecret)
                    ->assertNotFound()
                    ->assertJsonPath('errors.0.code', 'qr_session_not_found');
            } elseif ($operation === 'wrong_secret') {
                $this->withHeaders($this->qrHeaders($flow['session'], 'wrong-browser-secret'))
                    ->postJson(self::TABLE_ROUND_URL, [
                        'client_request_id' => 'must-not-land-'.$seed,
                        'lines' => $this->linesFor(2),
                    ])->assertNotFound()
                    ->assertJsonPath('errors.0.code', 'qr_session_not_found');
            } elseif ($operation === 'rotate_storm') {
                for ($attempt = 0; $attempt < 3; $attempt++) {
                    $this->postAs($station, self::ROTATE_URL)->assertOk();
                }
                $this->assertSame(QrSession::STATUS_ACTIVE, $flow['session']->fresh()->status);
                $this->assertSame(QrSession::STATUS_EXPIRED, $quickPending->fresh()->status);
            } else {
                $row = collect($this->boardRows($till))
                    ->firstWhere('table_id', (int) $flow['table']->id);
                $this->assertIsArray($row, $context);
                $this->assertSame($order->uuid, $row['order']['uuid'] ?? null, $context);
            }

            $this->assertLifetimeLedger($order, $context);
        }

        $firstRoundBefore = $this->roundSnapshot(
            QrOrderRound::query()->whereKey((int) $first->json('data.round.id'))->sole(),
        );
        $this->travel(2)->minutes();
        DB::table('pos_products')->where('id', 1)->update([
            'status' => 'inactive',
            'base_price' => '99.999',
            'updated_at' => now(),
        ]);
        DB::table('pos_branch_product')
            ->where('branch_id', 10)
            ->where('product_id', 1)
            ->update(['is_available' => false, 'updated_at' => now()]);

        $beforeUnavailable = [
            'order' => $order->fresh()->getRawOriginal(),
            'rounds' => QrOrderRound::query()->where('order_id', $order->id)->count(),
            'items' => OrderItem::query()->where('order_id', $order->id)->count(),
            'addons' => OrderItemAddon::query()
                ->whereIn('order_item_id', OrderItem::query()->where('order_id', $order->id)->pluck('id'))
                ->count(),
            'discounts' => OrderDiscount::query()->where('order_id', $order->id)->count(),
        ];
        $this->submitRound($flow, $this->roundPayload(
            requestId: 'seed-'.$seed.'-unavailable',
            productId: 1,
            firstRound: false,
        ))->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'product_unavailable');
        $this->assertSame($beforeUnavailable['order'], $order->fresh()->getRawOriginal());
        $this->assertSame(
            $beforeUnavailable['rounds'],
            QrOrderRound::query()->where('order_id', $order->id)->count(),
        );
        $this->assertSame($beforeUnavailable['items'], OrderItem::query()->where('order_id', $order->id)->count());
        $this->assertSame(
            $beforeUnavailable['addons'],
            OrderItemAddon::query()
                ->whereIn('order_item_id', OrderItem::query()->where('order_id', $order->id)->pluck('id'))
                ->count(),
        );
        $this->assertSame($beforeUnavailable['discounts'], OrderDiscount::query()->where('order_id', $order->id)->count());
        $this->assertLifetimeLedger($order, "seed={$seed} unavailable call rolled back");

        $secondPayload = $this->roundPayload(
            requestId: 'seed-'.$seed.'-round-2',
            productId: 2,
            firstRound: false,
        );
        $second = $this->submitRound($flow, $secondPayload)
            ->assertCreated()
            ->assertJsonPath('data.replayed', false)
            ->assertJsonPath('data.round.round_no', 2);
        $this->assertSame(
            $firstRoundBefore,
            $this->roundSnapshot(QrOrderRound::query()->whereKey((int) $first->json('data.round.id'))->sole()),
            "seed={$seed} catalogue changes altered round 1",
        );
        $this->assertSame(0, (int) data_get($second->json(), 'data.round.priced_lines.0.line_discount_baisas'));
        $this->assertLifetimeLedger($order, "seed={$seed} later round after catalogue change");

        $beforeReplay = $order->fresh()->getRawOriginal();
        $this->submitRound($flow, $secondPayload)
            ->assertCreated()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.round.id', $second->json('data.round.id'));
        $this->assertSame($beforeReplay, $order->fresh()->getRawOriginal());
        $this->assertLifetimeLedger($order, "seed={$seed} second replay");

        $this->exerciseZeroRoundFinishArm($station, 'ZERO-'.$seed);
        $this->assertNoQrRoundup();
    }

    /** @return array<string, array{int}> */
    public static function randomSeeds(): array
    {
        return [
            'seed 0xD1E1' => [0xD1E1],
            'seed 0xB41A5' => [0xB41A5],
        ];
    }

    #[DataProvider('randomSeeds')]
    public function test_seeded_full_alphabet_preserves_lifetime_invariants_after_every_call(
        int $seed,
    ): void {
        $random = new Randomizer(new Mt19937($seed));
        $devices = [
            'station_a' => $this->deviceFixture('payment_station', 10, 'alphabet-a-'.$seed),
            'station_b' => $this->deviceFixture('payment_station', 10, 'alphabet-b-'.$seed),
            'wrong_branch' => $this->deviceFixture('payment_station', 20, 'alphabet-wrong-'.$seed),
            'till' => $this->deviceFixture('fixed_pos', 10, 'alphabet-till-'.$seed),
        ];
        $orders = [];
        $sessions = [];

        // Each token owns an independent flow, so shuffling the alphabet never
        // manufactures an invalid precondition. The calls within a token retain
        // their causal order; actors and reopen-state order are also seeded.
        $alphabet = $random->shuffleArray([
            'open_rotate_menu_bind_rebind_quick',
            'round_replay_freeze_wrong_session_branch_station_pay',
            'finish_counter_till_pay',
            'horizon_lazy_touch_till_pay_open_clear',
            'reopen_every_charge_state_claim_clock_sweeper',
            'finish_with_zero_rounds',
        ]);

        foreach ($alphabet as $step => $operation) {
            $context = "full-alphabet seed={$seed} step={$step} op={$operation}";
            match ($operation) {
                'open_rotate_menu_bind_rebind_quick' => $this->runRotationBindQuickAlphabet(
                    $devices,
                    $orders,
                    $sessions,
                    $context,
                ),
                'round_replay_freeze_wrong_session_branch_station_pay' => $this->runRoundAndStationAlphabet(
                    $random,
                    $devices,
                    $orders,
                    $sessions,
                    $context,
                ),
                'finish_counter_till_pay' => $this->runCounterAlphabet(
                    $devices,
                    $orders,
                    $sessions,
                    $context,
                ),
                'horizon_lazy_touch_till_pay_open_clear' => $this->runHorizonAlphabet(
                    $devices,
                    $orders,
                    $sessions,
                    $context,
                ),
                'reopen_every_charge_state_claim_clock_sweeper' => $this->runReopenAlphabet(
                    $random,
                    $devices,
                    $orders,
                    $sessions,
                    $context,
                ),
                default => $this->runZeroRoundAlphabet(
                    $devices,
                    $orders,
                    $sessions,
                    $context,
                ),
            };
        }

        $this->assertTrackedLifetimeInvariants($orders, $sessions, "full-alphabet seed={$seed} final");
    }

    public function test_branch_payment_reopen_and_terminal_calls_preserve_lifetime_invariants(): void
    {
        $opener = $this->deviceFixture('payment_station', 10, 'protocol-opener');
        $claimant = $this->deviceFixture('payment_station', 10, 'protocol-claimant');
        $wrongBranch = $this->deviceFixture('payment_station', 20, 'protocol-wrong-branch');
        $till = $this->deviceFixture('fixed_pos', 10, 'protocol-till');
        $flow = $this->openAndBindFlow($opener, 'PROTOCOL');
        $round = $this->submitRound($flow, $this->roundPayload(
            requestId: 'protocol-round-1',
            productId: 2,
            firstRound: true,
        ))->assertCreated();
        $order = Order::query()->where('uuid', $round->json('data.order.uuid'))->sole();
        $this->finishAtStation($flow)->assertOk();
        $this->assertLifetimeLedger($order, 'protocol finish station');

        foreach ([$opener, $claimant] as $station) {
            $row = collect($this->awaitingRows($station))->firstWhere('order_uuid', $order->uuid);
            $this->assertIsArray($row);
            $this->assertSame($flow['table']->label, $row['table_label'] ?? null);
        }
        $this->assertNull(
            collect($this->awaitingRows($wrongBranch))->firstWhere('order_uuid', $order->uuid),
        );
        $this->claim($wrongBranch, $order)
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'order_not_found');

        $this->claim($claimant, $order)
            ->assertOk()
            ->assertJsonPath('data.already_claimed_by_this_device', false);
        $this->claim($opener, $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_already_claimed');
        foreach ([$opener, $claimant] as $station) {
            $this->assertNull(
                collect($this->awaitingRows($station))->firstWhere('order_uuid', $order->uuid),
            );
        }
        $this->assertLifetimeLedger($order, 'protocol exclusive claim');

        $this->release($claimant, $order, Order::CHARGE_OUTCOME_DECLINED)
            ->assertOk()
            ->assertJsonPath('data.charge_outcome', Order::CHARGE_OUTCOME_DECLINED);
        foreach ([$opener, $claimant] as $station) {
            $this->assertNotNull(
                collect($this->awaitingRows($station))->firstWhere('order_uuid', $order->uuid),
            );
        }

        $this->fallback($claimant, $order)
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_HELD);
        $this->assertChargeFieldsNull($order->fresh());
        $this->reopen($till, $order)
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_OPEN)
            ->assertJsonPath('data.session_status', QrSession::STATUS_ACTIVE);
        $this->assertChargeFieldsNull($order->fresh());
        $this->assertLifetimeLedger($order, 'protocol held reopen');

        $this->submitRound($flow, $this->roundPayload(
            requestId: 'protocol-round-2',
            productId: 2,
            firstRound: false,
        ))->assertCreated();
        $this->assertLifetimeLedger($order, 'protocol appended after reopen');
        $this->finishAtStation($flow)->assertOk();
        $this->claim($claimant, $order)->assertOk();
        $this->assertSyncProcessed($this->push($claimant, [
            $this->paymentEvent($order, Payment::METHOD_CARD),
        ]));

        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $closed = $flow['session']->fresh();
        $this->assertSame(QrSession::STATUS_CLOSED, $closed->status);
        $this->assertNotNull($closed->closed_at);
        $this->assertSame(Order::CHARGE_OUTCOME_APPROVED, $order->fresh()->charge_outcome);
        $this->assertLifetimeLedger($order, 'protocol station terminal');

        $roundCount = QrOrderRound::query()->where('qr_session_id', $flow['session']->id)->count();
        $orderCount = Order::query()->where('qr_session_id', $flow['session']->id)->count();
        $this->submitRound($flow, $this->roundPayload(
            requestId: 'must-never-land-after-close',
            productId: 2,
            firstRound: false,
        ))->assertNotFound()
            ->assertJsonPath('errors.0.code', 'qr_session_not_found');
        $this->assertSame(
            $roundCount,
            QrOrderRound::query()->where('qr_session_id', $flow['session']->id)->count(),
        );
        $this->assertSame(
            $orderCount,
            Order::query()->where('qr_session_id', $flow['session']->id)->count(),
        );
        $this->postJson(self::TABLE_BIND_URL, [
            'table_token' => $flow['table']->qr_token,
            'client_secret' => 'closed-rescan',
        ])->assertNotFound()
            ->assertJsonPath('errors.0.code', 'qr_bind_failed');
        $this->clearTable($till, $flow['table'])->assertOk();
        $this->postAs($opener, self::OPEN_TABLE_URL, ['table_id' => $flow['table']->id])
            ->assertCreated();
        $this->assertNoQrRoundup();
    }

    public function test_horizon_orphan_is_boarded_then_till_paid_closed_cleared_and_reopened(): void
    {
        $station = $this->deviceFixture('payment_station', 10, 'horizon-station');
        $till = $this->deviceFixture('fixed_pos', 10, 'horizon-till');
        $flow = $this->openAndBindFlow($station, 'HORIZON');
        $created = $this->submitRound($flow, $this->roundPayload(
            requestId: 'horizon-round-1',
            productId: 2,
            firstRound: true,
        ))->assertCreated();
        $order = Order::query()->where('uuid', $created->json('data.order.uuid'))->sole();
        $this->assertSame(Order::STATUS_OPEN, $order->status);
        $this->assertSame(QrSession::STATUS_ACTIVE, $flow['session']->fresh()->status);

        $this->travelTo($flow['session']->fresh()->expires_at->copy()->addSecond());
        $this->sendQrStatus($flow['session'], $flow['secret'])
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'qr_session_not_found');
        $this->assertSame(QrSession::STATUS_EXPIRED, $flow['session']->fresh()->status);
        $row = collect($this->boardRows($till))
            ->firstWhere('table_id', (int) $flow['table']->id);
        $this->assertIsArray($row);
        $this->assertTrue((bool) ($row['orphaned'] ?? false));
        $this->assertSame($order->uuid, $row['order']['uuid'] ?? null);

        $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $flow['table']->id])
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_table_has_unpaid_order');
        $this->assertLifetimeLedger($order, 'horizon orphan guard');

        $this->assertSyncProcessed($this->push($till, [
            $this->paymentEvent($order, Payment::METHOD_CASH),
        ]));
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
        $this->assertSame(QrSession::STATUS_CLOSED, $flow['session']->fresh()->status);
        $this->assertNotNull($flow['session']->fresh()->closed_at);
        $this->assertLifetimeLedger($order, 'horizon till-pay-open terminal');

        $this->clearTable($till, $flow['table'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cleared');
        $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $flow['table']->id])
            ->assertCreated();
        $this->assertNoQrRoundup();
    }

    public function test_reopen_matrix_is_fail_closed_and_allowed_reopens_complete_payment(): void
    {
        $station = $this->deviceFixture('payment_station', 10, 'matrix-station');
        $till = $this->deviceFixture('fixed_pos', 10, 'matrix-till');

        foreach (['never_claimed', 'declined', 'cancelled', 'held'] as $state) {
            $flow = $this->paymentFlow(
                $station,
                'allowed-'.$state,
                $state === 'held' ? 'counter' : 'station',
            );
            $order = $flow['order'];

            if ($state === 'declined' || $state === 'cancelled') {
                $outcome = $state === 'declined'
                    ? Order::CHARGE_OUTCOME_DECLINED
                    : Order::CHARGE_OUTCOME_CANCELLED;
                $this->claim($station, $order)->assertOk();
                $this->release($station, $order, $outcome)->assertOk();
            }

            $this->reopen($till, $order)
                ->assertOk()
                ->assertJsonPath('data.status', Order::STATUS_OPEN)
                ->assertJsonPath('data.session_status', QrSession::STATUS_ACTIVE);
            $this->assertChargeFieldsNull($order->fresh());
            $this->assertLifetimeLedger($order, 'allowed reopen '.$state);

            $this->finishAtStation($flow)->assertOk();
            $this->claim($station, $order)->assertOk();
            $this->assertSyncProcessed($this->push($station, [
                $this->paymentEvent($order, Payment::METHOD_CARD),
            ]));
            $this->assertSame(Order::STATUS_PAID, $order->fresh()->status, $state);
            $this->assertSame(QrSession::STATUS_CLOSED, $flow['session']->fresh()->status, $state);
            $this->assertLifetimeLedger($order, 'allowed reopen terminal '.$state);
        }

        foreach (['live', 'lapsed', 'uncertain', 'expired_unresolved', 'approved'] as $state) {
            $flow = $this->paymentFlow($station, 'refused-'.$state, 'station');
            $order = $flow['order'];
            $this->claim($station, $order)->assertOk();

            if ($state === 'lapsed') {
                $this->travelTo($order->fresh()->charge_deadline_at->copy()->addSeconds(31));
                $this->artisan('qr:sweep-stale-charges')->assertSuccessful();
                $this->assertSame(Order::CHARGE_OUTCOME_LAPSED, $order->fresh()->charge_outcome);
            } elseif ($state === 'uncertain') {
                $this->release($station, $order, Order::CHARGE_OUTCOME_UNCERTAIN)->assertOk();
            } elseif ($state === 'expired_unresolved') {
                $this->travelTo($order->fresh()->charge_deadline_at->copy()->addSecond());
                $this->assertNull($order->fresh()->charge_outcome);
            } elseif ($state === 'approved') {
                $this->assertSyncProcessed($this->push($station, [
                    $this->paymentEvent($order, Payment::METHOD_CARD),
                ]));
                $this->assertSame(Order::CHARGE_OUTCOME_APPROVED, $order->fresh()->charge_outcome);
            }

            $before = $this->chargeProvenance($order->fresh());
            $response = $this->reopen($till, $order)->assertConflict();
            $this->assertContains($response->json('errors.0.code'), [
                'qr_charge_recovery_required',
                'qr_session_not_ordered',
            ], $state);
            $this->assertSame($before, $this->chargeProvenance($order->fresh()), $state);
            $this->assertLifetimeLedger($order, 'refused reopen '.$state);
        }

        $this->assertNoQrRoundup();
    }

    /** @param array<string, Device> $devices */
    private function runRoundAndStationAlphabet(
        Randomizer $random,
        array $devices,
        array &$orders,
        array &$sessions,
        string $context,
    ): void {
        $flow = $this->trackedOpenAndBindFlow(
            $devices['station_a'],
            'ALPHABET-STATION-'.$this->tableSequence,
            $orders,
            $sessions,
            $context,
        );
        $firstPayload = $this->roundPayload(
            requestId: 'alphabet-station-first-'.$this->tableSequence,
            productId: 2,
            firstRound: true,
        );
        $first = $this->submitRound($flow, $firstPayload)->assertCreated();
        $order = Order::query()->where('uuid', $first->json('data.order.uuid'))->sole();
        $orders[(int) $order->id] = $order;
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' valid round');

        $beforeReplay = $order->fresh()->getRawOriginal();
        $this->submitRound($flow, $firstPayload)
            ->assertCreated()
            ->assertJsonPath('data.replayed', true);
        $this->assertSame($beforeReplay, $order->fresh()->getRawOriginal());
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' duplicate round');

        $decoy = $this->trackedOpenAndBindFlow(
            $devices['station_a'],
            'ALPHABET-DECOY-'.$this->tableSequence,
            $orders,
            $sessions,
            $context.' wrong-session setup',
        );
        $this->withHeaders([
            'X-QR-Session' => $flow['session']->uuid,
            'X-QR-Client-Secret' => $decoy['secret'],
        ])->postJson(self::TABLE_ROUND_URL, [
            'client_request_id' => 'alphabet-wrong-session-'.$this->tableSequence,
            'lines' => $this->linesFor(1),
        ])->assertNotFound()
            ->assertJsonPath('errors.0.code', 'qr_session_not_found');
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' wrong-session round');
        $this->clearTable($devices['till'], $decoy['table'])->assertOk();
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' clear decoy');

        $firstRound = QrOrderRound::query()->whereKey((int) $first->json('data.round.id'))->sole();
        $frozenBefore = $this->roundSnapshot($firstRound);
        DB::table('pos_products')->where('id', 2)->update([
            'base_price' => '9.875',
            'updated_at' => now(),
        ]);
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' catalogue changed');
        $this->submitRound($flow, $this->roundPayload(
            requestId: 'alphabet-after-freeze-'.$this->tableSequence,
            productId: 1,
            firstRound: false,
        ))->assertCreated();
        $this->assertSame($frozenBefore, $this->roundSnapshot($firstRound->fresh()));
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' after-freeze round');

        $this->finishAtStation($flow)->assertOk();
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' finish station');
        foreach (['station_a', 'station_b', 'wrong_branch'] as $key) {
            $rows = $this->awaitingRows($devices[$key]);
            if ($key === 'wrong_branch') {
                $this->assertNull(collect($rows)->firstWhere('order_uuid', $order->uuid));
            } else {
                $this->assertNotNull(collect($rows)->firstWhere('order_uuid', $order->uuid));
            }
            $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' awaiting '.$key);
        }
        $this->claim($devices['wrong_branch'], $order)->assertNotFound();
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' wrong-branch claim');

        $claimantKey = $random->getInt(0, 1) === 0 ? 'station_a' : 'station_b';
        $loserKey = $claimantKey === 'station_a' ? 'station_b' : 'station_a';
        $claimant = $devices[$claimantKey];
        $this->claim($claimant, $order)
            ->assertOk()
            ->assertJsonPath('data.already_claimed_by_this_device', false);
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' branch claim winner');
        $this->claim($devices[$loserKey], $order)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'charge_already_claimed');
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' branch claim loser');

        $this->release($claimant, $order, Order::CHARGE_OUTCOME_DECLINED)->assertOk();
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' release declined');
        $this->fallback($claimant, $order)
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_HELD);
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' branch fallback');
        $this->reopen($devices['till'], $order)->assertOk();
        $this->assertChargeFieldsNull($order->fresh());
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' held reopen');

        $this->submitRound($flow, $this->roundPayload(
            requestId: 'alphabet-post-reopen-'.$this->tableSequence,
            productId: 1,
            firstRound: false,
        ))->assertCreated();
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' post-reopen round');
        $this->finishAtStation($flow)->assertOk();
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' second station finish');
        $this->claim($claimant, $order)->assertOk();
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' second charge claim');
        $this->assertSyncProcessed($this->push($claimant, [
            $this->paymentEvent($order, Payment::METHOD_CARD),
        ]));
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' station pay');
        $this->clearTable($devices['till'], $flow['table'])->assertOk();
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' clear station flow');
    }

    /** @param array<string, Device> $devices */
    private function runCounterAlphabet(
        array $devices,
        array &$orders,
        array &$sessions,
        string $context,
    ): void {
        $flow = $this->trackedOpenAndBindFlow(
            $devices['station_a'],
            'ALPHABET-COUNTER-'.$this->tableSequence,
            $orders,
            $sessions,
            $context,
        );
        $order = $this->trackedFirstRound(
            $flow,
            1,
            'alphabet-counter-'.$this->tableSequence,
            $orders,
            $sessions,
            $context,
        );
        $this->finishFlow($flow, 'counter')
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_HELD);
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' finish counter');
        $this->assertSyncProcessed($this->push($devices['till'], [
            $this->paymentEvent($order, Payment::METHOD_CASH),
        ]));
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' till pay held');
        $this->clearTable($devices['till'], $flow['table'])->assertOk();
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' clear counter flow');
    }

    /** @param array<string, Device> $devices */
    private function runHorizonAlphabet(
        array $devices,
        array &$orders,
        array &$sessions,
        string $context,
    ): void {
        $flow = $this->trackedOpenAndBindFlow(
            $devices['station_a'],
            'ALPHABET-HORIZON-'.$this->tableSequence,
            $orders,
            $sessions,
            $context,
        );
        $order = $this->trackedFirstRound(
            $flow,
            1,
            'alphabet-horizon-'.$this->tableSequence,
            $orders,
            $sessions,
            $context,
        );
        $this->travelTo($flow['session']->fresh()->expires_at->copy()->addSecond());
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' six-hour clock crossed');
        $this->sendQrStatus($flow['session'], $flow['secret'])
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'qr_session_not_found');
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' lazy horizon touch');

        $row = collect($this->boardRows($devices['till']))
            ->firstWhere('table_id', (int) $flow['table']->id);
        $this->assertTrue((bool) ($row['orphaned'] ?? false));
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' orphan board');
        $this->postAs($devices['station_a'], self::OPEN_TABLE_URL, ['table_id' => $flow['table']->id])
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_table_has_unpaid_order');
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' orphan double-open guard');

        $this->assertSyncProcessed($this->push($devices['till'], [
            $this->paymentEvent($order, Payment::METHOD_CASH),
        ]));
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' till pay open');
        $this->clearTable($devices['till'], $flow['table'])->assertOk();
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' clear orphan');

        $opened = $this->postAs(
            $devices['station_a'],
            self::OPEN_TABLE_URL,
            ['table_id' => $flow['table']->id],
        )->assertCreated();
        $newSession = QrSession::query()->where('uuid', $opened->json('data.session_uuid'))->sole();
        $sessions[(int) $newSession->id] = $newSession;
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' reopen cleared table');
        $this->clearTable($devices['till'], $flow['table'])->assertOk();
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' clear reopened empty table');
    }

    /** @param array<string, Device> $devices */
    private function runReopenAlphabet(
        Randomizer $random,
        array $devices,
        array &$orders,
        array &$sessions,
        string $context,
    ): void {
        $states = $random->shuffleArray([
            'never_claimed',
            'declined',
            'cancelled',
            'held',
            'live',
            'lapsed',
            'uncertain',
            'expired_unresolved',
            'approved',
        ]);

        foreach ($states as $index => $state) {
            $stateContext = $context.' state='.$state.' index='.$index;
            $flow = $this->trackedOpenAndBindFlow(
                $devices['station_a'],
                'ALPHABET-REOPEN-'.strtoupper($state).'-'.$this->tableSequence,
                $orders,
                $sessions,
                $stateContext,
            );
            $order = $this->trackedFirstRound(
                $flow,
                1,
                'alphabet-reopen-'.$state.'-'.$this->tableSequence,
                $orders,
                $sessions,
                $stateContext,
            );
            $choice = $state === 'held' ? 'counter' : 'station';
            $this->finishFlow($flow, $choice)->assertOk();
            $this->assertTrackedLifetimeInvariants(
                $orders,
                $sessions,
                $stateContext.' finish '.$choice,
            );

            if (in_array($state, [
                'declined',
                'cancelled',
                'live',
                'lapsed',
                'uncertain',
                'expired_unresolved',
                'approved',
            ], true)) {
                $this->claim($devices['station_a'], $order)->assertOk();
                $this->assertTrackedLifetimeInvariants(
                    $orders,
                    $sessions,
                    $stateContext.' claim',
                );
            }

            if ($state === 'declined' || $state === 'cancelled') {
                $outcome = $state === 'declined'
                    ? Order::CHARGE_OUTCOME_DECLINED
                    : Order::CHARGE_OUTCOME_CANCELLED;
                $this->release($devices['station_a'], $order, $outcome)->assertOk();
                $this->assertTrackedLifetimeInvariants(
                    $orders,
                    $sessions,
                    $stateContext.' safe release',
                );
            } elseif ($state === 'lapsed') {
                $this->travelTo($order->fresh()->charge_deadline_at->copy()->addSeconds(31));
                $this->assertTrackedLifetimeInvariants(
                    $orders,
                    $sessions,
                    $stateContext.' claim deadline and grace crossed',
                );
                $this->artisan('qr:sweep-stale-charges')->assertSuccessful();
                $this->assertSame(Order::CHARGE_OUTCOME_LAPSED, $order->fresh()->charge_outcome);
                $this->assertTrackedLifetimeInvariants(
                    $orders,
                    $sessions,
                    $stateContext.' sweeper',
                );
            } elseif ($state === 'uncertain') {
                $this->release(
                    $devices['station_a'],
                    $order,
                    Order::CHARGE_OUTCOME_UNCERTAIN,
                )->assertOk();
                $this->assertTrackedLifetimeInvariants(
                    $orders,
                    $sessions,
                    $stateContext.' uncertain release',
                );
            } elseif ($state === 'expired_unresolved') {
                $this->travelTo($order->fresh()->charge_deadline_at->copy()->addSecond());
                $this->assertNull($order->fresh()->charge_outcome);
                $this->assertTrackedLifetimeInvariants(
                    $orders,
                    $sessions,
                    $stateContext.' claim deadline crossed',
                );
            } elseif ($state === 'approved') {
                $this->assertSyncProcessed($this->push($devices['station_a'], [
                    $this->paymentEvent($order, Payment::METHOD_CARD),
                ]));
                $this->assertTrackedLifetimeInvariants(
                    $orders,
                    $sessions,
                    $stateContext.' approved station pay',
                );
            }

            $allowed = in_array($state, [
                'never_claimed',
                'declined',
                'cancelled',
                'held',
            ], true);
            $before = $this->chargeProvenance($order->fresh());
            $reopen = $this->reopen($devices['till'], $order);
            if ($allowed) {
                $reopen
                    ->assertOk()
                    ->assertJsonPath('data.status', Order::STATUS_OPEN)
                    ->assertJsonPath('data.session_status', QrSession::STATUS_ACTIVE);
                $this->assertChargeFieldsNull($order->fresh());
            } else {
                $reopen->assertConflict();
                $this->assertContains($reopen->json('errors.0.code'), [
                    'qr_charge_recovery_required',
                    'qr_session_not_ordered',
                ]);
                $this->assertSame($before, $this->chargeProvenance($order->fresh()));
            }
            $this->assertTrackedLifetimeInvariants(
                $orders,
                $sessions,
                $stateContext.' reopen attempt',
            );

            if ($allowed) {
                $this->finishAtStation($flow)->assertOk();
                $this->assertTrackedLifetimeInvariants(
                    $orders,
                    $sessions,
                    $stateContext.' finish after reopen',
                );
                $this->claim($devices['station_b'], $order)->assertOk();
                $this->assertTrackedLifetimeInvariants(
                    $orders,
                    $sessions,
                    $stateContext.' second full claim',
                );
                $this->assertSyncProcessed($this->push($devices['station_b'], [
                    $this->paymentEvent($order, Payment::METHOD_CARD),
                ]));
                $this->assertTrackedLifetimeInvariants(
                    $orders,
                    $sessions,
                    $stateContext.' second full payment',
                );
            } elseif ($state !== 'approved') {
                if ($state === 'live') {
                    $this->release(
                        $devices['station_a'],
                        $order,
                        Order::CHARGE_OUTCOME_DECLINED,
                    )->assertOk();
                    $this->assertTrackedLifetimeInvariants(
                        $orders,
                        $sessions,
                        $stateContext.' cleanup release',
                    );
                    $this->fallback($devices['station_a'], $order)->assertOk();
                } else {
                    $this->fallback($devices['till'], $order)->assertOk();
                }
                $this->assertTrackedLifetimeInvariants(
                    $orders,
                    $sessions,
                    $stateContext.' attended fallback',
                );
                $this->assertSyncProcessed($this->push($devices['till'], [
                    $this->paymentEvent($order, Payment::METHOD_CASH),
                ]));
                $this->assertTrackedLifetimeInvariants(
                    $orders,
                    $sessions,
                    $stateContext.' attended settlement',
                );
            }

            $this->clearTable($devices['till'], $flow['table'])->assertOk();
            $this->assertTrackedLifetimeInvariants(
                $orders,
                $sessions,
                $stateContext.' clear terminal table',
            );
        }
    }

    /** @param array<string, Device> $devices */
    private function runRotationBindQuickAlphabet(
        array $devices,
        array &$orders,
        array &$sessions,
        string $context,
    ): void {
        $station = $devices['station_a'];
        $table = $this->tableFixture('ALPHABET-ROTATE-'.$this->tableSequence);
        $opened = $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $table->id])
            ->assertCreated();
        $session = QrSession::query()->where('uuid', $opened->json('data.session_uuid'))->sole();
        $sessions[(int) $session->id] = $session;
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' open-table pending');

        $quickPending = $this->quickPendingSession($station);
        $this->travelTo($quickPending->token_expires_at->copy()->addSecond());
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' rotation clock crossed');
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postAs($station, self::ROTATE_URL)->assertOk();
            $this->assertSame(QrSession::STATUS_PENDING, $session->fresh()->status);
            $this->assertSame(QrSession::STATUS_EXPIRED, $quickPending->fresh()->status);
            $this->assertTrackedLifetimeInvariants(
                $orders,
                $sessions,
                $context.' rotate storm '.$attempt,
            );
        }

        $this->getJson(self::TABLE_MENU_URL.'?t='.urlencode((string) $table->qr_token))
            ->assertOk()
            ->assertJsonMissingPath('data.session_uuid');
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' sessionless menu');

        $firstSecret = 'alphabet-first-'.$this->tableSequence;
        $this->postJson(self::TABLE_BIND_URL, [
            'table_token' => $table->qr_token,
            'client_secret' => $firstSecret,
        ])->assertOk();
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' bind');

        $secondSecret = 'alphabet-rebind-'.$this->tableSequence;
        $this->postJson(self::TABLE_BIND_URL, [
            'table_token' => $table->qr_token,
            'client_secret' => $secondSecret,
        ])->assertOk()->assertJsonPath('data.read_only', true);
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' rebind');
        $this->sendQrStatus($session, $firstSecret)
            ->assertOk();
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' owner retained');

        $quick = $this->quickCheckout($station, 'alphabet-quick-'.$this->phoneSequence);
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' quick checkout');
        $this->assertQuickProjectionIsUnchanged($station, $quick['order']);
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' quick projection');

        $this->clearTable($devices['till'], $table)->assertOk();
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' clear empty table');
    }

    /** @param array<string, Device> $devices */
    private function runZeroRoundAlphabet(
        array $devices,
        array &$orders,
        array &$sessions,
        string $context,
    ): void {
        $flow = $this->trackedOpenAndBindFlow(
            $devices['station_a'],
            'ALPHABET-ZERO-'.$this->tableSequence,
            $orders,
            $sessions,
            $context,
        );
        $this->finishAtStation($flow)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_dine_in_order_required');
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' finish zero rounds');
        $this->clearTable($devices['till'], $flow['table'])->assertOk();
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' clear zero flow');
    }

    /**
     * @param  array<int, Order>  $orders
     * @param  array<int, QrSession>  $sessions
     * @return array{station: Device, table: PosTable, session: QrSession, secret: string}
     */
    private function trackedOpenAndBindFlow(
        Device $station,
        string $label,
        array &$orders,
        array &$sessions,
        string $context,
    ): array {
        $table = $this->tableFixture($label);
        $opened = $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $table->id])
            ->assertCreated();
        $session = QrSession::query()->where('uuid', $opened->json('data.session_uuid'))->sole();
        $sessions[(int) $session->id] = $session;
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' open-table');

        $secret = 'tracked-'.Str::lower(Str::random(24));
        $this->postJson(self::TABLE_BIND_URL, [
            'table_token' => $table->qr_token,
            'client_secret' => $secret,
        ])->assertOk();
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' bind');

        return compact('station', 'table', 'session', 'secret');
    }

    /**
     * @param  array<string, mixed>  $flow
     * @param  array<int, Order>  $orders
     * @param  array<int, QrSession>  $sessions
     */
    private function trackedFirstRound(
        array $flow,
        int $productId,
        string $requestId,
        array &$orders,
        array &$sessions,
        string $context,
    ): Order {
        $response = $this->submitRound($flow, $this->roundPayload(
            requestId: $requestId,
            productId: $productId,
            firstRound: true,
        ))->assertCreated();
        $order = Order::query()->where('uuid', $response->json('data.order.uuid'))->sole();
        $orders[(int) $order->id] = $order;
        $this->assertTrackedLifetimeInvariants($orders, $sessions, $context.' first round');

        return $order;
    }

    /**
     * @param  array<int, Order>  $orders
     * @param  array<int, QrSession>  $sessions
     */
    private function assertTrackedLifetimeInvariants(
        array $orders,
        array $sessions,
        string $context,
    ): void {
        foreach ($orders as $order) {
            $fresh = $order->fresh();
            $this->assertLifetimeLedger($fresh, $context.' order='.(int) $fresh->id);

            if ($fresh->charge_amount_baisas !== null) {
                $this->assertSame(
                    Money::toBaisas($fresh->grand_total),
                    (int) $fresh->charge_amount_baisas,
                    $context.' frozen charge amount',
                );
            }
            if ($fresh->charge_outcome === Order::CHARGE_OUTCOME_APPROVED) {
                $this->assertSame(Order::STATUS_PAID, $fresh->status, $context.' approved terminal');
            }
            if (in_array($fresh->status, [Order::STATUS_PAID, Order::STATUS_VOID], true)) {
                $this->assertSame(
                    QrSession::STATUS_CLOSED,
                    QrSession::query()->findOrFail((int) $fresh->qr_session_id)->status,
                    $context.' terminal session close',
                );
            }
        }

        foreach ($sessions as $session) {
            $fresh = $session->fresh();
            $this->assertNotNull($fresh->table_id, $context.' tracked session is dine-in');
            $this->assertLessThanOrEqual(
                1,
                Order::query()
                    ->where('table_id', $fresh->table_id)
                    ->where('source', Order::SOURCE_QR_WEB)
                    ->where('order_type', 'dine_in')
                    ->whereIn('status', [
                        Order::STATUS_OPEN,
                        Order::STATUS_HELD,
                        Order::STATUS_AWAITING_PAYMENT,
                    ])
                    ->count(),
                $context.' one unpaid table order',
            );
        }

        $this->assertNoQrRoundup();
    }

    /**
     * @return array{
     *   station: Device,
     *   table: PosTable,
     *   session: QrSession,
     *   secret: string
     * }
     */
    private function openAndBindFlow(Device $station, string $label): array
    {
        $table = $this->tableFixture($label);
        $opened = $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $table->id])
            ->assertCreated()
            ->assertJsonPath('data.table_token', $table->qr_token);
        $session = QrSession::query()
            ->where('uuid', $opened->json('data.session_uuid'))
            ->sole();
        $secret = 'qr2-secret-'.Str::lower(Str::random(24));
        $this->postJson(self::TABLE_BIND_URL, [
            'table_token' => $table->qr_token,
            'client_secret' => $secret,
        ])->assertOk()
            ->assertJsonPath('data.session_uuid', $session->uuid)
            ->assertJsonPath('data.status', QrSession::STATUS_ACTIVE);

        return compact('station', 'table', 'session', 'secret');
    }

    /**
     * @return array{
     *   station: Device,
     *   table: PosTable,
     *   session: QrSession,
     *   secret: string,
     *   order: Order
     * }
     */
    private function paymentFlow(Device $station, string $label, string $choice): array
    {
        $flow = $this->openAndBindFlow($station, $label);
        $created = $this->submitRound($flow, $this->roundPayload(
            requestId: $label.'-round-1',
            productId: 2,
            firstRound: true,
        ))->assertCreated();
        $flow['order'] = Order::query()->where('uuid', $created->json('data.order.uuid'))->sole();
        $this->finishFlow($flow, $choice)->assertOk();

        return $flow;
    }

    /** @param array<string, mixed> $flow */
    private function submitRound(array $flow, array $payload): TestResponse
    {
        return $this->withHeaders($this->qrHeaders($flow['session'], $flow['secret']))
            ->postJson(self::TABLE_ROUND_URL, $payload);
    }

    /** @param array<string, mixed> $flow */
    private function finishAtStation(array $flow): TestResponse
    {
        return $this->finishFlow($flow, 'station');
    }

    /** @param array<string, mixed> $flow */
    private function finishFlow(array $flow, string $choice): TestResponse
    {
        return $this->withHeaders($this->qrHeaders($flow['session'], $flow['secret']))
            ->postJson(self::TABLE_FINISH_URL, ['payment_choice' => $choice]);
    }

    /** @return array<string, string> */
    private function qrHeaders(QrSession $session, string $secret): array
    {
        return [
            'X-QR-Session' => (string) $session->uuid,
            'X-QR-Client-Secret' => $secret,
        ];
    }

    private function sendQrStatus(QrSession $session, string $secret): TestResponse
    {
        return $this->withHeaders($this->qrHeaders($session, $secret))
            ->getJson(self::QR_STATUS_URL);
    }

    private function claim(Device $device, Order $order): TestResponse
    {
        return $this->postAs($device, self::CLAIM_URL, ['order_uuid' => $order->uuid]);
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
        return $this->postAs($device, self::FALLBACK_URL, ['order_uuid' => $order->uuid]);
    }

    private function reopen(Device $device, Order $order): TestResponse
    {
        return $this->postAs($device, self::REOPEN_URL, ['order_uuid' => $order->uuid]);
    }

    private function clearTable(Device $device, PosTable $table): TestResponse
    {
        return $this->postAs($device, self::CLEAR_TABLE_URL, ['table_id' => $table->id]);
    }

    /** @return list<array<string, mixed>> */
    private function awaitingRows(Device $device): array
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken((string) $device->device_token)
            ->getJson(self::AWAITING_URL)
            ->assertOk()
            ->json('data.orders');
    }

    /** @return list<array<string, mixed>> */
    private function boardRows(Device $device): array
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken((string) $device->device_token)
            ->getJson(self::TABLE_BOARD_URL)
            ->assertOk()
            ->json('data.tables');
    }

    /** @param list<array<string, mixed>> $events */
    private function push(Device $device, array $events): TestResponse
    {
        return $this->postAs($device, self::SYNC_URL, ['events' => $events]);
    }

    /** @param array<string, mixed> $payload */
    private function postAs(Device $device, string $url, array $payload = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken((string) $device->device_token)->postJson($url, $payload);
    }

    /** @return array<string, mixed> */
    private function paymentEvent(Order $order, string $method): array
    {
        $payment = [
            'method' => $method,
            'amount_baisas' => Money::toBaisas($order->fresh()->grand_total),
            'status' => Payment::STATUS_SUCCESS,
        ];
        if ($method === Payment::METHOD_CARD) {
            $payment['softpos_reference'] = 'QR2-'.$this->nextEventId();
            $payment['softpos_auth_code'] = 'QR2-AUTH';
        }

        return [
            'client_event_id' => $this->nextEventId(),
            'event_type' => 'order.pay',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => $order->uuid,
                'paid_at' => now()->toIso8601String(),
                'payments' => [$payment],
            ],
        ];
    }

    private function nextEventId(): string
    {
        $this->eventSequence++;

        return sprintf('10000000-0000-4000-8000-%012d', $this->eventSequence);
    }

    private function assertSyncProcessed(TestResponse $response): void
    {
        $response->assertOk();
        $this->assertSame(
            'processed',
            $response->json('data.results.0.status'),
            (string) json_encode($response->json('data.results.0')),
        );
    }

    /**
     * The accepted-round ledger, order header, and append-only till rows must
     * agree after every call, and a frozen round must never change afterward.
     */
    private function assertLifetimeLedger(Order $order, string $context): void
    {
        $order = $order->fresh();
        $rounds = QrOrderRound::query()
            ->where('order_id', $order->id)
            ->where('status', QrOrderRound::STATUS_ACCEPTED)
            ->orderBy('round_no')
            ->get();
        $this->assertNotEmpty($rounds, $context);

        foreach ($rounds as $round) {
            $snapshot = $this->roundSnapshot($round);
            if (isset($this->frozenRoundSnapshots[(int) $round->id])) {
                $this->assertSame(
                    $this->frozenRoundSnapshots[(int) $round->id],
                    $snapshot,
                    $context.' immutable round '.(int) $round->round_no,
                );
            } else {
                $this->frozenRoundSnapshots[(int) $round->id] = $snapshot;
            }
        }

        $roundSubtotal = (int) $rounds->sum('subtotal_baisas');
        $roundTax = (int) $rounds->sum('tax_baisas');
        $roundGrand = (int) $rounds->sum('total_baisas');
        $headerSubtotal = Money::toBaisas($order->subtotal);
        $headerDiscount = Money::toBaisas($order->discount_total);
        $headerTax = Money::toBaisas($order->tax_total);
        $headerGrand = Money::toBaisas($order->grand_total);

        $this->assertSame($roundSubtotal, $headerSubtotal, $context.' rounds/header subtotal');
        $this->assertSame($roundTax, $headerTax, $context.' rounds/header tax');
        $this->assertSame($roundGrand, $headerGrand, $context.' rounds/header grand');
        $this->assertSame(
            $headerSubtotal - $headerDiscount + $headerTax,
            $headerGrand,
            $context.' header equation',
        );

        $items = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get();
        $itemSubtotal = (int) $items->sum(
            static fn (OrderItem $item): int => Money::toBaisas($item->line_total),
        );
        $discountRows = OrderDiscount::query()->where('order_id', $order->id)->get();
        $rowDiscount = (int) $discountRows->sum(
            static fn (OrderDiscount $discount): int => Money::toBaisas($discount->amount),
        );
        $this->assertSame($headerSubtotal, $itemSubtotal, $context.' items/header subtotal');
        $this->assertSame($headerDiscount, $rowDiscount, $context.' discounts/header discount');
        $this->assertSame(
            $itemSubtotal - $rowDiscount + $headerTax,
            $headerGrand,
            $context.' till-row equation',
        );

        $frozenLineCount = 0;
        $frozenAddonCount = 0;
        foreach ($rounds as $round) {
            $lines = is_array($round->priced_lines) ? $round->priced_lines : [];
            $frozenLineCount += count($lines);
            foreach ($lines as $line) {
                $addons = is_array($line['addons'] ?? null) ? $line['addons'] : [];
                $frozenAddonCount += count($addons);
            }
        }
        $this->assertSame($frozenLineCount, $items->count(), $context.' frozen/item line count');
        $this->assertSame(
            $frozenAddonCount,
            OrderItemAddon::query()
                ->whereIn('order_item_id', $items->pluck('id')->all())
                ->count(),
            $context.' frozen/add-on row count',
        );

        $this->assertNoQrRoundup();
    }

    /** @return array<string, mixed> */
    private function roundSnapshot(QrOrderRound $round): array
    {
        return array_intersect_key($round->getRawOriginal(), array_flip([
            'qr_session_id',
            'order_id',
            'round_no',
            'status',
            'client_request_id',
            'priced_lines',
            'subtotal_baisas',
            'tax_baisas',
            'total_baisas',
            'submitted_at',
            'resolved_at',
            'resolved_by_device_id',
        ]));
    }

    /** @return array<string, mixed> */
    private function chargeProvenance(Order $order): array
    {
        return array_intersect_key(
            $order->getRawOriginal(),
            array_flip(self::CHARGE_FIELDS),
        );
    }

    private function assertChargeFieldsNull(Order $order): void
    {
        foreach (self::CHARGE_FIELDS as $field) {
            $this->assertNull($order->getRawOriginal($field), $field);
        }
    }

    private function assertNoQrRoundup(): void
    {
        $this->assertDatabaseCount('pos_roundup_donations', 0);
        $this->assertSame(0, Payment::query()->whereNotNull('roundup_amount')->count());
        $this->assertSame(
            0,
            Order::query()
                ->where('source', Order::SOURCE_QR_WEB)
                ->where('order_type', 'dine_in')
                ->whereNotNull('charge_roundup_amount_baisas')
                ->count(),
        );
    }

    private function exerciseZeroRoundFinishArm(Device $station, string $label): void
    {
        $flow = $this->openAndBindFlow($station, $label);
        $pending = QrOrderRound::query()->create([
            'qr_session_id' => $flow['session']->id,
            'order_id' => null,
            'round_no' => 1,
            'status' => QrOrderRound::STATUS_PENDING_CONFIRMATION,
            'client_request_id' => 'pending-'.$label,
            'priced_lines' => [],
            'subtotal_baisas' => 0,
            'tax_baisas' => 0,
            'total_baisas' => 0,
            'submitted_at' => now(),
            'resolved_at' => null,
            'resolved_by_device_id' => null,
        ]);

        $this->finishAtStation($flow)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_dine_in_order_required');
        $this->assertSame(QrOrderRound::STATUS_REJECTED, $pending->fresh()->status);
        $this->assertNotNull($pending->fresh()->resolved_at);
        $this->assertSame(QrSession::STATUS_ACTIVE, $flow['session']->fresh()->status);
        $this->assertSame(0, Order::query()->where('qr_session_id', $flow['session']->id)->count());
    }

    /** @return array{session: QrSession, secret: string, order: Order} */
    private function quickCheckout(Device $station, string $requestId): array
    {
        $secret = 'quick-secret-'.$requestId;
        $session = QrSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $station->company_id,
            'branch_id' => $station->branch_id,
            'device_id' => $station->id,
            'table_id' => null,
            'token' => bin2hex(random_bytes(32)),
            'token_expires_at' => now()->addMinute(),
            'client_secret_hash' => QrSession::hashClientSecret($secret),
            'status' => QrSession::STATUS_ACTIVE,
            'bound_at' => now(),
            'last_seen_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);
        $response = $this->withHeaders($this->qrHeaders($session, $secret))
            ->postJson(self::QUICK_CHECKOUT_URL, [
                'client_request_id' => $requestId,
                'checkout_choice' => 'machine',
                'phone' => $this->nextPhone(),
                'lines' => $this->linesFor(2),
            ])->assertCreated();
        $order = Order::query()->where('uuid', $response->json('data.order.uuid'))->sole();

        return compact('session', 'secret', 'order');
    }

    private function quickPendingSession(Device $station): QrSession
    {
        return QrSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $station->company_id,
            'branch_id' => $station->branch_id,
            'device_id' => $station->id,
            'table_id' => null,
            'token' => bin2hex(random_bytes(32)),
            'token_expires_at' => now()->addMinute(),
            'status' => QrSession::STATUS_PENDING,
            'expires_at' => now()->addMinutes(30),
        ]);
    }

    private function assertQuickProjectionIsUnchanged(Device $station, Order $quickOrder): void
    {
        $row = collect($this->awaitingRows($station))
            ->firstWhere('order_uuid', $quickOrder->uuid);
        $this->assertIsArray($row);
        $this->assertArrayNotHasKey('table_label', $row);
        $this->assertSame([
            'session_uuid',
            'order_uuid',
            'receipt_number',
            'temp_reference',
            'status',
            'amount_baisas',
            'item_count',
            'opened_at',
        ], array_keys($row));
    }

    /** @return array<string, mixed> */
    private function roundPayload(
        string $requestId,
        int $productId,
        bool $firstRound,
        array $addonIds = [],
    ): array {
        $payload = [
            'client_request_id' => $requestId,
            'lines' => $this->linesFor($productId, $addonIds),
        ];
        if ($firstRound) {
            $payload['phone'] = $this->nextPhone();
            $payload['plate_number'] = 'OMAN-QR2-'.$this->phoneSequence;
        }

        return $payload;
    }

    /**
     * @param  list<int>  $addonIds
     * @return list<array<string, mixed>>
     */
    private function linesFor(int $productId, array $addonIds = []): array
    {
        return [[
            'product_id' => $productId,
            'qty' => 1,
            'addon_ids' => $addonIds,
            'notes' => null,
        ]];
    }

    private function nextPhone(): string
    {
        $this->phoneSequence++;

        return sprintf('92%06d', $this->phoneSequence);
    }

    private function deviceFixture(string $type, int $branchId, string $label): Device
    {
        Branch::query()->firstOrCreate(['id' => $branchId], [
            'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'name' => 'Lifetime branch '.$branchId, 'status' => 'active',
            'latitude' => null, 'longitude' => null, 'geofence_radius_m' => 500,
        ]);
        $this->deviceSequence++;

        return Device::factory()
            ->withSoftPos()
            ->paired('qr2-life-'.$this->deviceSequence.'-'.$label)
            ->create([
                'company_id' => 100,
                'branch_id' => $branchId,
                'device_type' => $type,
            ]);
    }

    private function tableFixture(string $label): PosTable
    {
        $this->tableSequence++;
        $floor = Floor::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => 10,
            'name' => 'Invariant floor '.$this->tableSequence,
            'display_order' => $this->tableSequence,
            'status' => 'active',
        ]);

        return PosTable::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'floor_id' => $floor->id,
            'label' => $label,
            'seats' => 4,
            'shape' => 'square',
            'qr_token' => hash('sha256', $label.'-'.$this->tableSequence),
            'status' => 'active',
            'display_order' => $this->tableSequence,
        ]);
    }

    private function seedPricingCatalogue(): void
    {
        $timestamps = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_products')->insert([
            [
                'id' => 1,
                'uuid' => (string) Str::uuid(),
                'company_id' => 100,
                'category_id' => null,
                'name' => 'Frozen discounted coffee',
                'base_price' => '5.000',
                'stock_mode' => 'untracked',
                'display_order' => 1,
                'status' => 'active',
                'show_on_customer_tablet' => true,
                'is_internal' => false,
                'available_from' => null,
                'available_until' => null,
                'deleted_at' => null,
            ] + $timestamps,
            [
                'id' => 2,
                'uuid' => (string) Str::uuid(),
                'company_id' => 100,
                'category_id' => null,
                'name' => 'Later meal',
                'base_price' => '2.750',
                'stock_mode' => 'untracked',
                'display_order' => 2,
                'status' => 'active',
                'show_on_customer_tablet' => true,
                'is_internal' => false,
                'available_from' => null,
                'available_until' => null,
                'deleted_at' => null,
            ] + $timestamps,
        ]);
        DB::table('pos_branch_product')->insert([
            ['branch_id' => 10, 'product_id' => 1, 'is_available' => true, 'stock_qty' => null] + $timestamps,
            ['branch_id' => 10, 'product_id' => 2, 'is_available' => true, 'stock_qty' => null] + $timestamps,
        ]);

        DB::table('pos_addon_groups')->insert([
            'id' => 1,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Milk choice',
            'selection_mode' => 'single',
            'is_global' => false,
            'display_order' => 0,
            'status' => 'active',
        ] + $timestamps);
        DB::table('pos_addons')->insert([
            'id' => 1,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'add_on_group_id' => 1,
            'name' => 'Oat milk',
            'price_delta' => '0.500',
            'display_order' => 0,
            'status' => 'active',
        ] + $timestamps);
        DB::table('pos_addon_group_products')->insert([
            'add_on_group_id' => 1,
            'product_id' => 1,
            'display_order' => 0,
        ] + $timestamps);

        DB::table('pos_discounts')->insert([
            'id' => 1,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'First-minute coffee discount',
            'scope' => 'product',
            'amount_type' => 'fixed',
            'amount' => '1.000',
            'validity_start' => now()->subMinute(),
            'validity_end' => now()->addMinute(),
            'dayofweek_mask' => null,
            'time_start' => null,
            'time_end' => null,
            'branch_scope_json' => null,
            'stackable' => false,
            'requires_manager_approval' => false,
            'auto_apply' => true,
            'status' => 'active',
            'deleted_at' => null,
        ] + $timestamps);
        DB::table('pos_discount_targets')->insert([
            'discount_id' => 1,
            'target_type' => 'product',
            'target_id' => 1,
        ] + $timestamps);
    }

    private function enableOrderNumbering(): void
    {
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
    }
}
