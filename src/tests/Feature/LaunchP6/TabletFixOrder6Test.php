<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP6;

use App\Models\Device;
use App\Models\Order;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\TabletOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\Support\LaunchP6Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P6 Part A fix order 6 (LAUNCH-P6_A_FIX_ORDER_6.md) — F-20: the table
 * board and detail tell a `tablet-orders` build that a bill holds customer
 * tablet rounds (`tablet_rounds`, counted as customer rounds), so the bill
 * goes to the server checkout; an old build keeps today's shape.
 */
final class TabletFixOrder6Test extends TestCase
{
    use LaunchP6Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p6Setup();
    }

    /**
     * The till seats the table and enters a cake (a staff round); the tablet
     * adds a round staff send (accepted) and another still waiting (pending).
     *
     * @return array{0: Table, 1: Order, 2: array<string, mixed>}
     */
    private function tillBillWithTabletRounds(): array
    {
        $table = $this->seatingTable('Table 9');
        $seat = $this->seatingRow($table, ['opened_by_device_id' => $this->till->id]);
        $id = (string) Str::uuid();
        $this->p6As($this->till, 'POST', '/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => $id, 'event_type' => 'table.session.round', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['seating_key' => $seat->client_request_id, 'table_id' => $table->id, 'queued_offline' => false,
                'client_request_id' => $id, 'submitted_at' => now()->toIso8601String(), 'staff_id' => 7,
                'lines' => [['product_id' => $this->cake, 'qty' => 1, 'addon_ids' => [], 'notes' => null]]],
        ]]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $sent = $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => $table->uuid])->assertCreated()->json('data');
        $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tablet-orders/{$sent['tablet_order_uuid']}/send-to-kitchen")->assertOk();
        $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => $table->uuid])->assertCreated();
        $bill = Order::query()->sole();
        $this->assertSame([(int) $this->till->id, 'main_pos'], [(int) $bill->device_id, $bill->source]);

        return [$table, $bill, $sent];
    }

    /** @return array<string, mixed>|null */
    private function boardBill(Device $device, int $tableId, bool $capable): ?array
    {
        $row = collect($this->p6As($device, 'GET', '/api/v1/device/tables/board', [],
            $capable ? ['X-Pos-Capabilities' => 'tablet-orders'] : [])->assertOk()->json('data.tables'))->firstWhere('table_id', $tableId);

        return $row === null ? null : $row['bill'];
    }

    /** @return array<string, mixed> */
    private function detailBill(int $tableId, bool $capable): array
    {
        return $this->p6As($this->till, 'GET', "/api/v1/device/tables/{$tableId}/detail", [],
            $capable ? ['X-Pos-Capabilities' => 'tablet-orders'] : [])->assertOk()->json('data.bill');
    }

    /** @return list<int|null> */
    private function counts(array $bill): array
    {
        return [$bill['customer_rounds'] ?? null, $bill['staff_rounds'] ?? null, $bill['tablet_rounds'] ?? null];
    }

    public function test_f20_a_till_opened_bill_with_tablet_rounds_shows_them_as_customer_rounds_on_the_board_and_the_detail(): void
    {
        [$table, $bill] = $this->tillBillWithTabletRounds();

        // One staff round; one sent and one pending tablet round.
        $board = $this->boardBill($this->till, (int) $table->id, true);
        $this->assertSame($bill->uuid, $board['order_uuid']);
        $this->assertSame([2, 1, 2], $this->counts($board));
        $this->assertSame([2, 1, 2], $this->counts($this->detailBill((int) $table->id, true)));
        $this->assertGreaterThan(0, $board['customer_rounds']);
    }

    public function test_f20_a_sent_tablet_round_alone_already_routes_the_bill_to_the_server_checkout(): void
    {
        [$table] = $this->tillBillWithTabletRounds();
        // The waiting round is rejected: one sent tablet round remains.
        $pending = TabletOrder::query()->whereNull('sent_to_kitchen_at')->sole();
        $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tables/{$this->seat()}/rounds/{$pending->round_id}/reject")->assertOk();

        $this->assertSame([1, 1, 1], $this->counts($this->boardBill($this->till, (int) $table->id, true)));
        $this->assertSame([1, 1, 1], $this->counts($this->detailBill((int) $table->id, true)));
    }

    private function seat(): string
    {
        return (string) TableSession::query()->sole()->uuid;
    }

    public function test_f20_an_old_build_sees_todays_shape(): void
    {
        [$table] = $this->tillBillWithTabletRounds();

        // Today: rounds without a QR session are staff rounds; no tablet field.
        $board = $this->boardBill($this->till, (int) $table->id, false);
        $this->assertSame([0, 3, null], $this->counts($board));
        $this->assertArrayNotHasKey('tablet_rounds', $board);
        $detail = $this->detailBill((int) $table->id, false);
        $this->assertArrayNotHasKey('tablet_rounds', $detail);
        $this->assertArrayNotHasKey('customer_rounds', $detail);
    }

    public function test_f20_only_the_devices_branch_is_counted(): void
    {
        [$table] = $this->tillBillWithTabletRounds();
        // Another branch's or merchant's device never sees this table.
        foreach ([$this->p6Device('mdev_p6_b11', 'fixed_pos', 100, 11), $this->p6Device('mdev_p6_y_till', 'fixed_pos', 200, 20)] as $device) {
            $this->assertNull($this->boardBill($device, (int) $table->id, true));
        }
        // A tablet row of another branch on these rounds is not this branch's tablet round.
        TabletOrder::query()->update(['branch_id' => 11]);

        $this->assertSame([0, 3, 0], $this->counts($this->boardBill($this->till, (int) $table->id, true)));
        $this->assertSame([0, 3, 0], $this->counts($this->detailBill((int) $table->id, true)));
    }
}
