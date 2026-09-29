<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Floor;
use App\Models\Order;
use App\Models\Payment;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\Table as PosTable;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Adversarial S5 invariants that cross the submission, staff-resolution,
 * settlement, inventory, identity, and table-occupancy boundaries.
 */
final class QrDineInStaffConfirmInvariantsTest extends TestCase
{
    use RefreshDatabase;

    private const CLAIM_SETTLEMENT_URL = '/api/v1/device/qr/claim-settlement';

    private const CLEAR_TABLE_URL = '/api/v1/device/qr/clear-table';

    private const CONFIRM_ROUND_URL = '/api/v1/device/qr/confirm-round';

    private const OPEN_TABLE_URL = '/api/v1/device/qr/open-table';

    private const SYNC_URL = '/api/v1/device/sync/push';

    private const TABLE_BIND_URL = '/api/v1/public/qr/table-bind';

    private const TABLE_FINISH_URL = '/api/v1/public/qr/table-finish';

    private const TABLE_ROUND_URL = '/api/v1/public/qr/table-round';

    private int $deviceSequence = 0;

    private int $tableSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-31 14:00:00 UTC'));
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
            'name' => 'S5 invariant branch',
            'latitude' => null,
            'longitude' => null,
            'geofence_radius_m' => 500,
            'status' => 'active',
        ]);
        Branch::query()->create([
            'id' => 20,
            'uuid' => (string) Str::uuid(),
            'company_id' => 200,
            'name' => 'Other tenant branch',
            'latitude' => null,
            'longitude' => null,
            'geofence_radius_m' => 500,
            'status' => 'active',
        ]);

        $this->seedPricingCatalogue();
        $this->enableOrderNumbering();
    }

    public function test_staff_confirm_matches_kitchen_direct_children_inventory_and_ledger_algebra(): void
    {
        $till = $this->device('fixed_pos');

        $direct = $this->buildTwoAcceptedRounds('DIRECT', $till, false);
        $this->assertAcceptedLedgerAlgebra($direct['order'], 2);
        $directChildren = $this->costBearingChildren($direct['order']);
        $this->settleAndPay($till, $direct['order']);
        $directIngredientMovements = $this->ingredientMovements($direct['order']);
        $directProductMovements = $this->productMovements($direct['order']);

        $this->setRoundMode('staff_confirm');
        $staff = $this->buildTwoAcceptedRounds('STAFF', $till, true);
        $this->assertAcceptedLedgerAlgebra($staff['order'], 2);

        $this->assertSame(
            $directChildren,
            $this->costBearingChildren($staff['order']),
            'Staff confirmation did not append the exact kitchen-direct child ledger.',
        );

        $this->settleAndPay($till, $staff['order']);
        $this->assertSame(
            $directIngredientMovements,
            $this->ingredientMovements($staff['order']),
            'Ingredient consumption differs between kitchen-direct and staff-confirm.',
        );
        $this->assertSame(
            $directProductMovements,
            $this->productMovements($staff['order']),
            'Product/component consumption differs between kitchen-direct and staff-confirm.',
        );

        $ingredientMovementSum = (float) DB::table('pos_stock_movements')
            ->where('branch_id', 10)
            ->where('ingredient_id', 301)
            ->sum('quantity');
        $ingredientBalance = (float) DB::table('pos_branch_stock')
            ->where('branch_id', 10)
            ->where('ingredient_id', 301)
            ->value('quantity');
        $this->assertEqualsWithDelta(
            100.0 + $ingredientMovementSum,
            $ingredientBalance,
            0.000001,
            'Ingredient balance diverged from its signed movement ledger.',
        );

        $productMovementSum = (float) DB::table('pos_product_stock_movements')
            ->where('branch_id', 10)
            ->where('product_id', 102)
            ->sum('quantity');
        $productBalance = (float) DB::table('pos_branch_product')
            ->where('branch_id', 10)
            ->where('product_id', 102)
            ->value('stock_qty');
        $this->assertEqualsWithDelta(
            100.0 + $productMovementSum,
            $productBalance,
            0.000001,
            'Product balance diverged from its signed movement ledger.',
        );
        $this->assertSame(Order::STATUS_PAID, $direct['order']->fresh()->status);
        $this->assertSame(Order::STATUS_PAID, $staff['order']->fresh()->status);
        foreach ([$direct['order'], $staff['order']] as $order) {
            $seating = $order->fresh()->tableSession()->sole();
            $this->assertSame('closed', $seating->status);
            $this->assertSame('paid', $seating->close_reason);
            $this->assertSame((int) $till->id, (int) $seating->closed_by_device_id);
            $this->assertSame(
                [(int) $seating->id],
                QrOrderRound::query()->where('order_id', $order->id)
                    ->distinct()->pluck('table_session_id')->map(static fn ($id): int => (int) $id)->all(),
            );
        }
    }

    public function test_after_an_accept_an_identity_free_round_succeeds_but_phone_or_plate_is_classified(): void
    {
        $this->setRoundMode('staff_confirm');
        $till = $this->device('fixed_pos');
        $flow = $this->openAndBind('IDENTITY-AFTER-ACCEPT');

        $first = $this->submitRound(
            $flow,
            $this->roundPayload('identity-accepted', '92001001', 'OM 9001'),
        )->assertCreated();
        $firstRound = QrOrderRound::query()
            ->where('client_request_id', 'identity-accepted')
            ->sole();
        $this->confirmRound($till, $firstRound)->assertOk();

        $this->submitRound(
            $flow,
            $this->roundPayload('identity-free-later'),
        )->assertCreated()
            ->assertJsonPath('data.round.status', QrOrderRound::STATUS_PENDING_CONFIRMATION)
            ->assertJsonPath('data.round.round_no', 2)
            ->assertJsonPath('data.order.uuid', $first->json('data.order.uuid'));

        $this->submitRound(
            $flow,
            $this->roundPayload('identity-phone-refused', '92001002'),
        )->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'qr_round_identity_already_set');

        $platePayload = $this->roundPayload('identity-plate-refused');
        $platePayload['plate_number'] = 'OM 9002';
        $this->submitRound($flow, $platePayload)
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'qr_round_identity_already_set');

        $this->assertSame(
            2,
            QrOrderRound::query()->where('qr_session_id', $flow['session']->id)->count(),
        );
    }

    public function test_terminal_winners_make_late_confirmation_not_pending_and_double_confirm_is_safe(): void
    {
        $this->setRoundMode('staff_confirm');
        $till = $this->device('fixed_pos');

        $claim = $this->acceptedAndPendingFlow('CLAIM-WINS', $till);
        $this->claimSettlement($till, $claim['order'])->assertOk();
        $this->assertLateConfirmIsNotPending($till, $claim['pending']);

        $finish = $this->acceptedAndPendingFlow('FINISH-WINS', $till);
        $this->withHeaders($this->qrHeaders($finish['flow']))
            ->postJson(self::TABLE_FINISH_URL, ['payment_choice' => 'counter'])
            ->assertOk()
            ->assertJsonPath('data.status', Order::STATUS_HELD);
        $this->assertLateConfirmIsNotPending($till, $finish['pending']);

        $void = $this->acceptedAndPendingFlow('VOID-WINS', $till);
        $this->push($till, [$this->voidEvent($void['order'])])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame(Order::STATUS_VOID, $void['order']->fresh()->status);
        $this->assertLateConfirmIsNotPending($till, $void['pending']);

        // Clear itself is reachable only after the unpaid order is terminal.
        // The preceding claim/pay transaction is the terminal winner and
        // rejects the pending row before clear frees the table.
        $clear = $this->acceptedAndPendingFlow('CLEAR-AFTER-PAY', $till);
        $this->settleAndPay($till, $clear['order']);
        $this->postAs($till, self::CLEAR_TABLE_URL, [
            'table_id' => $clear['flow']['table']->id,
        ])->assertOk()
            ->assertJsonPath('data.status', 'cleared');
        $this->assertLateConfirmIsNotPending($till, $clear['pending']);

        $double = $this->openAndBind('DOUBLE-CONFIRM');
        $this->submitRound(
            $double,
            $this->roundPayload('double-confirm', '92001009', null),
        )->assertCreated();
        $round = QrOrderRound::query()->where('client_request_id', 'double-confirm')->sole();
        $this->confirmRound($till, $round)->assertOk();
        $this->confirmRound($till, $round)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_round_not_pending');
        $this->assertSame(1, DB::table('pos_order_items')
            ->where('order_id', $round->order_id)
            ->count());
    }

    public function test_occupancy_merge_blocks_only_same_tenant_unpaid_primary_or_pivot_rows(): void
    {
        $station = $this->device('payment_station');
        $sameTenantTill = $this->device('fixed_pos');
        $otherTenantTill = $this->device('fixed_pos', 20, 200);

        foreach ([Order::STATUS_PAID, Order::STATUS_VOID] as $status) {
            $table = $this->createTable('SAFE-'.strtoupper($status));
            $this->rawOrder($sameTenantTill, $table->id, $status);
            $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $table->id])
                ->assertCreated();
        }

        $foreignPrimary = $this->createTable('SAFE-FOREIGN-PRIMARY');
        $this->rawOrder($otherTenantTill, $foreignPrimary->id, Order::STATUS_OPEN);
        $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $foreignPrimary->id])
            ->assertCreated();

        $foreignPivot = $this->createTable('SAFE-FOREIGN-PIVOT');
        $foreignOrder = $this->rawOrder($otherTenantTill, null, Order::STATUS_OPEN);
        $this->attachTable($foreignOrder, $foreignPivot);
        $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $foreignPivot->id])
            ->assertCreated();

        $blockedPrimary = $this->createTable('BLOCKED-PRIMARY');
        $this->rawOrder($sameTenantTill, $blockedPrimary->id, Order::STATUS_OPEN);
        $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $blockedPrimary->id])
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_table_has_unpaid_order');

        $blockedPivot = $this->createTable('BLOCKED-PIVOT');
        $sameTenantOrder = $this->rawOrder($sameTenantTill, null, Order::STATUS_HELD);
        $this->attachTable($sameTenantOrder, $blockedPivot);
        $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $blockedPivot->id])
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_table_has_unpaid_order');
    }

    /**
     * @return array{order: Order, flow: array<string, mixed>, rounds: list<QrOrderRound>}
     */
    private function buildTwoAcceptedRounds(string $label, Device $till, bool $staffConfirm): array
    {
        $flow = $this->openAndBind($label);
        $firstId = strtolower($label).'-round-one';
        $secondId = strtolower($label).'-round-two';
        $first = $this->submitRound(
            $flow,
            $this->roundPayload($firstId, '92002001', 'OM 2001'),
        )->assertCreated();
        $firstRound = QrOrderRound::query()->where('client_request_id', $firstId)->sole();
        if ($staffConfirm) {
            $this->confirmRound($till, $firstRound)->assertOk();
        }

        $this->submitRound($flow, $this->roundPayload($secondId))->assertCreated();
        $secondRound = QrOrderRound::query()->where('client_request_id', $secondId)->sole();
        if ($staffConfirm) {
            $this->confirmRound($till, $secondRound)->assertOk();
        }

        $order = Order::query()->where('uuid', $first->json('data.order.uuid'))->sole();
        $rounds = QrOrderRound::query()
            ->where('order_id', $order->id)
            ->orderBy('round_no')
            ->get()
            ->all();
        $this->assertSame(
            [QrOrderRound::STATUS_ACCEPTED, QrOrderRound::STATUS_ACCEPTED],
            array_map(static fn (QrOrderRound $round): string => $round->status, $rounds),
        );

        return compact('order', 'flow', 'rounds');
    }

    /**
     * @return array{
     *   order: Order,
     *   pending: QrOrderRound,
     *   flow: array<string, mixed>
     * }
     */
    private function acceptedAndPendingFlow(string $label, Device $till): array
    {
        $flow = $this->openAndBind($label);
        $acceptedId = strtolower($label).'-accepted';
        $pendingId = strtolower($label).'-pending';
        $first = $this->submitRound(
            $flow,
            $this->roundPayload($acceptedId, '92003001', null),
        )->assertCreated();
        $accepted = QrOrderRound::query()->where('client_request_id', $acceptedId)->sole();
        $this->confirmRound($till, $accepted)->assertOk();
        $this->submitRound($flow, $this->roundPayload($pendingId))->assertCreated();

        return [
            'order' => Order::query()->where('uuid', $first->json('data.order.uuid'))->sole(),
            'pending' => QrOrderRound::query()->where('client_request_id', $pendingId)->sole(),
            'flow' => $flow,
        ];
    }

    private function assertAcceptedLedgerAlgebra(Order $order, int $expectedRounds): void
    {
        $order->refresh();
        $rounds = QrOrderRound::query()
            ->where('order_id', $order->id)
            ->where('status', QrOrderRound::STATUS_ACCEPTED)
            ->get();
        $this->assertCount($expectedRounds, $rounds);
        $this->assertSame($expectedRounds, $rounds->pluck('accepted_seq')->filter()->unique()->count());

        $acceptedBaisas = (int) $rounds->sum('total_baisas');
        $itemGrossBaisas = $this->omrToBaisas(DB::table('pos_order_items')
            ->where('order_id', $order->id)
            ->sum('line_total'));
        $discountBaisas = $this->omrToBaisas(DB::table('pos_order_discounts')
            ->where('order_id', $order->id)
            ->sum('amount'));
        $headerSubtotalBaisas = Money::toBaisas($order->subtotal);
        $headerDiscountBaisas = Money::toBaisas($order->discount_total);
        $headerTaxBaisas = Money::toBaisas($order->tax_total);
        $headerGrandBaisas = Money::toBaisas($order->grand_total);

        $this->assertSame($itemGrossBaisas, $headerSubtotalBaisas);
        $this->assertSame($discountBaisas, $headerDiscountBaisas);
        $this->assertSame(
            $itemGrossBaisas - $discountBaisas + $headerTaxBaisas,
            $headerGrandBaisas,
        );
        $this->assertSame($acceptedBaisas, $headerGrandBaisas);
        $this->assertSame($expectedRounds, DB::table('pos_order_items')
            ->where('order_id', $order->id)
            ->count());
        $this->assertSame($expectedRounds * 2, DB::table('pos_order_discounts')
            ->where('order_id', $order->id)
            ->count());
    }

    /** @return array<string, mixed> */
    private function costBearingChildren(Order $order): array
    {
        $items = DB::table('pos_order_items')
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get();
        $itemIndexes = [];
        $itemRows = [];
        foreach ($items as $index => $item) {
            $itemIndexes[(int) $item->id] = $index;
            $addons = DB::table('pos_order_item_addons')
                ->where('order_item_id', $item->id)
                ->orderBy('id')
                ->get()
                ->map(fn (object $addon): array => [
                    'add_on_id' => $addon->add_on_id === null ? null : (int) $addon->add_on_id,
                    'name' => (string) $addon->add_on_name_snapshot,
                    'price_delta' => $this->decimal($addon->price_delta_snapshot),
                    'ingredient' => $this->decodeJson($addon->ingredient_snapshot_json),
                    'linked_product_id' => $addon->linked_product_id === null
                        ? null
                        : (int) $addon->linked_product_id,
                    'product' => $this->decodeJson($addon->product_snapshot_json),
                    'consumption' => $this->decodeJson($addon->consumption_snapshot_json),
                ])
                ->all();
            $itemRows[] = [
                'product_id' => (int) $item->product_id,
                'name' => (string) $item->product_name_snapshot,
                'qty' => $this->decimal($item->qty),
                'unit_price' => $this->decimal($item->unit_price_snapshot),
                'line_discount' => $this->decimal($item->line_discount),
                'line_total' => $this->decimal($item->line_total),
                'recipe' => $this->decodeJson($item->recipe_snapshot_json),
                'components' => $this->decodeJson($item->component_snapshot_json),
                'status' => (string) $item->status,
                'notes' => $item->notes,
                'addons' => $addons,
            ];
        }

        $discountRows = DB::table('pos_order_discounts')
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get()
            ->map(fn (object $discount): array => [
                'item_index' => $discount->order_item_id === null
                    ? null
                    : $itemIndexes[(int) $discount->order_item_id],
                'discount_id' => $discount->discount_id === null
                    ? null
                    : (int) $discount->discount_id,
                'offer_id' => $discount->offer_id === null ? null : (int) $discount->offer_id,
                'name' => (string) $discount->name_snapshot,
                'amount_type' => $discount->amount_type_snapshot,
                'amount' => $this->decimal($discount->amount),
                'reason' => $discount->reason,
                'applied_at' => (string) $discount->applied_at,
            ])
            ->all();

        return ['items' => $itemRows, 'discounts' => $discountRows];
    }

    /** @return list<array<string, mixed>> */
    private function ingredientMovements(Order $order): array
    {
        return DB::table('pos_stock_movements')
            ->where('reference_type', 'pos_orders')
            ->where('reference_id', $order->id)
            ->orderBy('ingredient_id')
            ->orderBy('movement_type')
            ->orderBy('id')
            ->get()
            ->map(fn (object $movement): array => [
                'ingredient_id' => (int) $movement->ingredient_id,
                'movement_type' => (string) $movement->movement_type,
                'quantity' => $this->decimal($movement->quantity),
                'unit_cost' => $this->decimal($movement->unit_cost_at_time),
            ])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function productMovements(Order $order): array
    {
        return DB::table('pos_product_stock_movements')
            ->where('reference_type', 'pos_orders')
            ->where('reference_id', $order->id)
            ->orderBy('product_id')
            ->orderBy('movement_type')
            ->orderBy('note')
            ->orderBy('id')
            ->get()
            ->map(fn (object $movement): array => [
                'product_id' => (int) $movement->product_id,
                'movement_type' => (string) $movement->movement_type,
                'quantity' => $this->decimal($movement->quantity),
                'note' => $movement->note,
            ])
            ->all();
    }

    private function settleAndPay(Device $till, Order $order): void
    {
        $amount = Money::toBaisas($order->fresh()->grand_total);
        $this->claimSettlement($till, $order)
            ->assertOk()
            ->assertJsonPath('data.charge_amount_baisas', $amount);
        $this->push($till, [$this->payEvent($order, $amount)])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed');
    }

    private function assertLateConfirmIsNotPending(Device $till, QrOrderRound $round): void
    {
        $round->refresh();
        $this->assertSame(QrOrderRound::STATUS_REJECTED, $round->status);
        $this->assertNull($round->confirm_payload);
        $this->confirmRound($till, $round)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_round_not_pending');
    }

    /** @return array{station: Device, table: PosTable, session: QrSession, secret: string} */
    private function openAndBind(string $label): array
    {
        $station = $this->device('payment_station');
        $table = $this->createTable($label);
        $opened = $this->postAs($station, self::OPEN_TABLE_URL, ['table_id' => $table->id])
            ->assertCreated();
        $session = QrSession::query()
            ->where('uuid', $opened->json('data.session_uuid'))
            ->sole();
        $secret = 's5-invariant-'.strtolower($label);
        $this->postJson(self::TABLE_BIND_URL, [
            'table_token' => $table->qr_token,
            'client_secret' => $secret,
        ])->assertOk()
            ->assertJsonPath('data.session_uuid', $session->uuid);

        return compact('station', 'table', 'session', 'secret');
    }

    /** @param array<string, mixed> $flow @param array<string, mixed> $payload */
    private function submitRound(array $flow, array $payload): TestResponse
    {
        return $this->withHeaders($this->qrHeaders($flow))
            ->postJson(self::TABLE_ROUND_URL, $payload);
    }

    private function confirmRound(Device $till, QrOrderRound $round): TestResponse
    {
        return $this->postAs($till, self::CONFIRM_ROUND_URL, ['round_id' => $round->id]);
    }

    private function claimSettlement(Device $till, Order $order): TestResponse
    {
        return $this->postAs($till, self::CLAIM_SETTLEMENT_URL, [
            'order_uuid' => $order->uuid,
        ]);
    }

    /** @param list<array<string, mixed>> $events */
    private function push(Device $device, array $events): TestResponse
    {
        return $this->postAs($device, self::SYNC_URL, ['events' => $events]);
    }

    /** @return array<string, mixed> */
    private function roundPayload(
        string $requestId,
        ?string $phone = null,
        ?string $plate = null,
    ): array {
        $payload = [
            'client_request_id' => $requestId,
            'lines' => [[
                'product_id' => 101,
                'qty' => 1,
                'addon_ids' => [201, 202],
                'notes' => 'No sugar',
            ]],
        ];
        if ($phone !== null) {
            $payload['phone'] = $phone;
        }
        if ($plate !== null) {
            $payload['plate_number'] = $plate;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function payEvent(Order $order, int $amountBaisas): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => $order->uuid,
                'paid_at' => now()->toIso8601String(),
                'payments' => [[
                    'method' => Payment::METHOD_CASH,
                    'amount_baisas' => $amountBaisas,
                    'change_given_baisas' => 0,
                    'status' => Payment::STATUS_SUCCESS,
                ]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function voidEvent(Order $order): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'order_uuid' => $order->uuid,
                'voided_at' => now()->toIso8601String(),
                'reason' => 'S5 invariant terminal winner',
            ],
        ];
    }

    /** @param array<string, mixed> $flow @return array<string, string> */
    private function qrHeaders(array $flow): array
    {
        return [
            'X-QR-Session' => (string) $flow['session']->uuid,
            'X-QR-Client-Secret' => (string) $flow['secret'],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function postAs(Device $device, string $url, array $payload): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken((string) $device->plainTextToken)->postJson($url, $payload);
    }

    private function setRoundMode(string $mode): void
    {
        DB::table('pos_company_settings')->updateOrInsert(
            ['company_id' => 100, 'key' => 'dine_in_round_mode'],
            [
                'value' => json_encode($mode, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function device(
        string $type,
        int $branchId = 10,
        int $companyId = 100,
    ): Device {
        $this->deviceSequence++;

        return Device::factory()
            ->paired('mdev_s5_invariant_'.$this->deviceSequence)
            ->create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'device_type' => $type,
                'terminal_id' => $type === 'payment_station'
                    ? 'S5-INV-'.$this->deviceSequence
                    : null,
            ]);
    }

    private function createTable(string $label): PosTable
    {
        $this->tableSequence++;
        $floor = Floor::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => 10,
            'name' => 'S5 invariant floor '.$this->tableSequence,
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
            'qr_token' => hash('sha256', 's5-invariant-'.$label.'-'.$this->tableSequence),
            'status' => 'active',
            'display_order' => $this->tableSequence,
        ]);
    }

    private function rawOrder(Device $device, ?int $tableId, string $status): Order
    {
        return Order::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $device->company_id,
            'branch_id' => $device->branch_id,
            'device_id' => $device->id,
            'client_request_id' => (string) Str::uuid(),
            'table_id' => $tableId,
            'order_type' => 'dine_in',
            'status' => $status,
            'source' => 'main_pos',
            'subtotal' => '0.000',
            'discount_total' => '0.000',
            'comp_total' => '0.000',
            'tax_total' => '0.000',
            'grand_total' => '0.000',
            'opened_at' => now(),
            'closed_at' => in_array($status, [Order::STATUS_PAID, Order::STATUS_VOID], true)
                ? now()
                : null,
        ]);
    }

    private function attachTable(Order $order, PosTable $table): void
    {
        DB::table('pos_order_tables')->insert([
            'order_id' => $order->id,
            'table_id' => $table->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    private function omrToBaisas(mixed $value): int
    {
        return (int) round((float) $value * 1000);
    }

    private function decodeJson(mixed $value): mixed
    {
        if ($value === null || is_array($value)) {
            return $value;
        }

        return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }

    private function seedPricingCatalogue(): void
    {
        $timestamps = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_ingredients')->insert([
            'id' => 301,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Invariant milk',
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
                'name' => 'Invariant coffee',
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
                'name' => 'Invariant cup',
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
            'name' => 'Invariant options',
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
                'name' => 'Invariant line discount',
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
                'name' => 'Invariant order discount',
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
