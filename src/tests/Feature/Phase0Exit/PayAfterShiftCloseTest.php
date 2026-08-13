<?php

declare(strict_types=1);

namespace Tests\Feature\Phase0Exit;

use App\Models\Device;
use App\Models\Order;
use App\Models\Shift;
use App\Models\SyncEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 0 exit — W-A3 / EXIT-08.
 *
 * "Shift closes while sales remain queued: sales survive and later process."
 *
 * An order is created inside an open shift's window; the shift.close
 * processes; THEN the queued order.pay arrives. The architecture accepts a
 * replay into a closed shift — orders carry no shift_id (attribution is
 * temporal, DB-001 pending), so a closed drawer is never a reason to reject
 * or lose a real sale. Pinned here: the pay SETTLES (paid order, payment
 * row, effects exactly once) and is neither rejected nor lost.
 *
 * Deliberately NOT asserted (scope carve-out per the Phase 0 coverage
 * matrix): which Z/EOD report the late sale is attributed to.
 */
class PayAfterShiftCloseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPosStaff([7]);
    }

    private function device(): Device
    {
        return Device::factory()->paired('mdev_p0_shift')->create([
            'company_id' => 100,
            'branch_id' => 10,
        ]);
    }

    private function seedProduct(): void
    {
        DB::table('pos_products')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Tea', 'base_price' => 1.000, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(array $events): TestResponse
    {
        return $this->withToken('mdev_p0_shift')->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }

    public function test_a_pay_arriving_after_its_shift_closed_still_settles_exactly_once(): void
    {
        $this->seedProduct();
        $this->device();
        $shiftUuid = (string) Str::uuid();
        $orderUuid = (string) Str::uuid();

        // Shift opens two hours ago with a 10.000 float.
        $this->push([[
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'shift.open',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'uuid' => $shiftUuid,
                'staff_id' => 7,
                'opening_cash_baisas' => 10000,
                'opened_at' => now()->subHours(2)->toIso8601String(),
                'shared_shift' => true,
            ],
        ]])->assertOk()->assertJsonPath('data.results.0.status', SyncEvent::STATUS_PROCESSED);

        // The order is created INSIDE the open shift's window.
        $this->push([[
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.create',
            'client_timestamp' => now()->subHour()->toIso8601String(),
            'payload' => ['order' => [
                'uuid' => $orderUuid,
                'order_type' => 'quick',
                'source' => 'main_pos',
                'staff_id' => 7,
                'opened_at' => now()->subHour()->toIso8601String(),
                'subtotal_baisas' => 1000,
                'discount_total_baisas' => 0,
                'tax_total_baisas' => 0,
                'grand_total_baisas' => 1000,
                'lines' => [['product_id' => 1, 'qty' => 1, 'unit_price_baisas' => 1000, 'line_discount_baisas' => 0, 'line_total_baisas' => 1000]],
            ]],
        ]])->assertOk()->assertJsonPath('data.results.0.status', SyncEvent::STATUS_PROCESSED);

        // The shift CLOSES while the pay still sits in the device outbox.
        $this->push([[
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'shift.close',
            'client_timestamp' => now()->subMinutes(5)->toIso8601String(),
            'payload' => [
                'shift_uuid' => $shiftUuid,
                'closing_cash_baisas' => 10000,
                'closed_at' => now()->subMinutes(5)->toIso8601String(),
            ],
        ]])->assertOk()->assertJsonPath('data.results.0.status', SyncEvent::STATUS_PROCESSED);

        $shift = Shift::firstWhere('uuid', $shiftUuid);
        $this->assertSame(Shift::STATUS_CLOSED, $shift->status);

        // NOW the queued pay lands. Its paid_at falls inside the closed
        // shift's window — the replay must be ACCEPTED, not rejected or lost.
        $pay = [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => now()->subMinutes(30)->toIso8601String(),
            'payload' => [
                'order_uuid' => $orderUuid,
                'paid_at' => now()->subMinutes(30)->toIso8601String(),
                'payments' => [['method' => 'cash', 'amount_baisas' => 1000, 'change_given_baisas' => 0]],
            ],
        ];
        $res = $this->push([$pay])->assertOk();

        $this->assertFalse($res->json('data.results.0.duplicate'));
        $this->assertSame(SyncEvent::STATUS_PROCESSED, $res->json('data.results.0.status'));
        $this->assertSame('paid', $res->json('data.results.0.result.status'));

        $order = Order::firstWhere('uuid', $orderUuid);
        $this->assertSame(Order::STATUS_PAID, $order->status);
        $this->assertNotNull($order->closed_at);
        $this->assertDatabaseCount('pos_payments', 1);
        $this->assertDatabaseHas('pos_payments', ['order_id' => $order->id, 'method' => 'cash', 'status' => 'success']);
        $this->assertEqualsWithDelta(1.000, (float) DB::table('pos_payments')->value('amount'), 1e-9);

        // The sale is durably visible in the ledger, terminal and processed.
        $this->assertDatabaseHas('pos_sync_events', [
            'client_event_id' => $pay['client_event_id'],
            'ack_status' => SyncEvent::STATUS_PROCESSED,
        ]);

        // The shift's close remains terminal — the late pay reopened nothing.
        $this->assertSame(Shift::STATUS_CLOSED, $shift->fresh()->status);

        // Effects exactly once: a replay of the same pay is a stored-ACK
        // duplicate, never a second settlement.
        $replay = $this->push([$pay])->assertOk();
        $this->assertTrue($replay->json('data.results.0.duplicate'));
        $this->assertSame(SyncEvent::STATUS_PROCESSED, $replay->json('data.results.0.status'));
        $this->assertDatabaseCount('pos_payments', 1);
        $this->assertSame(Order::STATUS_PAID, $order->fresh()->status);
    }
}
