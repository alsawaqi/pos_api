<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\ConfirmDineInQrRoundAction;
use App\Actions\Qr\DineInRoundMode;
use App\Actions\Qr\RejectDineInQrRoundAction;
use App\Models\Branch;
use App\Models\Device;
use App\Models\Floor;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\OrderItem;
use App\Models\OrderItemAddon;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\Table as PosTable;
use App\Support\Qr\ForwardedCustomerIp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * S5 Part A's money/identity contracts. Route admission and the accepted-round
 * print feed live in their own focused suites; this class pins the round core.
 */
final class QrDineInStaffConfirmModeTest extends TestCase
{
    use RefreshDatabase;

    private const OPEN_TABLE_URL = '/api/v1/device/qr/open-table';

    private const TABLE_BIND_URL = '/api/v1/public/qr/table-bind';

    private const TABLE_ROUND_URL = '/api/v1/public/qr/table-round';

    private const STATUS_URL = '/api/v1/public/qr/status';

    private const CLAIM_SETTLEMENT_URL = '/api/v1/device/qr/claim-settlement';

    private const SYNC_URL = '/api/v1/device/sync/push';

    private const BFF_SECRET = 'w2-dine-in-bff-secret';

    private int $deviceSequence = 0;

    private int $tableSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-31 10:00:00 UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
        Cache::flush();
        config([
            'qr.dine_in_session_lifetime_hours' => 6,
            'qr.settlement_claim_seconds' => 300,
            'qr.station_geofence_exempt' => false,
            'qr.distinct_phone_ip_backstop_per_branch_per_hour' => 500,
        ]);

        Branch::query()->create([
            'id' => 10,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'S5 contract branch',
            'latitude' => null,
            'longitude' => null,
            'geofence_radius_m' => 500,
            'status' => 'active',
        ]);
        $this->seedPricingCatalogue();
        $this->enableOrderNumbering();
    }

    /** @return array<string, array{string|null}> */
    public static function directModeFallbacks(): array
    {
        return [
            'missing policy' => [null],
            'explicit kitchen-direct' => [json_encode('kitchen_direct', JSON_THROW_ON_ERROR)],
            'malformed JSON' => ['{not-json'],
            'unsupported scalar' => [json_encode('future-mode', JSON_THROW_ON_ERROR)],
        ];
    }

    public function test_round_guard_uses_authenticated_bff_customer_ip(): void
    {
        $this->setRoundMode(DineInRoundMode::KITCHEN_DIRECT);
        config([
            'qr.bff_client_ip_secret' => self::BFF_SECRET,
            'qr.distinct_phone_ip_backstop_per_branch_per_hour' => 1,
        ]);
        Cache::flush();
        $first = $this->openAndBindFlow('W2-IP-A');
        $second = $this->openAndBindFlow('W2-IP-B');

        $this->submitRoundFromIp(
            $first,
            $this->roundPayload('w2-ip-round-a', '90002001', null),
            '203.0.113.81',
        )->assertCreated();
        $this->submitRoundFromIp(
            $second,
            $this->roundPayload('w2-ip-round-b', '90002002', null),
            '203.0.113.82',
        )->assertCreated();
    }

    /**
     * This is the strongest in-tree baseline control possible without checking
     * a volatile UUID/id fixture into the branch: it pins every stable response
     * field, key order, frozen priced_lines JSON byte string, and child ledger
     * value for both the missing and tolerant-garbage paths.
     */
    #[DataProvider('directModeFallbacks')]
    public function test_kitchen_direct_default_and_garbage_match_the_shipped_golden(
        ?string $rawMode,
    ): void {
        if ($rawMode !== null) {
            $this->setRoundModeRaw($rawMode);
        }
        $flow = $this->openAndBindFlow('DIRECT');
        $response = $this->submitRound($flow, $this->roundPayload(
            'baseline-direct-round',
            '92000001',
            'OM 1001',
        ))->assertCreated();

        $this->assertDirectResponseGolden($response, 'baseline-direct-round');
        $round = QrOrderRound::query()->sole();
        $order = Order::query()->sole();

        $this->assertSame(QrOrderRound::STATUS_ACCEPTED, $round->status);
        $this->assertNull($round->confirm_payload);
        $this->assertNotNull($order->table_session_id);
        $this->assertSame((int) $order->table_session_id, (int) $round->table_session_id);
        $this->assertSame((int) $order->table_session_id, (int) $flow['session']->fresh()->table_session_id);
        $this->assertSame(
            json_encode([$this->expectedPricedLines()[0] + ['order_item_id' => (int) OrderItem::query()->where('order_id', $order->id)->sole()->id]], JSON_THROW_ON_ERROR),
            $round->getRawOriginal('priced_lines'),
            'kitchen-direct priced_lines bytes drifted',
        );
        $this->assertSame(now()->toIso8601String(), $round->submitted_at?->toIso8601String());
        $this->assertSame(now()->toIso8601String(), $round->resolved_at?->toIso8601String());
        $this->assertNull($round->resolved_by_device_id);

        $this->assertSame('5.750', $order->subtotal);
        $this->assertSame('1.250', $order->discount_total);
        $this->assertSame('0.000', $order->tax_total);
        $this->assertSame('4.500', $order->grand_total);
        $this->assertSame(1, OrderItem::query()->where('order_id', $order->id)->count());
        $this->assertSame(2, OrderItemAddon::query()
            ->whereIn('order_item_id', OrderItem::query()->where('order_id', $order->id)->pluck('id'))
            ->count());
        $this->assertSame(2, OrderDiscount::query()->where('order_id', $order->id)->count());

        $item = OrderItem::query()->where('order_id', $order->id)->sole();
        $this->assertSame(101, (int) $item->product_id);
        $this->assertSame('Contract coffee', $item->product_name_snapshot);
        $this->assertSame('1.000', $item->qty);
        $this->assertSame('5.750', $item->unit_price_snapshot);
        $this->assertSame('1.000', $item->line_discount);
        $this->assertSame('5.750', $item->line_total);
        $this->assertSame('No sugar', $item->notes);
        $this->assertSame([[
            'ingredient_id' => 301,
            'qty' => 0.25,
            'unit' => 'l',
            'unit_cost' => 0.4,
        ]], $item->recipe_snapshot_json);
        $this->assertSame([['product_id' => 102, 'qty' => 1]], $item->component_snapshot_json);

        $discounts = OrderDiscount::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();
        $this->assertSame([501, 502], $discounts->pluck('discount_id')->map(
            static fn ($id): int => (int) $id,
        )->all());
        $this->assertSame(['1.000', '0.250'], $discounts->pluck('amount')->all());
        $this->assertNotNull($discounts[0]->order_item_id);
        $this->assertNull($discounts[1]->order_item_id);
    }

    /** @return array<string, array{string|null}> */
    public static function branchDirectModeFallbacks(): array
    {
        return [
            'sibling override only' => [null],
            'malformed branch JSON' => ['{not-json'],
            'branch array' => [json_encode(['staff_confirm'], JSON_THROW_ON_ERROR)],
            'unknown branch mode' => [json_encode('unknown-mode', JSON_THROW_ON_ERROR)],
            'branch JSON null' => [json_encode(null, JSON_THROW_ON_ERROR)],
        ];
    }

    #[DataProvider('branchDirectModeFallbacks')]
    public function test_kitchen_direct_golden_holds_under_sibling_override_and_garbage_branch_row(
        ?string $rawMode,
    ): void {
        $this->createSiblingBranch();
        $this->setBranchRoundModeRaw(11, json_encode('staff_confirm', JSON_THROW_ON_ERROR));
        if ($rawMode !== null) {
            $this->setBranchRoundModeRaw(10, $rawMode);
        }
        $flow = $this->openAndBindFlow('DIRECT');
        $response = $this->submitRound($flow, $this->roundPayload(
            'baseline-direct-round',
            '92000001',
            'OM 1001',
        ))->assertCreated();

        $this->assertDirectResponseGolden($response, 'baseline-direct-round');
        $round = QrOrderRound::query()->sole();
        $order = Order::query()->sole();

        $this->assertSame(QrOrderRound::STATUS_ACCEPTED, $round->status);
        $this->assertNull($round->confirm_payload);
        $this->assertSame(
            json_encode([$this->expectedPricedLines()[0] + ['order_item_id' => (int) OrderItem::query()->where('order_id', $order->id)->sole()->id]], JSON_THROW_ON_ERROR),
            $round->getRawOriginal('priced_lines'),
            'kitchen-direct priced_lines bytes drifted',
        );
        $this->assertSame(now()->toIso8601String(), $round->submitted_at?->toIso8601String());
        $this->assertSame(now()->toIso8601String(), $round->resolved_at?->toIso8601String());
        $this->assertNull($round->resolved_by_device_id);

        $this->assertSame('5.750', $order->subtotal);
        $this->assertSame('1.250', $order->discount_total);
        $this->assertSame('0.000', $order->tax_total);
        $this->assertSame('4.500', $order->grand_total);
        $this->assertSame(1, OrderItem::query()->where('order_id', $order->id)->count());
        $this->assertSame(2, OrderItemAddon::query()
            ->whereIn('order_item_id', OrderItem::query()->where('order_id', $order->id)->pluck('id'))
            ->count());
        $this->assertSame(2, OrderDiscount::query()->where('order_id', $order->id)->count());

        $item = OrderItem::query()->where('order_id', $order->id)->sole();
        $this->assertSame(101, (int) $item->product_id);
        $this->assertSame('Contract coffee', $item->product_name_snapshot);
        $this->assertSame('1.000', $item->qty);
        $this->assertSame('5.750', $item->unit_price_snapshot);
        $this->assertSame('1.000', $item->line_discount);
        $this->assertSame('5.750', $item->line_total);
        $this->assertSame('No sugar', $item->notes);
        $this->assertSame([[
            'ingredient_id' => 301,
            'qty' => 0.25,
            'unit' => 'l',
            'unit_cost' => 0.4,
        ]], $item->recipe_snapshot_json);
        $this->assertSame([['product_id' => 102, 'qty' => 1]], $item->component_snapshot_json);

        $discounts = OrderDiscount::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();
        $this->assertSame([501, 502], $discounts->pluck('discount_id')->map(
            static fn ($id): int => (int) $id,
        )->all());
        $this->assertSame(['1.000', '0.250'], $discounts->pluck('amount')->all());
        $this->assertNotNull($discounts[0]->order_item_id);
        $this->assertNull($discounts[1]->order_item_id);
    }

    /** @return array<string, array{string, int, string, string, bool}> */
    public static function branchRoundModeOverrides(): array
    {
        return [
            'branch confirms over direct company' => [
                DineInRoundMode::KITCHEN_DIRECT, 10,
                json_encode('staff_confirm', JSON_THROW_ON_ERROR),
                QrOrderRound::STATUS_PENDING_CONFIRMATION, true,
            ],
            'branch direct over confirming company' => [
                DineInRoundMode::STAFF_CONFIRM, 10,
                json_encode('kitchen_direct', JSON_THROW_ON_ERROR),
                QrOrderRound::STATUS_ACCEPTED, false,
            ],
            'sibling direct cannot bypass confirming company' => [
                DineInRoundMode::STAFF_CONFIRM, 11,
                json_encode('kitchen_direct', JSON_THROW_ON_ERROR),
                QrOrderRound::STATUS_PENDING_CONFIRMATION, true,
            ],
            'malformed branch cannot bypass confirming company' => [
                DineInRoundMode::STAFF_CONFIRM, 10,
                '{not-json',
                QrOrderRound::STATUS_PENDING_CONFIRMATION, true,
            ],
        ];
    }

    #[DataProvider('branchRoundModeOverrides')]
    public function test_branch_override_decides_the_round_status(
        string $companyMode,
        int $branchId,
        string $rawBranchMode,
        string $expectedStatus,
        bool $expectsConfirmation,
    ): void {
        $this->createSiblingBranch();
        $this->setRoundMode($companyMode);
        $this->setBranchRoundModeRaw($branchId, $rawBranchMode);
        $flow = $this->openAndBindFlow('BRANCH-MODE');
        $this->submitRound($flow, $this->roundPayload(
            'branch-mode-round',
            '92000401',
            null,
        ))->assertCreated()
            ->assertJsonPath('data.round.status', $expectedStatus);

        $round = QrOrderRound::query()->sole();
        $order = Order::query()->sole();
        $this->assertSame($expectedStatus, $round->status);
        if ($expectsConfirmation) {
            $this->assertNotNull($round->confirm_payload);
            $this->assertNotEmpty($round->confirm_payload);
            $this->assertNull($round->resolved_at);
            $this->assertSame('0.000', $order->grand_total);
        } else {
            $this->assertNull($round->confirm_payload);
            $this->assertSame(now()->toIso8601String(), $round->resolved_at?->toIso8601String());
            $this->assertSame('4.500', $order->grand_total);
        }
    }

    public function test_branch_mode_flip_preserves_pending_rounds_and_their_confirmation_or_rejection(): void
    {
        $this->setBranchRoundModeRaw(10, json_encode('staff_confirm', JSON_THROW_ON_ERROR));
        $flow = $this->openAndBindFlow('MODE-FLIP');
        $firstPayload = $this->roundPayload('before-flip-confirm', '92000501', null);
        $this->submitRound($flow, $firstPayload)->assertCreated()
            ->assertJsonPath('data.round.status', QrOrderRound::STATUS_PENDING_CONFIRMATION);
        $this->submitRound($flow, $this->roundPayload('before-flip-reject', '92000501', null))
            ->assertCreated()
            ->assertJsonPath('data.round.status', QrOrderRound::STATUS_PENDING_CONFIRMATION);
        $rounds = QrOrderRound::query()->orderBy('id')->get();
        $this->assertCount(2, $rounds);
        $before = $rounds->map(static fn (QrOrderRound $round): array => $round->getRawOriginal())->all();

        $this->setBranchRoundModeRaw(10, json_encode('kitchen_direct', JSON_THROW_ON_ERROR));
        foreach ($rounds as $index => $round) {
            $this->assertSame($before[$index], $round->fresh()->getRawOriginal());
            $this->assertSame(QrOrderRound::STATUS_PENDING_CONFIRMATION, $round->fresh()->status);
        }
        $this->submitRound($flow, $firstPayload)->assertCreated()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.round.status', QrOrderRound::STATUS_PENDING_CONFIRMATION);

        $resolver = $this->device('fixed_pos');
        $confirmed = $this->app->make(ConfirmDineInQrRoundAction::class)->handle($resolver, (int) $rounds[0]->id);
        $rejected = $this->app->make(RejectDineInQrRoundAction::class)->handle($resolver, (int) $rounds[1]->id);
        $this->assertSame(QrOrderRound::STATUS_ACCEPTED, $confirmed['round']['status']);
        $this->assertSame(QrOrderRound::STATUS_REJECTED, $rejected['round']['status']);
        $expectedConfirmedLines = json_decode($before[0]['priced_lines'], true, flags: JSON_THROW_ON_ERROR);
        $expectedConfirmedLines[0]['order_item_id'] = (int) OrderItem::query()->where('order_id', $rounds[0]->order_id)->sole()->id;
        $before[0]['priced_lines'] = json_encode($expectedConfirmedLines, JSON_THROW_ON_ERROR);
        foreach ($rounds as $index => $round) {
            $round->refresh();
            $this->assertSame($before[$index]['priced_lines'], $round->getRawOriginal('priced_lines'));
            $this->assertNull($round->confirm_payload);
            $this->assertSame(now()->toIso8601String(), $round->resolved_at?->toIso8601String());
        }
        $this->assertSame('4.500', Order::query()->sole()->grand_total);
    }

    public function test_staff_confirm_submit_is_private_pending_zero_total_and_idempotent(): void
    {
        $this->setRoundMode(DineInRoundMode::STAFF_CONFIRM);
        $flow = $this->openAndBindFlow('PENDING');
        $request = $this->roundPayload(
            'staff-pending-round',
            '92000011',
            'OM 1011',
        );

        $first = $this->submitRound($flow, $request)
            ->assertCreated()
            ->assertJsonPath('data.round.status', QrOrderRound::STATUS_PENDING_CONFIRMATION)
            ->assertJsonPath('data.round.resolved_at', null)
            ->assertJsonPath('data.order.subtotal_baisas', 0)
            ->assertJsonPath('data.order.discount_total_baisas', 0)
            ->assertJsonPath('data.order.tax_total_baisas', 0)
            ->assertJsonPath('data.order.grand_total_baisas', 0)
            ->assertJsonPath('data.replayed', false);
        $round = QrOrderRound::query()->sole();
        $order = Order::query()->sole();
        $roundBeforeReplay = $round->getRawOriginal();
        $orderBeforeReplay = $order->getRawOriginal();
        $this->assertNotNull($round->table_session_id);
        $this->assertSame((int) $order->table_session_id, (int) $round->table_session_id);
        $seatingBeforeReplay = $order->tableSession()->sole()->getRawOriginal();

        $this->assertSame((int) $order->id, (int) $round->order_id);
        $this->assertNull($round->resolved_at);
        $this->assertNull($round->resolved_by_device_id);
        $this->assertIsArray($round->confirm_payload);
        $this->assertNotEmpty($round->confirm_payload);
        $this->assertPrivatePayloadIsAppendComplete($round->confirm_payload);
        $this->assertNoOrderChildren($order);
        $this->assertSame('0.000', $order->subtotal);
        $this->assertSame('0.000', $order->discount_total);
        $this->assertSame('0.000', $order->tax_total);
        $this->assertSame('0.000', $order->grand_total);
        $this->assertPrivatePayloadDidNotLeak($first);

        $status = $this->qrStatus($flow)
            ->assertOk()
            ->assertJsonPath('data.order.uuid', $order->uuid)
            ->assertJsonPath('data.order.grand_total_baisas', 0)
            ->assertJsonPath('data.dine_in.rounds.0.id', (int) $round->id)
            ->assertJsonPath('data.dine_in.rounds.0.status', QrOrderRound::STATUS_PENDING_CONFIRMATION)
            ->assertJsonPath('data.dine_in.rounds.0.total_baisas', 4500)
            ->assertJsonPath('data.dine_in.running_total_baisas', 0)
            ->assertJsonPath('data.dine_in.bill_totals.grand_total_baisas', 0)
            ->assertJsonPath('data.dine_in.bill_totals.manual_discount_baisas', 0)
            ->assertJsonPath('data.dine_in.payment_state', null)
            ->assertJsonPath('data.dine_in.round_submission.allowed', true)
            ->assertJsonPath('data.dine_in.round_submission.refusal_code', null)
            ->assertJsonPath('data.dine_in.finish_and_pay.allowed', false)
            ->assertJsonPath('data.dine_in.finish_and_pay.refusal_code', 'qr_dine_in_order_required');
        $this->assertPrivatePayloadDidNotLeak($status);

        $replay = $this->submitRound($flow, $request)
            ->assertCreated()
            ->assertJsonPath('data.replayed', true)
            ->assertJsonPath('data.round.id', (int) $round->id)
            ->assertJsonPath('data.round.status', QrOrderRound::STATUS_PENDING_CONFIRMATION)
            ->assertJsonPath('data.order.uuid', $order->uuid)
            ->assertJsonPath('data.order.grand_total_baisas', 0);
        $firstData = $first->json('data');
        $replayData = $replay->json('data');
        unset($firstData['replayed'], $replayData['replayed']);
        $this->assertSame($firstData, $replayData);
        $this->assertSame($roundBeforeReplay, $round->fresh()->getRawOriginal());
        $this->assertSame($orderBeforeReplay, $order->fresh()->getRawOriginal());
        $this->assertSame($seatingBeforeReplay, $order->tableSession()->sole()->getRawOriginal());
        $this->assertDatabaseCount('pos_qr_order_rounds', 1);
        $this->assertNoOrderChildren($order);
        $this->assertPrivatePayloadDidNotLeak($replay);
    }

    public function test_confirm_uses_only_the_frozen_payload_and_materializes_the_accepted_round(): void
    {
        $this->setRoundMode(DineInRoundMode::STAFF_CONFIRM);
        $flow = $this->openAndBindFlow('CONFIRM');
        $created = $this->submitRound($flow, $this->roundPayload(
            'confirm-frozen-round',
            '92000021',
            'OM 1021',
        ))->assertCreated();
        $round = QrOrderRound::query()->sole();
        $order = Order::query()->where('uuid', $created->json('data.order.uuid'))->sole();
        $resolver = $this->device('fixed_pos');
        $frozenPayload = $round->confirm_payload;
        $frozenLinesBytes = $round->getRawOriginal('priced_lines');
        $seatingId = (int) $round->table_session_id;
        $this->assertGreaterThan(0, $seatingId);

        $this->assertIsArray($frozenPayload);
        DB::table('pos_products')->where('id', 101)->update([
            'name' => 'Mutated after submit',
            'base_price' => '99.999',
        ]);
        DB::table('pos_addons')->where('id', 201)->update([
            'name' => 'Mutated add-on',
            'price_delta' => '9.999',
            'ingredient_qty' => '9.000',
        ]);
        DB::table('pos_product_recipes')
            ->where('product_id', 101)
            ->where('ingredient_id', 301)
            ->update(['quantity' => '9.000']);
        DB::table('pos_discounts')->whereIn('id', [501, 502])->update([
            'name' => 'Mutated discount',
            'amount' => '9.000',
        ]);

        $presented = $this->app->make(ConfirmDineInQrRoundAction::class)->handle(
            $resolver,
            (int) $round->id,
        );

        $this->assertSame(QrOrderRound::STATUS_ACCEPTED, $presented['round']['status']);
        $expectedOwnedLines = $this->expectedPricedLines();
        $expectedOwnedLines[0]['order_item_id'] = (int) OrderItem::query()->where('order_id', $order->id)->sole()->id;
        $this->assertSame($expectedOwnedLines, $presented['round']['priced_lines']);
        $this->assertSame(now()->toIso8601String(), $presented['round']['resolved_at']);
        $this->assertSame([
            'subtotal_baisas' => 5750,
            'discount_total_baisas' => 1250,
            'tax_total_baisas' => 0,
            'grand_total_baisas' => 4500,
        ], $presented['order']);
        $this->assertNull($presented['receipt_number']);
        $this->assertMatchesRegularExpression('/^T-\d{4}-\d{3,}$/', $presented['temp_reference']);
        $presentedJson = json_encode($presented, JSON_THROW_ON_ERROR);
        foreach (['confirm_payload', 'recipe_snapshot_json', 'component_snapshot_json'] as $key) {
            $this->assertStringNotContainsString($key, $presentedJson);
        }

        $round->refresh();
        $order->refresh();
        $this->assertSame(QrOrderRound::STATUS_ACCEPTED, $round->status);
        $this->assertSame(now()->toIso8601String(), $round->resolved_at?->toIso8601String());
        $this->assertSame((int) $resolver->id, (int) $round->resolved_by_device_id);
        $this->assertNull($round->confirm_payload);
        $expectedFrozenLines = json_decode($frozenLinesBytes, true, flags: JSON_THROW_ON_ERROR);
        $expectedFrozenLines[0]['order_item_id'] = $expectedOwnedLines[0]['order_item_id'];
        $this->assertSame(json_encode($expectedFrozenLines, JSON_THROW_ON_ERROR), $round->getRawOriginal('priced_lines'));
        $this->assertSame($seatingId, (int) $round->table_session_id);
        $this->assertSame($seatingId, (int) $order->table_session_id);
        $this->assertSame('5.750', $order->subtotal);
        $this->assertSame('1.250', $order->discount_total);
        $this->assertSame('0.000', $order->tax_total);
        $this->assertSame('4.500', $order->grand_total);

        $item = OrderItem::query()->where('order_id', $order->id)->sole();
        $storedItem = $frozenPayload['items'][0];
        $this->assertSame('Contract coffee', $item->product_name_snapshot);
        $this->assertSame($storedItem['attributes']['unit_price_snapshot'], $item->unit_price_snapshot);
        $this->assertSame($storedItem['attributes']['line_discount'], $item->line_discount);
        $this->assertSame($storedItem['attributes']['line_total'], $item->line_total);
        $this->assertSame($storedItem['attributes']['recipe_snapshot_json'], $item->recipe_snapshot_json);
        $this->assertSame(
            $storedItem['attributes']['component_snapshot_json'],
            $item->component_snapshot_json,
        );

        $addons = OrderItemAddon::query()
            ->where('order_item_id', $item->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(count($storedItem['addons']), $addons);
        foreach ($storedItem['addons'] as $index => $storedAddon) {
            $this->assertSame($storedAddon['add_on_name_snapshot'], $addons[$index]->add_on_name_snapshot);
            $this->assertSame($storedAddon['price_delta_snapshot'], $addons[$index]->price_delta_snapshot);
            $this->assertSame(
                $storedAddon['ingredient_snapshot_json'],
                $addons[$index]->ingredient_snapshot_json,
            );
            $this->assertSame(
                $storedAddon['product_snapshot_json'],
                $addons[$index]->product_snapshot_json,
            );
            $this->assertSame(
                $storedAddon['consumption_snapshot_json'],
                $addons[$index]->consumption_snapshot_json,
            );
        }

        $discounts = OrderDiscount::query()
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(count($frozenPayload['discounts']), $discounts);
        foreach ($frozenPayload['discounts'] as $index => $storedDiscount) {
            $this->assertSame($storedDiscount['discount_id'], $discounts[$index]->discount_id);
            $this->assertSame($storedDiscount['name_snapshot'], $discounts[$index]->name_snapshot);
            $this->assertSame($storedDiscount['amount_type_snapshot'], $discounts[$index]->amount_type_snapshot);
            $this->assertSame($storedDiscount['amount'], $discounts[$index]->amount);
            $this->assertSame(
                $storedDiscount['order_item_index'] === null,
                $discounts[$index]->order_item_id === null,
            );
        }

        $status = $this->qrStatus($flow)
            ->assertOk()
            ->assertJsonPath('data.dine_in.rounds.0.status', QrOrderRound::STATUS_ACCEPTED)
            ->assertJsonPath('data.dine_in.running_total_baisas', 4500)
            ->assertJsonPath('data.dine_in.bill_totals.grand_total_baisas', 4500)
            ->assertJsonPath('data.dine_in.bill_totals.manual_discount_baisas', 0)
            ->assertJsonPath('data.order.grand_total_baisas', 4500)
            ->assertJsonPath('data.dine_in.finish_and_pay.allowed', true);
        $this->assertPrivatePayloadDidNotLeak($status);
    }

    public function test_rejected_birth_allows_guarded_identity_correction_until_an_accept_exists(): void
    {
        $this->setRoundMode(DineInRoundMode::STAFF_CONFIRM);
        $flow = $this->openAndBindFlow('IDENTITY');
        $resolver = $this->device('fixed_pos');

        $first = $this->submitRound($flow, $this->roundPayload(
            'identity-one',
            '92000101',
            'old plate',
        ))->assertCreated();
        $order = Order::query()->where('uuid', $first->json('data.order.uuid'))->sole();
        $this->assertOrderIdentity($order, '92000101', 'OLD PLATE');
        $this->rejectForIdentityRegression(
            QrOrderRound::query()->where('client_request_id', 'identity-one')->sole(),
            $resolver,
        );

        $second = $this->submitRound($flow, $this->roundPayload(
            'identity-two',
            '92000102',
            ' corrected plate ',
        ))->assertCreated()
            ->assertJsonPath('data.round.round_no', 2)
            ->assertJsonPath('data.round.status', QrOrderRound::STATUS_PENDING_CONFIRMATION)
            ->assertJsonPath('data.order.uuid', $order->uuid)
            ->assertJsonPath('data.order.grand_total_baisas', 0);
        $this->assertOrderIdentity($order->fresh(), '92000102', 'CORRECTED PLATE');
        $this->rejectForIdentityRegression(
            QrOrderRound::query()->where('client_request_id', 'identity-two')->sole(),
            $resolver,
        );

        $third = $this->submitRound($flow, $this->roundPayload(
            'identity-three',
            '92000103',
            'third plate',
        ))->assertCreated()
            ->assertJsonPath('data.round.round_no', 3);
        $this->assertOrderIdentity($order->fresh(), '92000103', 'THIRD PLATE');
        $this->rejectForIdentityRegression(
            QrOrderRound::query()->where('client_request_id', 'identity-three')->sole(),
            $resolver,
        );

        $this->submitRound($flow, $this->roundPayload(
            'identity-four-refused',
            '92000104',
            'must not land',
        ))->assertStatus(429)
            ->assertJsonPath('errors.0.code', 'qr_identity_limit_exceeded');

        $this->assertDatabaseCount('pos_qr_order_rounds', 3);
        $this->assertOrderIdentity($order->fresh(), '92000103', 'THIRD PLATE');
        $this->assertSame(
            0,
            QrOrderRound::query()
                ->where('qr_session_id', $flow['session']->id)
                ->where('status', QrOrderRound::STATUS_ACCEPTED)
                ->count(),
        );

        $status = $this->qrStatus($flow)
            ->assertOk()
            ->assertJsonPath('data.dine_in.rounds.0.status', QrOrderRound::STATUS_REJECTED)
            ->assertJsonPath('data.dine_in.rounds.1.status', QrOrderRound::STATUS_REJECTED)
            ->assertJsonPath('data.dine_in.rounds.2.status', QrOrderRound::STATUS_REJECTED)
            ->assertJsonPath('data.dine_in.running_total_baisas', 0)
            ->assertJsonPath('data.dine_in.bill_totals.grand_total_baisas', 0)
            ->assertJsonPath('data.dine_in.bill_totals.manual_discount_baisas', 0)
            ->assertJsonPath('data.dine_in.finish_and_pay.allowed', false);
        $this->assertPrivatePayloadDidNotLeak($second);
        $this->assertPrivatePayloadDidNotLeak($third);
        $this->assertPrivatePayloadDidNotLeak($status);
    }

    public function test_zero_accepted_claim_refuses_and_terminally_rejects_pending_round(): void
    {
        $this->setRoundMode(DineInRoundMode::STAFF_CONFIRM);
        $flow = $this->openAndBindFlow('ZERO-CLAIM');
        $created = $this->submitRound($flow, $this->roundPayload(
            'zero-accepted-round',
            '92000201',
            null,
        ))->assertCreated();
        $round = QrOrderRound::query()->sole();
        $order = Order::query()->where('uuid', $created->json('data.order.uuid'))->sole();
        $till = $this->device('fixed_pos');
        $beforeRound = $round->getRawOriginal();
        $beforeOrder = $order->getRawOriginal();
        $beforeSession = $flow['session']->fresh()->getRawOriginal();

        $this->postAs($till, self::CLAIM_SETTLEMENT_URL, [
            'order_uuid' => $order->uuid,
        ])->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_order_not_settleable');

        $round->refresh();
        $this->assertSame(QrOrderRound::STATUS_REJECTED, $round->status);
        $this->assertSame(now()->toIso8601String(), $round->resolved_at?->toIso8601String());
        $this->assertSame((int) $till->id, (int) $round->resolved_by_device_id);
        $this->assertNull($round->confirm_payload);
        $this->assertSame($beforeRound['priced_lines'], $round->getRawOriginal('priced_lines'));
        $this->assertSame($beforeRound['subtotal_baisas'], $round->getRawOriginal('subtotal_baisas'));
        $this->assertSame($beforeRound['tax_baisas'], $round->getRawOriginal('tax_baisas'));
        $this->assertSame($beforeRound['total_baisas'], $round->getRawOriginal('total_baisas'));
        $this->assertSame($beforeRound['submitted_at'], $round->getRawOriginal('submitted_at'));
        $this->assertSame($beforeOrder, $order->fresh()->getRawOriginal());
        $this->assertSame($beforeSession, $flow['session']->fresh()->getRawOriginal());
        $this->assertNull($order->fresh()->charge_device_id);
        $this->assertNull($order->fresh()->charge_amount_baisas);
        $this->assertSame(QrSession::STATUS_ACTIVE, $flow['session']->fresh()->status);
    }

    public function test_void_rejects_and_scrubs_every_pending_round(): void
    {
        $this->setRoundMode(DineInRoundMode::STAFF_CONFIRM);
        $flow = $this->openAndBindFlow('VOID');
        $created = $this->submitRound($flow, $this->roundPayload(
            'void-pending-one',
            '92000301',
            null,
        ))->assertCreated();
        $order = Order::query()->where('uuid', $created->json('data.order.uuid'))->sole();
        $round = QrOrderRound::query()->sole();
        $till = $this->device('fixed_pos');

        $this->postAs($till, self::SYNC_URL, [
            'events' => [[
                'client_event_id' => (string) Str::uuid(),
                'event_type' => 'order.void',
                'client_timestamp' => now()->toIso8601String(),
                'payload' => [
                    'order_uuid' => $order->uuid,
                    'voided_at' => now()->toIso8601String(),
                    'reason' => 'S5 pending-round closure',
                ],
            ]],
        ])->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed');

        $round->refresh();
        $this->assertSame(Order::STATUS_VOID, $order->fresh()->status);
        $this->assertSame(QrSession::STATUS_CLOSED, $flow['session']->fresh()->status);
        $this->assertSame(QrOrderRound::STATUS_REJECTED, $round->status);
        $this->assertSame(now()->toIso8601String(), $round->resolved_at?->toIso8601String());
        $this->assertSame((int) $till->id, (int) $round->resolved_by_device_id);
        $this->assertNull($round->confirm_payload);
        $this->assertNoOrderChildren($order);
    }

    private function assertDirectResponseGolden(
        TestResponse $response,
        string $clientRequestId,
    ): void {
        $body = $response->json();
        $this->assertSame(['data', 'meta', 'errors'], array_keys($body));
        $this->assertSame(['round', 'order', 'replayed'], array_keys($body['data']));
        $this->assertSame([
            'id',
            'round_no',
            'status',
            'client_request_id',
            'priced_lines',
            'subtotal_baisas',
            'tax_baisas',
            'total_baisas',
            'submitted_at',
            'resolved_at',
        ], array_keys($body['data']['round']));
        $this->assertSame([
            'uuid',
            'status',
            'receipt_number',
            'temp_reference',
            'subtotal_baisas',
            'discount_total_baisas',
            'tax_total_baisas',
            'grand_total_baisas',
        ], array_keys($body['data']['order']));
        $this->assertSame([
            'round_no' => 1,
            'status' => QrOrderRound::STATUS_ACCEPTED,
            'client_request_id' => $clientRequestId,
            'priced_lines' => $this->expectedPricedLines(),
            'subtotal_baisas' => 5750,
            'tax_baisas' => 0,
            'total_baisas' => 4500,
            'submitted_at' => now()->toIso8601String(),
            'resolved_at' => now()->toIso8601String(),
        ], array_diff_key($body['data']['round'], ['id' => true]));
        $this->assertSame([
            'status' => Order::STATUS_OPEN,
            'receipt_number' => null,
            'temp_reference' => 'T-'.now()->format('md').'-001',
            'subtotal_baisas' => 5750,
            'discount_total_baisas' => 1250,
            'tax_total_baisas' => 0,
            'grand_total_baisas' => 4500,
        ], array_diff_key($body['data']['order'], ['uuid' => true]));
        $this->assertFalse($body['data']['replayed']);
        $this->assertSame(['money_unit' => 'baisas'], $body['meta']);
        $this->assertSame([], $body['errors']);
        $this->assertPrivatePayloadDidNotLeak($response);
    }

    /** @param array<string, mixed> $payload */
    private function assertPrivatePayloadIsAppendComplete(array $payload): void
    {
        $keys = $this->recursiveKeys($payload);
        foreach ([
            'recipe_snapshot_json',
            'component_snapshot_json',
            'ingredient_snapshot_json',
            'linked_product_id',
            'product_snapshot_json',
            'consumption_snapshot_json',
            'discount_id',
            'offer_id',
            'name_snapshot',
            'amount_type_snapshot',
        ] as $required) {
            $this->assertContains($required, $keys, "confirm_payload is missing {$required}");
        }

        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('"discount_id":501', $json);
        $this->assertStringContainsString('"discount_id":502', $json);
        $this->assertStringContainsString('"name_snapshot":"Contract line discount"', $json);
        $this->assertStringContainsString('"name_snapshot":"Contract order discount"', $json);
        $this->assertStringContainsString('"ingredient_id":301', $json);
        $this->assertStringContainsString('"product_id":102', $json);
    }

    /** @param array<string, mixed> $value @return list<string> */
    private function recursiveKeys(array $value): array
    {
        $keys = [];
        foreach ($value as $key => $child) {
            if (is_string($key)) {
                $keys[] = $key;
            }
            if (is_array($child)) {
                $keys = [...$keys, ...$this->recursiveKeys($child)];
            }
        }

        return array_values(array_unique($keys));
    }

    private function assertPrivatePayloadDidNotLeak(TestResponse $response): void
    {
        $json = (string) $response->getContent();
        foreach ([
            'confirm_payload',
            'recipe_snapshot_json',
            'component_snapshot_json',
            'ingredient_snapshot_json',
            'product_snapshot_json',
            'consumption_snapshot_json',
        ] as $privateKey) {
            $this->assertStringNotContainsString($privateKey, $json);
        }
    }

    private function assertNoOrderChildren(Order $order): void
    {
        $this->assertSame(0, OrderItem::query()->where('order_id', $order->id)->count());
        $this->assertSame(0, OrderDiscount::query()->where('order_id', $order->id)->count());
        $this->assertSame(0, OrderItemAddon::query()->whereIn(
            'order_item_id',
            OrderItem::query()->where('order_id', $order->id)->pluck('id'),
        )->count());
    }

    private function assertOrderIdentity(Order $order, string $phone, string $plate): void
    {
        $this->assertSame($plate, $order->plate_number);
        $this->assertSame(
            $phone,
            DB::table('pos_customers')->where('id', $order->customer_id)->value('phone'),
        );
    }

    private function rejectForIdentityRegression(QrOrderRound $round, Device $resolver): void
    {
        $presented = $this->app->make(RejectDineInQrRoundAction::class)->handle(
            $resolver,
            (int) $round->id,
        );

        $round->refresh();
        $this->assertSame(QrOrderRound::STATUS_REJECTED, $presented['round']['status']);
        $this->assertSame(now()->toIso8601String(), $presented['round']['resolved_at']);
        $this->assertSame(QrOrderRound::STATUS_REJECTED, $round->status);
        $this->assertSame(now()->toIso8601String(), $round->resolved_at?->toIso8601String());
        $this->assertSame((int) $resolver->id, (int) $round->resolved_by_device_id);
        $this->assertNull($round->confirm_payload);
        $this->assertStringNotContainsString(
            'confirm_payload',
            json_encode($presented, JSON_THROW_ON_ERROR),
        );
    }

    /** @return list<array<string, mixed>> */
    private function expectedPricedLines(): array
    {
        return [[
            'product_id' => 101,
            'product_name' => 'Contract coffee',
            'product_name_ar' => null,
            'qty' => 1,
            'notes' => 'No sugar',
            'base_price_baisas' => 5000,
            'unit_price_baisas' => 5750,
            'line_discount_baisas' => 1000,
            'line_total_baisas' => 5750,
            'addons' => [
                [
                    'add_on_id' => 201,
                    'name' => 'Extra milk',
                    'name_ar' => null,
                    'price_delta_baisas' => 500,
                ],
                [
                    'add_on_id' => 202,
                    'name' => 'Cup upgrade',
                    'name_ar' => null,
                    'price_delta_baisas' => 250,
                ],
            ],
        ]];
    }

    /**
     * @return array{
     *   station: Device,
     *   table: PosTable,
     *   session: QrSession,
     *   secret: string
     * }
     */
    private function openAndBindFlow(string $label): array
    {
        $station = $this->device('payment_station');
        $table = $this->table($label);
        $opened = $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $table->id])
            ->assertCreated();
        $session = QrSession::query()
            ->where('uuid', $opened->json('data.session_uuid'))
            ->sole();
        $secret = 's5-secret-'.strtolower($label);
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
     *   client_request_id: string,
     *   phone: string,
     *   plate_number: string|null,
     *   lines: list<array<string, mixed>>
     * }
     */
    private function roundPayload(
        string $requestId,
        string $phone,
        ?string $plate,
    ): array {
        return [
            'client_request_id' => $requestId,
            'phone' => $phone,
            'plate_number' => $plate,
            'lines' => [[
                'product_id' => 101,
                'qty' => 1,
                'addon_ids' => [201, 202],
                'notes' => 'No sugar',
            ]],
        ];
    }

    /** @param array<string, mixed> $flow */
    private function submitRound(array $flow, array $payload): TestResponse
    {
        return $this->withHeaders($this->qrHeaders($flow['session'], $flow['secret']))
            ->postJson(self::TABLE_ROUND_URL, $payload);
    }

    /** @param array<string, mixed> $flow */
    private function submitRoundFromIp(array $flow, array $payload, string $forwardedIp): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.80'])
            ->withHeaders($this->qrHeaders($flow['session'], $flow['secret']) + [
                ForwardedCustomerIp::AUTH_HEADER => self::BFF_SECRET,
                ForwardedCustomerIp::IP_HEADER => $forwardedIp,
            ])
            ->postJson(self::TABLE_ROUND_URL, $payload);
    }

    /** @param array<string, mixed> $flow */
    private function qrStatus(array $flow): TestResponse
    {
        return $this->withHeaders($this->qrHeaders($flow['session'], $flow['secret']))
            ->getJson(self::STATUS_URL);
    }

    /** @return array<string, string> */
    private function qrHeaders(QrSession $session, string $secret): array
    {
        return [
            'X-QR-Session' => (string) $session->uuid,
            'X-QR-Client-Secret' => $secret,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function postAs(Device $device, string $url, array $payload): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken((string) $device->plainTextToken)->postJson($url, $payload);
    }

    public function test_f32_customer_round_shared_addons_price_each_line(): void
    {
        $this->setRoundMode(DineInRoundMode::KITCHEN_DIRECT);
        $flow = $this->openAndBindFlow('F32');
        $payload = $this->roundPayload('f32-round', '90002001', null);
        $payload['lines'][] = array_replace($payload['lines'][0], ['notes' => 'second customer']);
        $response = $this->submitRound($flow, $payload)->assertCreated();
        $round = QrOrderRound::query()->sole();
        $this->assertCount(2, $round->priced_lines);
        $this->assertSame($round->priced_lines[0]['line_total_baisas'], $round->priced_lines[1]['line_total_baisas']);
        $this->assertSame(5750, $round->priced_lines[0]['line_total_baisas']);
        // Existing fixture: 1.000 per-line discount plus 0.250 order discount.
        $this->assertSame(2 * ($round->priced_lines[0]['line_total_baisas'] - 1000) - 250, $round->total_baisas);
        $this->assertSame(9250, $round->total_baisas);
        $payload['client_request_id'] = 'f32-duplicate';
        $payload['lines'][0]['addon_ids'][] = $payload['lines'][0]['addon_ids'][0];
        $this->submitRound($flow, $payload)->assertStatus(422);
        $this->assertDatabaseCount('pos_qr_order_rounds', 1);
    }

    private function setRoundMode(string $mode): void
    {
        $this->setRoundModeRaw(json_encode($mode, JSON_THROW_ON_ERROR));
    }

    private function setRoundModeRaw(string $raw): void
    {
        DB::table('pos_company_settings')->updateOrInsert(
            ['company_id' => 100, 'key' => 'dine_in_round_mode'],
            ['value' => $raw, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    private function setBranchRoundModeRaw(int $branchId, string $raw): void
    {
        DB::table('pos_branch_settings')->updateOrInsert(
            ['company_id' => 100, 'branch_id' => $branchId, 'key' => 'dine_in_round_mode'],
            ['value' => $raw, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    private function createSiblingBranch(): void
    {
        Branch::query()->create([
            'id' => 11,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'S5 sibling branch',
            'latitude' => null,
            'longitude' => null,
            'geofence_radius_m' => 500,
            'status' => 'active',
        ]);
    }

    private function device(string $type): Device
    {
        $this->deviceSequence++;

        return Device::factory()
            ->paired('mdev_s5_contract_'.$this->deviceSequence)
            ->create([
                'company_id' => 100,
                'branch_id' => 10,
                'device_type' => $type,
                'terminal_id' => $type === 'payment_station'
                    ? 'S5-TERM-'.$this->deviceSequence
                    : null,
            ]);
    }

    private function table(string $label): PosTable
    {
        $this->tableSequence++;
        $floor = Floor::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => 10,
            'name' => 'S5 floor '.$this->tableSequence,
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
            'qr_token' => hash('sha256', 's5-'.$label.'-'.$this->tableSequence),
            'status' => 'active',
            'display_order' => $this->tableSequence,
        ]);
    }

    private function seedPricingCatalogue(): void
    {
        $timestamps = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_ingredients')->insert([
            'id' => 301,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Contract milk',
            'unit' => 'l',
            'default_unit_cost' => '0.400',
            'status' => 'active',
        ] + $timestamps);
        DB::table('pos_products')->insert([
            [
                'id' => 101,
                'uuid' => (string) Str::uuid(),
                'company_id' => 100,
                'category_id' => null,
                'name' => 'Contract coffee',
                'base_price' => '5.000',
                'stock_mode' => 'ingredient',
                'display_order' => 1,
                'status' => 'active',
                'show_on_customer_tablet' => true,
                'is_internal' => false,
                'deleted_at' => null,
            ] + $timestamps,
            [
                'id' => 102,
                'uuid' => (string) Str::uuid(),
                'company_id' => 100,
                'category_id' => null,
                'name' => 'Contract cup',
                'base_price' => '0.100',
                'stock_mode' => 'unit',
                'display_order' => 2,
                'status' => 'active',
                'show_on_customer_tablet' => true,
                'is_internal' => false,
                'deleted_at' => null,
            ] + $timestamps,
        ]);
        DB::table('pos_branch_product')->insert([
            [
                'branch_id' => 10,
                'product_id' => 101,
                'is_available' => true,
                'stock_qty' => null,
            ] + $timestamps,
            [
                'branch_id' => 10,
                'product_id' => 102,
                'is_available' => true,
                'stock_qty' => '100.000',
            ] + $timestamps,
        ]);
        DB::table('pos_branch_stock')->insert([
            'branch_id' => 10,
            'ingredient_id' => 301,
            'quantity' => '100.000',
        ] + $timestamps);
        DB::table('pos_product_recipes')->insert([
            'product_id' => 101,
            'ingredient_id' => 301,
            'quantity' => '0.250',
            'unit_at_set' => 'l',
            'sort_order' => 1,
        ] + $timestamps);
        DB::table('pos_product_components')->insert([
            'product_id' => 101,
            'component_product_id' => 102,
            'quantity' => '1.000',
        ] + $timestamps);

        DB::table('pos_addon_groups')->insert([
            'id' => 401,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Contract options',
            'selection_mode' => 'multiple',
            'min_selections' => 0,
            'max_selections' => 2,
            'is_global' => false,
            'display_order' => 1,
            'status' => 'active',
        ] + $timestamps);
        DB::table('pos_addons')->insert([
            [
                'id' => 201,
                'uuid' => (string) Str::uuid(),
                'company_id' => 100,
                'add_on_group_id' => 401,
                'name' => 'Extra milk',
                'price_delta' => '0.500',
                'ingredient_id' => 301,
                'ingredient_qty' => '0.050',
                'ingredient_unit' => 'l',
                'linked_product_id' => null,
                'display_order' => 1,
                'status' => 'active',
                'deleted_at' => null,
            ] + $timestamps,
            [
                'id' => 202,
                'uuid' => (string) Str::uuid(),
                'company_id' => 100,
                'add_on_group_id' => 401,
                'name' => 'Cup upgrade',
                'price_delta' => '0.250',
                'ingredient_id' => null,
                'ingredient_qty' => null,
                'ingredient_unit' => null,
                'linked_product_id' => 102,
                'display_order' => 2,
                'status' => 'active',
                'deleted_at' => null,
            ] + $timestamps,
        ]);
        DB::table('pos_addon_group_products')->insert([
            'add_on_group_id' => 401,
            'product_id' => 101,
            'display_order' => 1,
        ] + $timestamps);
        DB::table('pos_addon_consumptions')->insert([
            'add_on_id' => 202,
            'ingredient_id' => 301,
            'component_product_id' => null,
            'direction' => 'add',
            'quantity' => '0.010',
            'unit' => 'l',
            'display_order' => 1,
        ] + $timestamps);

        DB::table('pos_discounts')->insert([
            [
                'id' => 501,
                'uuid' => (string) Str::uuid(),
                'company_id' => 100,
                'name' => 'Contract line discount',
                'scope' => 'product',
                'amount_type' => 'fixed',
                'amount' => '1.000',
                'stackable' => true,
                'requires_manager_approval' => false,
                'auto_apply' => true,
                'status' => 'active',
                'deleted_at' => null,
            ] + $timestamps,
            [
                'id' => 502,
                'uuid' => (string) Str::uuid(),
                'company_id' => 100,
                'name' => 'Contract order discount',
                'scope' => 'order',
                'amount_type' => 'fixed',
                'amount' => '0.250',
                'stackable' => true,
                'requires_manager_approval' => false,
                'auto_apply' => true,
                'status' => 'active',
                'deleted_at' => null,
            ] + $timestamps,
        ]);
        DB::table('pos_discount_targets')->insert([
            'discount_id' => 501,
            'target_type' => 'product',
            'target_id' => 101,
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
