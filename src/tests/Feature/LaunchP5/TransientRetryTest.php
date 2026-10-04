<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use App\Actions\Device\Sync\SyncEventDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\Support\LaunchP5Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 fix order 2 (A3, device review M4, server side) — a paid sale
 * whose order.pay failed with the transient database error keeps the shift
 * close waiting (unsynced_sales, retryable), and when the device un-parks it
 * and re-pushes the SAME event (same client_event_id) it is processed; the
 * close retried under its fixed id then succeeds and counts the cash.
 */
class TransientRetryTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;

    public function test_a_pay_failed_with_the_transient_error_is_processed_when_re_pushed_and_the_close_then_goes_through(): void
    {
        $this->p5Device('mdev_tr');
        $this->p5Product();
        $this->p5Staff(7, 'cashier', '700007');
        $shift = (string) Str::uuid();
        $this->p5Push('mdev_tr', [$this->p5Event('shift.open', ['uuid' => $shift, 'staff_id' => 7, 'shared_shift' => true,
            'opening_cash_baisas' => 5000, 'opened_at' => now()->subHours(2)->toIso8601String()], at: now()->subHours(2)->toIso8601String())])
            ->assertOk();

        $uuid = (string) Str::uuid();
        $at = now()->subMinutes(30)->toIso8601String();
        $create = $this->p5Create($uuid, ['opened_at' => $at]);
        $create['client_timestamp'] = $at;
        $this->p5Push('mdev_tr', [$create])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $pay = $this->p5Pay($uuid, [['method' => 'cash', 'amount_baisas' => 10000]], ['auth_v' => 1, 'staff_id' => 7], $at);

        // A database fault while the pay is saved: the transient error.
        DB::statement("CREATE TRIGGER p5_db_fault BEFORE INSERT ON pos_payments BEGIN SELECT RAISE(ABORT, 'database is locked'); END");
        $this->p5Push('mdev_tr', [$pay])->assertOk()
            ->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.0.result.error', SyncEventDispatcher::TRANSIENT_ERROR);

        // The close waits for it (not a permanent failure).
        $close = $this->p5Event('shift.close', ['shift_uuid' => $shift, 'closing_cash_baisas' => 15000,
            'closed_at' => now()->toIso8601String(), 'closed_by_staff_id' => 7, 'order_uuids' => [$uuid], 'auth_v' => 1],
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'shift-close:'.$shift.':0')->toString());
        $this->p5Push('mdev_tr', [$close])->assertOk()
            ->assertJsonPath('data.results.0.status', 'failed')
            ->assertJsonPath('data.results.0.result.code', 'unsynced_sales')
            ->assertJsonPath('data.results.0.result.missing', [$uuid]);

        // The fault is gone; the device un-parks the row and re-pushes it as it was.
        DB::statement('DROP TRIGGER p5_db_fault');
        $this->p5Push('mdev_tr', [$pay])->assertOk()
            ->assertJsonPath('data.results.0.duplicate', true)
            ->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame('paid', DB::table('pos_orders')->where('uuid', $uuid)->value('status'));
        $this->assertSame(1, DB::table('pos_payments')->count());

        $this->p5Push('mdev_tr', [$close])->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.expected_cash_baisas', 15000)
            ->assertJsonPath('data.results.0.result.variance_baisas', 0);
    }
}
