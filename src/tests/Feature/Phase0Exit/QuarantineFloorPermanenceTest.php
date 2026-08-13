<?php

declare(strict_types=1);

namespace Tests\Feature\Phase0Exit;

use App\Models\Device;
use App\Models\Expense;
use App\Models\SyncEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 0 exit — W-A4 / EXIT-10.
 *
 * "Ambiguous historical row at/below floor: remains quarantined and is
 * never automatically replayed."
 *
 * Rows at and below the configured quarantine floor — including rows that
 * look PERFECTLY eligible (registered handler, old server receipt, safe
 * device attribution, valid payload) — must survive repeated sweeps and a
 * --limit-constrained sweep completely untouched, while an above-floor row
 * processes normally. Quarantine permanence is what makes the one-time
 * operator floor decision trustworthy.
 *
 * Operator-visibility note (flag F-2, investigated read-only for this work
 * item): no HTTP surface in pos_api, pos_admin or pos_merchant queries
 * pos_sync_events — quarantined rows are reachable only by direct DB
 * inspection, and the sweep's summary log excludes them by construction
 * (its candidate query starts at id > floor). That absence is reported to
 * the orchestrator as an owner-decision item, not asserted here as a UI
 * contract.
 */
class QuarantineFloorPermanenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPosStaff([7]);

        config(['sync.stranded_sweep_enabled' => false]);
    }

    private function device(): Device
    {
        return Device::factory()->paired('mdev_p0_floor')->create([
            'company_id' => 100,
            'branch_id' => 10,
        ]);
    }

    /**
     * A perfectly sweep-eligible stranded expense event: registered handler,
     * valid payload, received 11 minutes ago — only the floor can hold it.
     */
    private function eligibleEvent(Device $device): SyncEvent
    {
        return SyncEvent::create([
            'client_event_id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'event_type' => 'expense.log',
            'payload_json' => [
                'category' => 'utilities',
                'amount_baisas' => 5000,
                'note' => 'water bill',
                'staff_id' => 7,
            ],
            'client_timestamp' => now()->subMinutes(12),
            'server_received_at' => now()->subMinutes(11),
            'ack_status' => SyncEvent::STATUS_RECEIVED,
        ]);
    }

    private function assertQuarantinedUntouched(SyncEvent $event): void
    {
        $row = $event->fresh();
        $this->assertSame(SyncEvent::STATUS_RECEIVED, $row->ack_status);
        $this->assertNull($row->processed_at);
        $this->assertNull($row->result_json);
    }

    public function test_rows_at_and_below_the_floor_survive_repeated_and_limited_sweeps_untouched(): void
    {
        $device = $this->device();

        // Three IDENTICALLY-eligible rows; only their position vs the floor
        // differs. The below-floor row is the "looks perfectly eligible"
        // quarantine case the exit criterion targets.
        $belowFloor = $this->eligibleEvent($device);
        $atFloor = $this->eligibleEvent($device);
        $aboveFloor = $this->eligibleEvent($device);
        $this->assertLessThan($atFloor->id, $belowFloor->id);
        $this->assertGreaterThan($atFloor->id, $aboveFloor->id);

        config(['sync.stranded_sweep_after_id' => $atFloor->id]);

        // Sweep 1: only the above-floor row processes.
        $this->artisan('sync:sweep-stranded-events')
            ->expectsOutput('processed=1 failed=0 skipped=0')
            ->assertSuccessful();

        $this->assertSame(SyncEvent::STATUS_PROCESSED, $aboveFloor->fresh()->ack_status);
        $this->assertQuarantinedUntouched($belowFloor);
        $this->assertQuarantinedUntouched($atFloor);
        $this->assertDatabaseCount('pos_expenses', 1);

        // Sweeps 2 and 3: nothing changes — quarantine is permanent, and the
        // quarantined rows are not even counted as skipped (they are excluded
        // before candidacy).
        foreach ([2, 3] as $run) {
            $this->artisan('sync:sweep-stranded-events')
                ->expectsOutput('processed=0 failed=0 skipped=0')
                ->assertSuccessful();

            $this->assertQuarantinedUntouched($belowFloor);
            $this->assertQuarantinedUntouched($atFloor);
            $this->assertSame(1, Expense::query()->count(), "expense count drifted after sweep run {$run}");
        }

        // A --limit-constrained sweep must not change candidacy either: the
        // limit bounds work above the floor, it never reaches below it.
        $this->artisan('sync:sweep-stranded-events', ['--limit' => 1])
            ->expectsOutput('processed=0 failed=0 skipped=0')
            ->assertSuccessful();

        $this->assertQuarantinedUntouched($belowFloor);
        $this->assertQuarantinedUntouched($atFloor);
        $this->assertSame(SyncEvent::STATUS_PROCESSED, $aboveFloor->fresh()->ack_status);
        $this->assertDatabaseCount('pos_expenses', 1);
        $this->assertDatabaseCount('pos_sync_events', 3);
    }
}
