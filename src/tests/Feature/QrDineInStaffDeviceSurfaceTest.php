<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Device;
use App\Models\Floor;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\Table as PosTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class QrDineInStaffDeviceSurfaceTest extends TestCase
{
    use RefreshDatabase;

    private const BOARD_URL = '/api/v1/device/qr/table-board';

    private const CONFIRM_URL = '/api/v1/device/qr/confirm-round';

    private const REJECT_URL = '/api/v1/device/qr/reject-round';

    private const DETAIL_URL = '/api/v1/device/qr/table-round/';

    private const FEED_URL = '/api/v1/device/qr/accepted-rounds';

    private int $deviceSequence = 0;

    private int $orderSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-08-31 12:00:00'));
        foreach ([10, 20] as $branchId) {
            Branch::query()->create([
                'id' => $branchId,
                'uuid' => (string) Str::uuid(),
                'company_id' => 100,
                'name' => 'S5 Branch '.$branchId,
                'latitude' => null,
                'longitude' => null,
                'geofence_radius_m' => 500,
                'status' => 'active',
            ]);
        }
    }

    public function test_round_detail_confirm_and_reject_are_attended_tenant_scoped_and_private(): void
    {
        $station = $this->device('payment_station');
        $till = $this->device('fixed_pos');
        $wrongBranchTill = $this->device('fixed_pos', 20);
        $table = $this->table(10, 'A-01');
        $session = $this->qrSession($station, $table);
        $order = $this->order($station, $session, $table);
        $round = $this->round(
            $session,
            $order,
            QrOrderRound::STATUS_PENDING_CONFIRMATION,
            now()->subMinute(),
            $this->confirmPayload(),
        );

        $this->getAs($station, self::DETAIL_URL.$round->id)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'device_not_attended');
        $this->getAs($wrongBranchTill, self::DETAIL_URL.$round->id)
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'qr_round_not_found');

        $detail = $this->getAs($till, self::DETAIL_URL.$round->id)
            ->assertOk()
            ->assertJsonPath('data.round.id', $round->id)
            ->assertJsonPath('data.round.priced_lines.0.notes', 'No sugar')
            ->assertJsonPath('data.table_label', 'A-01')
            ->assertJsonPath('data.receipt_number', $order->receipt_number);
        $detail->assertDontSee('PRIVATE-COST');
        $this->assertArrayNotHasKey('confirm_payload', (array) $detail->json('data.round'));

        $confirmed = $this->postAs($till, self::CONFIRM_URL, ['round_id' => $round->id])
            ->assertOk()
            ->assertJsonPath('data.round.status', QrOrderRound::STATUS_ACCEPTED)
            ->assertJsonPath('data.order.grand_total_baisas', 4750)
            ->assertJsonPath('meta.money_unit', 'baisas');
        $confirmed->assertDontSee('PRIVATE-COST');

        $round->refresh();
        $this->assertSame(QrOrderRound::STATUS_ACCEPTED, $round->status);
        $this->assertSame((int) $till->id, (int) $round->resolved_by_device_id);
        $this->assertNotNull($round->accepted_seq);
        $this->assertNull($round->confirm_payload);
        $this->assertDatabaseHas('pos_order_items', [
            'order_id' => $order->id,
            'product_name_snapshot' => 'Frozen coffee',
            'line_total' => '4.750',
        ]);
        $this->assertSame('4.750', $order->fresh()->grand_total);

        $this->postAs($till, self::CONFIRM_URL, ['round_id' => $round->id])
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_round_not_pending');

        $expired = $this->qrSession($station, $this->table(10, 'A-02'), QrSession::STATUS_EXPIRED);
        $expiredOrder = $this->order($station, $expired, $expired->table);
        $expiredRound = $this->round(
            $expired,
            $expiredOrder,
            QrOrderRound::STATUS_PENDING_CONFIRMATION,
            now()->subMinute(),
            $this->confirmPayload(),
        );

        $this->postAs($till, self::CONFIRM_URL, ['round_id' => $expiredRound->id])
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'qr_session_expired');
        $this->assertSame(
            QrOrderRound::STATUS_PENDING_CONFIRMATION,
            $expiredRound->fresh()->status,
        );

        $this->postAs($till, self::REJECT_URL, ['round_id' => $expiredRound->id])
            ->assertOk()
            ->assertJsonPath('data.round.status', QrOrderRound::STATUS_REJECTED);
        $this->assertNull($expiredRound->fresh()->confirm_payload);
        $this->assertSame(Order::STATUS_OPEN, $expiredOrder->fresh()->status);
    }

    public function test_feed_cursor_follows_acceptance_sequence_for_out_of_order_same_second_confirms(): void
    {
        $station = $this->device('payment_station');
        $till = $this->device('fixed_pos');
        $table = $this->table(10, 'B-01');
        $session = $this->qrSession($station, $table);
        $order = $this->order($station, $session, $table);
        $roundA = $this->round(
            $session,
            $order,
            QrOrderRound::STATUS_PENDING_CONFIRMATION,
            null,
            $this->confirmPayload(),
        );
        $roundB = $this->round(
            $session,
            $order,
            QrOrderRound::STATUS_PENDING_CONFIRMATION,
            null,
            $this->confirmPayload(),
        );

        $this->postAs($till, self::CONFIRM_URL, ['round_id' => $roundB->id])
            ->assertOk();

        $first = $this->getAs($till, self::FEED_URL)
            ->assertOk()
            ->assertJsonCount(1, 'data.rounds')
            ->assertJsonPath('data.rounds.0.id', $roundB->id)
            ->assertJsonPath('meta.skipped_expired_count', 0);
        $cursor = (string) $first->json('meta.next_cursor');

        // A has the lower row id and the exact same wall-clock resolved_at,
        // but its later accepted_seq must put it after the landed cursor.
        $this->postAs($till, self::CONFIRM_URL, ['round_id' => $roundA->id])
            ->assertOk();

        $second = $this->getAs(
            $till,
            self::FEED_URL.'?'.http_build_query(['after' => $cursor]),
        )->assertOk()
            ->assertJsonCount(1, 'data.rounds')
            ->assertJsonPath('data.rounds.0.id', $roundA->id);

        $roundA->refresh();
        $roundB->refresh();
        $this->assertLessThan($roundA->accepted_seq, $roundB->accepted_seq);
        $this->assertSame(
            $roundA->resolved_at?->format('Y-m-d H:i:s'),
            $roundB->resolved_at?->format('Y-m-d H:i:s'),
        );
        $this->assertSame($second->json('meta.next_cursor'), $second->json('meta.latest_cursor'));
    }

    public function test_empty_branch_genesis_cursor_reports_first_acceptance_missed_past_horizon(): void
    {
        $station = $this->device('payment_station');
        $till = $this->device('fixed_pos');
        $table = $this->table(10, 'B-00');
        $session = $this->qrSession($station, $table);
        $order = $this->order($station, $session, $table);

        $empty = $this->getAs($till, self::FEED_URL)
            ->assertOk()
            ->assertJsonCount(0, 'data.rounds')
            ->assertJsonPath('meta.next_cursor', null)
            ->assertJsonPath('meta.skipped_expired_count', 0);
        $genesisCursor = $empty->json('meta.latest_cursor');
        $this->assertIsString($genesisCursor);
        $this->assertNotSame('', $genesisCursor);

        $missedRound = $this->round(
            $session,
            $order,
            QrOrderRound::STATUS_ACCEPTED,
            now()->subHours(7),
        );

        $missed = $this->getAs(
            $till,
            self::FEED_URL.'?'.http_build_query(['after' => $genesisCursor]),
        )->assertOk()
            ->assertJsonCount(0, 'data.rounds')
            ->assertJsonPath('meta.next_cursor', null)
            ->assertJsonPath('meta.skipped_expired_count', 1);
        $highWaterCursor = $missed->json('meta.latest_cursor');
        $this->assertIsString($highWaterCursor);
        $this->assertNotSame($genesisCursor, $highWaterCursor);
        $this->assertNotNull($missedRound->accepted_seq);

        $this->getAs(
            $till,
            self::FEED_URL.'?'.http_build_query(['after' => $highWaterCursor]),
        )->assertOk()
            ->assertJsonCount(0, 'data.rounds')
            ->assertJsonPath('meta.skipped_expired_count', 0);

        $otherTill = $this->device('fixed_pos', 20);
        $foreignGenesis = $this->getAs($otherTill, self::FEED_URL)
            ->assertOk()
            ->json('meta.latest_cursor');
        $this->getAs(
            $till,
            self::FEED_URL.'?'.http_build_query(['after' => $foreignGenesis]),
        )->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed');
    }

    public function test_feed_cursor_survives_wall_clock_regression_between_confirms(): void
    {
        $station = $this->device('payment_station');
        $till = $this->device('fixed_pos');
        $table = $this->table(10, 'B-02');
        $session = $this->qrSession($station, $table);
        $order = $this->order($station, $session, $table);
        $roundA = $this->round(
            $session,
            $order,
            QrOrderRound::STATUS_PENDING_CONFIRMATION,
            null,
            $this->confirmPayload(),
        );
        $roundB = $this->round(
            $session,
            $order,
            QrOrderRound::STATUS_PENDING_CONFIRMATION,
            null,
            $this->confirmPayload(),
        );

        $this->travelTo(Carbon::parse('2026-08-31 12:00:00'));
        $this->postAs($till, self::CONFIRM_URL, ['round_id' => $roundB->id])
            ->assertOk();
        $cursor = (string) $this->getAs($till, self::FEED_URL)
            ->assertOk()
            ->json('meta.next_cursor');

        $this->travelTo(Carbon::parse('2026-08-31 11:00:00'));
        $this->postAs($till, self::CONFIRM_URL, ['round_id' => $roundA->id])
            ->assertOk();

        $this->getAs(
            $till,
            self::FEED_URL.'?'.http_build_query(['after' => $cursor]),
        )->assertOk()
            ->assertJsonCount(1, 'data.rounds')
            ->assertJsonPath('data.rounds.0.id', $roundA->id);

        $roundA->refresh();
        $roundB->refresh();
        $this->assertTrue($roundA->resolved_at?->lt($roundB->resolved_at));
        $this->assertGreaterThan($roundB->accepted_seq, $roundA->accepted_seq);
    }

    public function test_feed_paginates_by_sequence_and_reports_horizon_jumps_without_leaking_legacy_rows(): void
    {
        $station = $this->device('payment_station');
        $till = $this->device('handheld');
        $closedSession = $this->qrSession($station, null, QrSession::STATUS_CLOSED);
        $closedOrder = $this->order($station, $closedSession, null, [
            'receipt_number' => null,
        ]);
        $firstRound = $this->round(
            $closedSession,
            $closedOrder,
            QrOrderRound::STATUS_ACCEPTED,
            Carbon::parse('2026-08-31 08:00:00'),
        );
        $secondRound = $this->round(
            $closedSession,
            $closedOrder,
            QrOrderRound::STATUS_ACCEPTED,
            Carbon::parse('2026-08-31 09:00:00'),
        );
        $thirdRound = $this->round(
            $closedSession,
            $closedOrder,
            QrOrderRound::STATUS_ACCEPTED,
            Carbon::parse('2026-08-31 10:00:00'),
        );
        $expiredAfterPrintable = $this->round(
            $closedSession,
            $closedOrder,
            QrOrderRound::STATUS_ACCEPTED,
            Carbon::parse('2026-08-31 05:59:59'),
        );
        $legacy = $this->round(
            $closedSession,
            $closedOrder,
            QrOrderRound::STATUS_ACCEPTED,
            Carbon::parse('2026-08-31 11:00:00'),
        );
        $legacy->update(['accepted_seq' => null]);
        $this->round(
            $closedSession,
            $closedOrder,
            QrOrderRound::STATUS_PENDING_CONFIRMATION,
            null,
            $this->confirmPayload(),
        );

        $pageOne = $this->getAs($till, self::FEED_URL.'?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.rounds')
            ->assertJsonPath('data.rounds.0.id', $firstRound->id)
            ->assertJsonPath('data.rounds.0.table_label', null)
            ->assertJsonPath('data.rounds.0.receipt_number', null)
            ->assertJsonPath('data.rounds.1.id', $secondRound->id)
            ->assertJsonPath('meta.skipped_expired_count', 0);
        $this->assertNotNull($pageOne->json('meta.latest_cursor'));

        $pageTwo = $this->getAs(
            $till,
            self::FEED_URL.'?'.http_build_query([
                'after' => $pageOne->json('meta.next_cursor'),
                'limit' => 2,
            ]),
        )->assertOk()
            ->assertJsonCount(1, 'data.rounds')
            ->assertJsonPath('data.rounds.0.id', $thirdRound->id)
            ->assertJsonPath('meta.skipped_expired_count', 1);
        $this->assertNotSame($pageTwo->json('meta.next_cursor'), $pageTwo->json('meta.latest_cursor'));

        // Literal r4 contract: an expired-only tail has no printable-row
        // next_cursor, while latest_cursor exposes the branch scan high-water.
        $expiredOnly = $this->getAs(
            $till,
            self::FEED_URL.'?'.http_build_query([
                'after' => $pageTwo->json('meta.next_cursor'),
                'limit' => 2,
            ]),
        )->assertOk()
            ->assertJsonCount(0, 'data.rounds')
            ->assertJsonPath('meta.next_cursor', null)
            ->assertJsonPath('meta.skipped_expired_count', 1);
        $this->assertNotNull($expiredOnly->json('meta.latest_cursor'));
        $this->assertSame(
            $expiredAfterPrintable->accepted_seq,
            QrOrderRound::query()->whereKey($expiredAfterPrintable->id)->value('accepted_seq'),
        );

        // Advancing to latest_cursor retires the expired gap and prevents a
        // repeated expiry notice. Legacy NULL accepted_seq is never served.
        $afterHighWater = $this->getAs(
            $till,
            self::FEED_URL.'?'.http_build_query([
                'after' => $expiredOnly->json('meta.latest_cursor'),
            ]),
        )->assertOk()
            ->assertJsonCount(0, 'data.rounds')
            ->assertJsonPath('meta.next_cursor', null)
            ->assertJsonPath('meta.skipped_expired_count', 0);
        $this->assertNotNull($afterHighWater->json('meta.latest_cursor'));

        $this->getAs($till, self::FEED_URL.'?after=not-a-cursor')
            ->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed');
        $this->getAs(
            $till,
            self::FEED_URL.'?'.http_build_query([
                'after' => 'tampered-'.$pageOne->json('meta.next_cursor'),
            ]),
        )->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed');
        $this->getAs($station, self::FEED_URL)
            ->assertConflict()
            ->assertJsonPath('errors.0.code', 'device_not_attended');

        $otherStation = $this->device('payment_station', 20);
        $otherTill = $this->device('fixed_pos', 20);
        $otherTable = $this->table(20, 'OTHER');
        $otherSession = $this->qrSession($otherStation, $otherTable);
        $otherOrder = $this->order($otherStation, $otherSession, $otherTable);
        $this->round($otherSession, $otherOrder, QrOrderRound::STATUS_ACCEPTED, now()->subMinute());
        $foreignCursor = $this->getAs($otherTill, self::FEED_URL)
            ->assertOk()
            ->json('meta.latest_cursor');

        $this->getAs(
            $till,
            self::FEED_URL.'?'.http_build_query(['after' => $foreignCursor]),
        )->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'validation_failed');
    }

    public function test_board_adds_recent_and_pending_rounds_without_changing_row_inclusion(): void
    {
        $station = $this->device('payment_station');
        $till = $this->device('fixed_pos');
        $table = $this->table(10, 'C-01');
        $this->table(10, 'EMPTY');
        $session = $this->qrSession($station, $table);
        $order = $this->order($station, $session, $table);
        $accepted = [];
        for ($index = 1; $index <= 22; $index++) {
            $accepted[] = $this->round(
                $session,
                $order,
                QrOrderRound::STATUS_ACCEPTED,
                now()->subMinutes(23 - $index),
            );
        }
        $pendingOne = $this->round(
            $session,
            $order,
            QrOrderRound::STATUS_PENDING_CONFIRMATION,
            null,
            $this->confirmPayload(),
        );
        $pendingTwo = $this->round(
            $session,
            $order,
            QrOrderRound::STATUS_PENDING_CONFIRMATION,
            null,
            $this->confirmPayload(),
        );

        $board = $this->getAs($till, self::BOARD_URL)
            ->assertOk()
            ->assertJsonCount(1, 'data.tables')
            ->assertJsonPath('data.tables.0.accepted_round_count', 22)
            ->assertJsonCount(22, 'data.tables.0.rounds')
            ->assertJsonCount(2, 'data.tables.0.pending_rounds');
        $roundIds = collect($board->json('data.tables.0.rounds'))->pluck('id')->all();
        $this->assertNotContains($accepted[0]->id, $roundIds);
        $this->assertNotContains($accepted[1]->id, $roundIds);
        $this->assertContains($accepted[2]->id, $roundIds);
        $this->assertContains($pendingOne->id, $roundIds);
        $this->assertContains($pendingTwo->id, $roundIds);
        $board->assertDontSee('PRIVATE-COST');
    }

    public function test_new_device_routes_use_the_required_named_limiters(): void
    {
        foreach ([
            'device.qr.confirm-round' => 'throttle:qr-table-device-write',
            'device.qr.reject-round' => 'throttle:qr-table-device-write',
            'device.qr.table-round' => 'throttle:qr-table-device-read',
            'device.qr.accepted-rounds' => 'throttle:qr-table-device-read',
        ] as $routeName => $middleware) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route);
            $this->assertContains($middleware, $route->gatherMiddleware());
        }
    }

    private function device(string $type, int $branchId = 10): Device
    {
        $this->deviceSequence++;

        return Device::factory()->paired('mdev_s5_surface_'.$this->deviceSequence)->create([
            'company_id' => 100,
            'branch_id' => $branchId,
            'device_type' => $type,
            'terminal_id' => $type === 'payment_station' ? 'TERM-'.$this->deviceSequence : null,
        ]);
    }

    private function table(int $branchId, string $label): PosTable
    {
        $floor = Floor::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => $branchId,
            'name' => 'Floor '.$label,
            'display_order' => 1,
            'status' => 'active',
        ]);

        return PosTable::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'floor_id' => $floor->id,
            'label' => $label,
            'seats' => 4,
            'shape' => 'square',
            'qr_token' => hash('sha256', 's5-'.$label),
            'status' => 'active',
            'display_order' => 1,
        ]);
    }

    private function qrSession(
        Device $station,
        ?PosTable $table,
        string $status = QrSession::STATUS_ACTIVE,
    ): QrSession {
        return QrSession::query()->create([
            'uuid' => (string) Str::uuid(),
            'company_id' => $station->company_id,
            'branch_id' => $station->branch_id,
            'device_id' => $station->id,
            'table_id' => $table?->id,
            'token' => hash('sha256', (string) Str::uuid()),
            'token_expires_at' => now()->addHours(6),
            'status' => $status,
            'client_secret_hash' => QrSession::hashClientSecret('s5-secret'),
            'bound_at' => now(),
            'last_seen_at' => now(),
            'expires_at' => $status === QrSession::STATUS_EXPIRED
                ? now()->subMinute()
                : now()->addHours(6),
            'closed_at' => $status === QrSession::STATUS_CLOSED ? now() : null,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    private function order(
        Device $station,
        QrSession $session,
        ?PosTable $table,
        array $attributes = [],
    ): Order {
        $this->orderSequence++;

        return Order::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => $station->company_id,
            'branch_id' => $station->branch_id,
            'device_id' => $station->id,
            'qr_session_id' => $session->id,
            'client_request_id' => (string) Str::uuid(),
            'table_id' => $table?->id,
            'order_type' => 'dine_in',
            'status' => Order::STATUS_OPEN,
            'source' => Order::SOURCE_QR_WEB,
            'subtotal' => '0.000',
            'discount_total' => '0.000',
            'comp_total' => '0.000',
            'tax_total' => '0.000',
            'grand_total' => '0.000',
            'opened_at' => now(),
            'receipt_number' => 'QR-'.str_pad((string) $this->orderSequence, 4, '0', STR_PAD_LEFT),
        ], $attributes));
    }

    /** @param array<string, mixed>|null $confirmPayload */
    private function round(
        QrSession $session,
        Order $order,
        string $status,
        ?Carbon $resolvedAt,
        ?array $confirmPayload = null,
    ): QrOrderRound {
        $roundNo = (int) QrOrderRound::query()
            ->where('qr_session_id', $session->id)
            ->max('round_no') + 1;

        return QrOrderRound::query()->create([
            'qr_session_id' => $session->id,
            'order_id' => $order->id,
            'round_no' => $roundNo,
            'status' => $status,
            'client_request_id' => (string) Str::uuid(),
            'priced_lines' => [[
                'product_id' => null,
                'name' => 'Frozen coffee',
                'name_ar' => 'قهوة مجمدة',
                'qty' => 1,
                'notes' => 'No sugar',
                'unit_price_baisas' => 4750,
                'line_discount_baisas' => 0,
                'line_total_baisas' => 4750,
                'addons' => [],
            ]],
            'confirm_payload' => $confirmPayload,
            'accepted_seq' => $status === QrOrderRound::STATUS_ACCEPTED
                ? ((int) QrOrderRound::query()->max('accepted_seq')) + 1
                : null,
            'subtotal_baisas' => 4750,
            'tax_baisas' => 0,
            'total_baisas' => 4750,
            'submitted_at' => now()->subMinutes(5),
            'resolved_at' => $resolvedAt,
        ]);
    }

    /** @return array<string, mixed> */
    private function confirmPayload(): array
    {
        return [
            'version' => 1,
            'items' => [[
                'attributes' => [
                    'product_id' => null,
                    'product_name_snapshot' => 'Frozen coffee',
                    'qty' => '1.000',
                    'unit_price_snapshot' => '4.750',
                    'line_discount' => '0.000',
                    'line_total' => '4.750',
                    'recipe_snapshot_json' => '{"marker":"PRIVATE-COST"}',
                    'component_snapshot_json' => null,
                    'status' => 'open',
                    'notes' => 'No sugar',
                ],
                'addons' => [],
            ]],
            'discounts' => [],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function postAs(Device $device, string $url, array $payload): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken((string) $device->device_token)->postJson($url, $payload);
    }

    private function getAs(Device $device, string $url): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken((string) $device->device_token)->getJson($url);
    }
}
