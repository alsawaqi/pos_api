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

    private function openEvent(string $shiftUuid, int $openingBaisas, ?string $openedAt = null): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'shift.open',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'uuid' => $shiftUuid,
                'staff_id' => 7,
                'opening_cash_baisas' => $openingBaisas,
                'opened_at' => $openedAt ?? now()->subHours(2)->toIso8601String(),
            ],
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

    private function createEvent(string $orderUuid, int $amountBaisas): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.create',
            'client_timestamp' => now()->subHour()->toIso8601String(),
            'payload' => ['order' => [
                'uuid' => $orderUuid,
                'order_type' => 'quick',
                'source' => 'main_pos',
                'staff_id' => 7,
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

    private function ringCashSale(string $token, int $amountBaisas): void
    {
        $uuid = (string) Str::uuid();
        $this->push($token, [$this->createEvent($uuid, $amountBaisas)])->assertOk();
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

    public function test_close_excludes_cash_taken_on_another_device(): void
    {
        $this->seedProduct();
        $this->device('mdev_a', 100, 10);
        $shiftUuid = (string) Str::uuid();

        $this->push('mdev_a', [$this->openEvent($shiftUuid, 10000)])->assertOk();
        $this->ringCashSale('mdev_a', 3000); // belongs to this device's shift

        // A second device in the same branch rings its own cash sale.
        Device::factory()->paired('mdev_b')->create(['company_id' => 100, 'branch_id' => 10]);
        $this->app['auth']->forgetGuards();
        $this->ringCashSale('mdev_b', 5000); // must NOT count toward mdev_a's drawer

        $this->app['auth']->forgetGuards();
        $res = $this->push('mdev_a', [$this->closeEvent($shiftUuid, 13000)])->assertOk();

        // Only mdev_a's 3.000 counts: expected 13.000, variance 0.
        $this->assertSame(13000, $res->json('data.results.0.result.expected_cash_baisas'));
        $this->assertSame(0, $res->json('data.results.0.result.variance_baisas'));
    }
}
