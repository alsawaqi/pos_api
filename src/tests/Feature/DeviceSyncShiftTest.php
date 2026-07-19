<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 8.5 — shift open/close processed off the sync pipeline.
 *
 * shift.open creates a drawer session; shift.close reconciles it:
 * expected_cash = opening + Σ(cash taken on this device during the window),
 * variance = closing − expected. Seeded for company 100 / branch 10 with a
 * 1.000 OMR product so cash sales can be rung.
 */
class DeviceSyncShiftTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Phase 4 — shift.open + the order.create used to ring cash reference
        // these cashiers; all belong to the device tenant (company 100).
        $this->seedPosStaff([7, 8, 99]);
    }

    private function device(string $token = 'mdev_a', int $company = 100, int $branch = 10): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => $company, 'branch_id' => $branch]);
    }

    private function seedProduct(): void
    {
        DB::table('pos_products')->insert([
            ['id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Tea', 'base_price' => 1.000, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function openEvent(string $shiftUuid, int $openingBaisas, ?string $openedAt = null, int $staffId = 7, bool $shared = true): array
    {
        $payload = [
            'uuid' => $shiftUuid,
            'staff_id' => $staffId,
            'opening_cash_baisas' => $openingBaisas,
            'opened_at' => $openedAt ?? now()->subHours(2)->toIso8601String(),
        ];
        // HH-2 — new builds opt into the staff-shared model; legacy field
        // builds never send this key ($shared = false models them).
        if ($shared) {
            $payload['shared_shift'] = true;
        }

        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'shift.open',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => $payload,
        ];
    }

    private function closeEvent(string $shiftUuid, int $closingBaisas): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'shift.close',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'shift_uuid' => $shiftUuid,
                'closing_cash_baisas' => $closingBaisas,
                'closed_at' => now()->toIso8601String(),
            ],
        ];
    }

    private function createEvent(string $orderUuid, int $amountBaisas, int $staffId = 7): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.create',
            'client_timestamp' => now()->subHour()->toIso8601String(),
            'payload' => ['order' => [
                'uuid' => $orderUuid,
                'order_type' => 'quick',
                'source' => 'main_pos',
                'staff_id' => $staffId,
                'opened_at' => now()->subHour()->toIso8601String(),
                'subtotal_baisas' => $amountBaisas,
                'discount_total_baisas' => 0,
                'tax_total_baisas' => 0,
                'grand_total_baisas' => $amountBaisas,
                'lines' => [['product_id' => 1, 'qty' => 1, 'unit_price_baisas' => $amountBaisas, 'line_discount_baisas' => 0, 'line_total_baisas' => $amountBaisas]],
            ]],
        ];
    }

    private function payCashEvent(string $orderUuid, int $amountBaisas): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => now()->subHour()->toIso8601String(),
            'payload' => [
                'order_uuid' => $orderUuid,
                'paid_at' => now()->subHour()->toIso8601String(),
                'payments' => [['method' => 'cash', 'amount_baisas' => $amountBaisas]],
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(string $token, array $events): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }

    private function ringCashSale(string $token, int $amountBaisas, int $staffId = 7): void
    {
        $uuid = (string) Str::uuid();
        $this->push($token, [$this->createEvent($uuid, $amountBaisas, $staffId)])->assertOk();
        $this->push($token, [$this->payCashEvent($uuid, $amountBaisas)])->assertOk();
    }

    public function test_open_creates_an_open_shift(): void
    {
        $this->device();
        $shiftUuid = (string) Str::uuid();

        $res = $this->push('mdev_a', [$this->openEvent($shiftUuid, 10000)])->assertOk();
        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertSame('open', $res->json('data.results.0.result.status'));

        $shift = Shift::firstWhere('uuid', $shiftUuid);
        $this->assertNotNull($shift);
        $this->assertSame('open', $shift->status);
        $this->assertSame(100, (int) $shift->company_id);
        $this->assertSame('10.000', $shift->opening_cash);
    }

    public function test_close_computes_expected_cash_and_variance(): void
    {
        $this->seedProduct();
        $this->device();
        $shiftUuid = (string) Str::uuid();

        $this->push('mdev_a', [$this->openEvent($shiftUuid, 10000)])->assertOk();
        $this->ringCashSale('mdev_a', 3000); // +3.000 cash during the shift

        // Drawer counted at 12.500 → expected 13.000 → short by 0.500.
        $res = $this->push('mdev_a', [$this->closeEvent($shiftUuid, 12500)])->assertOk();

        $this->assertSame('closed', $res->json('data.results.0.result.status'));
        $this->assertSame(13000, $res->json('data.results.0.result.expected_cash_baisas'));
        $this->assertSame(-500, $res->json('data.results.0.result.variance_baisas'));

        $shift = Shift::firstWhere('uuid', $shiftUuid);
        $this->assertSame('closed', $shift->status);
        $this->assertSame('13.000', $shift->expected_cash);
        $this->assertSame('-0.500', $shift->variance);
    }

    public function test_a_device_cannot_open_two_shifts(): void
    {
        $this->device();

        $this->push('mdev_a', [$this->openEvent((string) Str::uuid(), 5000)])->assertOk();
        $res = $this->push('mdev_a', [$this->openEvent((string) Str::uuid(), 5000)])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('already has an open shift', $res->json('data.results.0.result.error'));
        $this->assertSame(1, Shift::count());
    }

    public function test_open_rejects_a_staff_member_from_another_company(): void
    {
        $this->device(); // company 100
        // Staff 55 exists, but in another company — a device must not open a
        // (shared) shift under a foreign staff id.
        $this->seedPosStaff([55], companyId: 200, branchId: 20);

        $res = $this->push('mdev_a', [$this->openEvent((string) Str::uuid(), 5000, null, 55)])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('staff member outside the device tenant', $res->json('data.results.0.result.error'));
        $this->assertSame(0, Shift::count());
    }

    public function test_closing_an_unknown_shift_fails(): void
    {
        $this->device();

        $res = $this->push('mdev_a', [$this->closeEvent((string) Str::uuid(), 5000)])->assertOk();
        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('shift not found', $res->json('data.results.0.result.error'));
    }

    public function test_replaying_open_is_idempotent(): void
    {
        $this->device();
        $open = $this->openEvent((string) Str::uuid(), 5000);

        $this->push('mdev_a', [$open])->assertOk();
        $res = $this->push('mdev_a', [$open])->assertOk();

        $res->assertJsonPath('data.summary.duplicates', 1);
        $this->assertSame(1, Shift::count());
    }

    /**
     * Phase C6 — the close result carries the printed Z-report numbers,
     * attributed exactly like expected_cash (this device, temporal window).
     */
    public function test_close_returns_the_shift_sales_summary(): void
    {
        $this->seedProduct();
        $this->device();
        $shiftUuid = (string) Str::uuid();

        $this->push('mdev_a', [$this->openEvent($shiftUuid, 10000)])->assertOk();
        $this->ringCashSale('mdev_a', 3000);
        $this->ringCashSale('mdev_a', 2000);

        // A voided order in the window shows in the voids line, not in sales.
        $voidUuid = (string) Str::uuid();
        $this->push('mdev_a', [$this->createEvent($voidUuid, 1500)])->assertOk();
        $this->push('mdev_a', [[
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.void',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $voidUuid, 'voided_at' => now()->toIso8601String(), 'reason' => 'test'],
        ]])->assertOk();

        $res = $this->push('mdev_a', [$this->closeEvent($shiftUuid, 15000)])->assertOk();
        $summary = $res->json('data.results.0.result.summary');

        $this->assertSame(2, $summary['order_count']);
        $this->assertSame(5000, $summary['gross_sales_baisas']);
        $this->assertSame(0, $summary['discount_total_baisas']);
        $this->assertSame(0, $summary['comp_total_baisas']);
        $this->assertSame(5000, $summary['grand_total_baisas']);
        $this->assertSame(1, $summary['void_count']);
        $this->assertSame(1500, $summary['void_total_baisas']);
        $this->assertSame(0, $summary['round_up_baisas']);
        $this->assertSame(0, $summary['branch_expenses_baisas']);
        $tenders = collect($summary['tenders']);
        $this->assertSame(5000, $tenders->firstWhere('method', 'cash')['amount_baisas']);
        $this->assertSame(2, $tenders->firstWhere('method', 'cash')['count']);
    }

    /**
     * Phase D4 — a gifted sale never enters the drawer math (expected cash
     * sums METHOD_CASH only) but DOES surface as its own Z-report tender row.
     */
    public function test_close_excludes_gift_tenders_from_expected_cash(): void
    {
        $this->seedProduct();
        $this->device();
        $shiftUuid = (string) Str::uuid();

        $this->push('mdev_a', [$this->openEvent($shiftUuid, 10000)])->assertOk();
        $this->ringCashSale('mdev_a', 3000);

        // A fully gifted order during the shift: zero collected.
        $giftUuid = (string) Str::uuid();
        $this->push('mdev_a', [$this->createEvent($giftUuid, 2000)])->assertOk();
        $this->push('mdev_a', [[
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.pay',
            'client_timestamp' => now()->subHour()->toIso8601String(),
            'payload' => [
                'order_uuid' => $giftUuid,
                'paid_at' => now()->subHour()->toIso8601String(),
                'payments' => [['method' => 'gift', 'amount_baisas' => 2000]],
            ],
        ]])->assertOk();

        $res = $this->push('mdev_a', [$this->closeEvent($shiftUuid, 13000)])->assertOk();

        // Drawer: opening 10.000 + 3.000 cash only — the gift adds nothing.
        $this->assertSame(13000, $res->json('data.results.0.result.expected_cash_baisas'));
        $this->assertSame(0, $res->json('data.results.0.result.variance_baisas'));

        // Z-report: the gifted order counts as a sale and its tender shows.
        $summary = $res->json('data.results.0.result.summary');
        $this->assertSame(2, $summary['order_count']);
        $gift = collect($summary['tenders'])->firstWhere('method', 'gift');
        $this->assertSame(2000, $gift['amount_baisas']);
        $this->assertSame(1, $gift['count']);
    }

    public function test_close_excludes_another_staffs_cash_on_another_device(): void
    {
        $this->seedProduct();
        $this->device('mdev_a', 100, 10);
        $shiftUuid = (string) Str::uuid();

        $this->push('mdev_a', [$this->openEvent($shiftUuid, 10000)])->assertOk();
        $this->ringCashSale('mdev_a', 3000); // belongs to this device's shift

        // A DIFFERENT staff member rings cash on a second device in the same
        // branch — neither this drawer nor this staff, so it stays out.
        Device::factory()->paired('mdev_b')->create(['company_id' => 100, 'branch_id' => 10]);
        $this->app['auth']->forgetGuards();
        $this->ringCashSale('mdev_b', 5000, 99); // must NOT count toward mdev_a's drawer

        $this->app['auth']->forgetGuards();
        $res = $this->push('mdev_a', [$this->closeEvent($shiftUuid, 13000)])->assertOk();

        // Only mdev_a's 3.000 counts: expected 13.000, variance 0.
        $this->assertSame(13000, $res->json('data.results.0.result.expected_cash_baisas'));
        $this->assertSame(0, $res->json('data.results.0.result.variance_baisas'));
    }

    /**
     * HH-2 — one shift a day per staff, shared across their devices: cash the
     * SAME staff member takes on a second terminal (the handheld) counts
     * toward the shift they opened on the first, in both the drawer math and
     * the Z-report summary.
     */
    public function test_close_includes_the_shifts_staffs_cash_from_another_device(): void
    {
        $this->seedProduct();
        $this->device('mdev_a', 100, 10);
        $shiftUuid = (string) Str::uuid();

        $this->push('mdev_a', [$this->openEvent($shiftUuid, 10000)])->assertOk();
        $this->ringCashSale('mdev_a', 3000); // on the opening device

        Device::factory()->paired('mdev_b')->create(['company_id' => 100, 'branch_id' => 10]);
        $this->app['auth']->forgetGuards();
        $this->ringCashSale('mdev_b', 5000, 7); // SAME staff, other device

        $this->app['auth']->forgetGuards();
        $res = $this->push('mdev_a', [$this->closeEvent($shiftUuid, 18000)])->assertOk();

        // Both sales count: 10.000 + 3.000 + 5.000 = 18.000, variance 0.
        $this->assertSame(18000, $res->json('data.results.0.result.expected_cash_baisas'));
        $this->assertSame(0, $res->json('data.results.0.result.variance_baisas'));

        $summary = $res->json('data.results.0.result.summary');
        $this->assertSame(2, $summary['order_count']);
        $this->assertSame(8000, $summary['grand_total_baisas']);
        $tenders = collect($summary['tenders']);
        $this->assertSame(8000, $tenders->firstWhere('method', 'cash')['amount_baisas']);
    }

    /**
     * HH-2 — the staff-keyed one-open-shift guard: the same person cannot
     * open a second float on another device; the client adopts instead (via
     * GET /device/shift/current?staff_id). A different staff member still
     * opens their own shift there freely.
     */
    public function test_a_staff_member_cannot_open_two_shifts_across_devices(): void
    {
        $this->device('mdev_a', 100, 10);
        Device::factory()->paired('mdev_b')->create(['company_id' => 100, 'branch_id' => 10]);

        $this->push('mdev_a', [$this->openEvent((string) Str::uuid(), 5000)])->assertOk();

        $this->app['auth']->forgetGuards();
        $res = $this->push('mdev_b', [$this->openEvent((string) Str::uuid(), 4000)])->assertOk();
        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('staff already has an open shift', $res->json('data.results.0.result.error'));
        $this->assertSame(1, Shift::count());

        // A different staff member is free to open on the second device.
        $res = $this->push('mdev_b', [$this->openEvent((string) Str::uuid(), 4000, null, 8)])->assertOk();
        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertSame(2, Shift::count());
    }

    /**
     * FIELD COMPAT — a deployed pos_machine build (no shared_shift flag)
     * keeps the legacy per-device model end-to-end: the same staff member
     * opens a second per-device shift on another terminal without being
     * rejected, and each close attributes ONLY that device's cash.
     */
    public function test_legacy_opens_without_the_flag_keep_per_device_semantics(): void
    {
        $this->seedProduct();
        $this->device('mdev_a', 100, 10);
        Device::factory()->paired('mdev_b')->create(['company_id' => 100, 'branch_id' => 10]);

        $shiftA = (string) Str::uuid();
        $this->push('mdev_a', [$this->openEvent($shiftA, 10000, null, 7, false)])->assertOk();
        $this->ringCashSale('mdev_a', 3000);

        // Same staff, second legacy device: opens ITS OWN shift (no reject).
        $this->app['auth']->forgetGuards();
        $shiftB = (string) Str::uuid();
        $res = $this->push('mdev_b', [$this->openEvent($shiftB, 2000, null, 7, false)])->assertOk();
        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->ringCashSale('mdev_b', 5000);

        // Each close sees only its own device's drawer.
        $this->app['auth']->forgetGuards();
        $res = $this->push('mdev_a', [$this->closeEvent($shiftA, 13000)])->assertOk();
        $this->assertSame(13000, $res->json('data.results.0.result.expected_cash_baisas'));

        $this->app['auth']->forgetGuards();
        $res = $this->push('mdev_b', [$this->closeEvent($shiftB, 7000)])->assertOk();
        $this->assertSame(7000, $res->json('data.results.0.result.expected_cash_baisas'));
    }

    /**
     * Two coexisting SHARED shifts stay disjoint even when one cashier rings
     * a sale on the other's terminal: the order follows its STAFF's shift,
     * never the device's, so no money is counted twice.
     */
    public function test_shared_shifts_stay_disjoint_when_staff_cross_devices(): void
    {
        $this->seedProduct();
        $this->device('mdev_a', 100, 10);
        Device::factory()->paired('mdev_b')->create(['company_id' => 100, 'branch_id' => 10]);

        $shiftA = (string) Str::uuid(); // staff 7, opened on mdev_a
        $this->push('mdev_a', [$this->openEvent($shiftA, 10000, null, 7)])->assertOk();
        $this->app['auth']->forgetGuards();
        $shiftB = (string) Str::uuid(); // staff 8, opened on mdev_b
        $this->push('mdev_b', [$this->openEvent($shiftB, 5000, null, 8)])->assertOk();

        // Staff 8 rings a sale ON STAFF 7'S terminal (mdev_a).
        $this->app['auth']->forgetGuards();
        $this->ringCashSale('mdev_a', 4000, 8);

        // It belongs to staff 8's shift only.
        $this->app['auth']->forgetGuards();
        $res = $this->push('mdev_a', [$this->closeEvent($shiftA, 10000)])->assertOk();
        $this->assertSame(10000, $res->json('data.results.0.result.expected_cash_baisas'));

        $this->app['auth']->forgetGuards();
        $res = $this->push('mdev_b', [$this->closeEvent($shiftB, 9000)])->assertOk();
        $this->assertSame(9000, $res->json('data.results.0.result.expected_cash_baisas'));
    }
}
