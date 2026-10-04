<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use App\Actions\Device\Sync\Handlers\CloseShiftHandler;
use App\Models\Device;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\Support\LaunchP5Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 fix order 1 (L2) — late cash: no lost update, no close/pay race.
 * recordLateCash locks every shift whose window holds the payment (open ones
 * included) and writes late_sales_baisas and the note line in SQL, so two
 * devices' late cash for one closed shift both count; the close locks its
 * shift row first. SQLite has no row locks: the lost update is replayed by
 * writing another device's late cash right after the shift was read.
 */
class LateCashLockTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;

    private string $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p5Device('mdev_lc');
        $this->p5Product();
        $this->p5Staff(7, 'cashier', '700007');
        $this->shift = (string) Str::uuid();
        $this->p5Push('mdev_lc', [$this->p5Event('shift.open', ['uuid' => $this->shift, 'staff_id' => 7, 'shared_shift' => true,
            'opening_cash_baisas' => 5000, 'opened_at' => now()->subHours(3)->toIso8601String()], at: now()->subHours(3)->toIso8601String())])
            ->assertOk();
        $this->p5Push('mdev_lc', [$this->p5Event('shift.close', ['shift_uuid' => $this->shift, 'closing_cash_baisas' => 5000,
            'closed_at' => now()->subHour()->toIso8601String(), 'closed_by_staff_id' => 7, 'order_uuids' => [], 'auth_v' => 1],
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'shift-close:'.$this->shift.':0')->toString())])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');
    }

    public function test_two_devices_late_cash_for_one_closed_shift_both_count(): void
    {
        // Another device's late cash commits right after this pay read the shift.
        $injected = false;
        DB::listen(function (QueryExecuted $query) use (&$injected): void {
            if ($injected || ! str_starts_with($query->sql, 'select * from "pos_shifts"') || ! str_contains($query->sql, '"opened_at" <= ?')) {
                return;
            }
            $injected = true;
            DB::table('pos_shifts')->where('uuid', $this->shift)->update([
                'late_sales_baisas' => DB::raw('late_sales_baisas + 1000'), 'needs_review' => true, 'note' => 'Late cash sale other-device (1.000)',
            ]);
        });

        $uuid = (string) Str::uuid();
        $at = now()->subMinutes(90)->toIso8601String();
        $create = $this->p5Create($uuid, ['opened_at' => $at]);
        $create['client_timestamp'] = $at;
        $this->p5Push('mdev_lc', [$create, $this->p5Pay($uuid, [['method' => 'cash', 'amount_baisas' => 10000]], [], $at)])
            ->assertOk()->assertJsonPath('data.results.1.status', 'processed')
            ->assertJsonPath('data.results.1.result.late_for_shift', $this->shift);

        $this->assertTrue($injected);
        $row = DB::table('pos_shifts')->where('uuid', $this->shift)->first();
        $this->assertSame(11000, (int) $row->late_sales_baisas);
        $this->assertSame('Late cash sale other-device (1.000) | Late cash sale '.$uuid.' (10.000)', $row->note);
    }

    public function test_the_close_reads_its_shift_row_for_update(): void
    {
        $device = Device::query()->firstOrFail();
        $this->assertTrue(CloseShiftHandler::shiftForClose($device, $this->shift)->getQuery()->lock);
        $this->assertSame($this->shift, CloseShiftHandler::shiftForClose($device, $this->shift)->value('uuid'));
    }
}
