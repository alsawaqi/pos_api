<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Device\Sync\SyncEventDispatchLock;
use App\Models\Device;
use App\Models\Expense;
use App\Models\SyncEvent;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

/** API-002 - recovery for handled events left received by a killed worker. */
class DeviceSyncStrandedEventTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPosStaff([7]);

        // This suite creates a fresh ledger with no historical quarantine.
        config([
            'sync.stranded_sweep_after_id' => 0,
            'sync.stranded_sweep_enabled' => false,
        ]);
    }

    private function device(string $token = 'mdev_stranded'): Device
    {
        return Device::factory()->paired($token)->create([
            'company_id' => 100,
            'branch_id' => 10,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function event(Device $device, array $overrides = []): SyncEvent
    {
        return SyncEvent::create(array_merge([
            'client_event_id' => (string) Str::uuid(),
            'device_id' => $device->id,
            'company_id' => $device->company_id, 'branch_id' => $device->branch_id,
            'event_type' => 'expense.log',
            'payload_json' => [
                'category' => 'utilities',
                'amount_baisas' => 5000,
                'note' => 'water bill',
                'staff_id' => 7,
            ],
            'client_timestamp' => now(),
            'server_received_at' => now()->subMinutes(11),
            'ack_status' => SyncEvent::STATUS_RECEIVED,
        ], $overrides));
    }

    public function test_sweep_fails_closed_when_the_historical_floor_is_missing(): void
    {
        $event = $this->event($this->device());
        config(['sync.stranded_sweep_after_id' => null]);
        Log::spy();

        $this->artisan('sync:sweep-stranded-events')
            ->expectsOutput('SYNC_STRANDED_SWEEP_AFTER_ID must be configured as a nonnegative integer.')
            ->assertExitCode(Command::INVALID);

        $this->assertSame(SyncEvent::STATUS_RECEIVED, $event->fresh()->ack_status);
        $this->assertDatabaseCount('pos_expenses', 0);
        Log::shouldHaveReceived('error')
            ->once()
            ->with(
                'Stranded sync event sweep aborted because its historical quarantine floor is missing or invalid',
                ['config_key' => 'sync.stranded_sweep_after_id'],
            );
    }

    public function test_sweep_fails_closed_when_the_historical_floor_is_invalid(): void
    {
        $event = $this->event($this->device());
        Log::spy();

        foreach ([-1, 'not-an-integer', true] as $invalidFloor) {
            config(['sync.stranded_sweep_after_id' => $invalidFloor]);

            $this->artisan('sync:sweep-stranded-events')
                ->expectsOutput('SYNC_STRANDED_SWEEP_AFTER_ID must be configured as a nonnegative integer.')
                ->assertExitCode(Command::INVALID);
        }

        $this->assertSame(SyncEvent::STATUS_RECEIVED, $event->fresh()->ack_status);
        $this->assertDatabaseCount('pos_expenses', 0);
        Log::shouldHaveReceived('error')
            ->times(3)
            ->with(
                'Stranded sync event sweep aborted because its historical quarantine floor is missing or invalid',
                ['config_key' => 'sync.stranded_sweep_after_id'],
            );
    }

    public function test_sweep_quarantines_ids_at_or_below_the_floor_and_processes_only_newer_events(): void
    {
        $device = $this->device();
        $belowFloor = $this->event($device);
        $atFloor = $this->event($device);
        $aboveFloor = $this->event($device);
        config(['sync.stranded_sweep_after_id' => $atFloor->id]);

        $this->artisan('sync:sweep-stranded-events')
            ->expectsOutput('processed=1 failed=0 skipped=0')
            ->assertSuccessful();

        $this->assertLessThan($atFloor->id, $belowFloor->id);
        $this->assertGreaterThan($atFloor->id, $aboveFloor->id);
        $this->assertSame(SyncEvent::STATUS_RECEIVED, $belowFloor->fresh()->ack_status);
        $this->assertSame(SyncEvent::STATUS_RECEIVED, $atFloor->fresh()->ack_status);
        $this->assertSame(SyncEvent::STATUS_PROCESSED, $aboveFloor->fresh()->ack_status);
        $this->assertDatabaseCount('pos_expenses', 1);
    }

    public function test_sweep_processes_only_old_received_events_with_registered_handlers(): void
    {
        $device = $this->device();
        $stranded = $this->event($device, ['client_timestamp' => now()]);
        $fresh = $this->event($device, [
            'client_timestamp' => now()->subHours(4),
            'server_received_at' => now()->subMinutes(9),
        ]);
        $handlerless = $this->event($device, ['event_type' => 'sync.noop']);
        $unknown = $this->event($device, ['event_type' => 'order.typo']);
        $failed = $this->event($device, [
            'ack_status' => SyncEvent::STATUS_FAILED,
            'processed_at' => now()->subMinutes(10),
            'result_json' => ['error' => 'already rejected'],
        ]);
        $processed = $this->event($device, [
            'ack_status' => SyncEvent::STATUS_PROCESSED,
            'processed_at' => now()->subMinutes(10),
            'result_json' => ['expense_id' => 999],
        ]);

        $this->artisan('sync:sweep-stranded-events')
            ->expectsOutput('processed=1 failed=0 skipped=0')
            ->assertSuccessful();

        $recovered = $stranded->fresh();
        $this->assertSame(SyncEvent::STATUS_PROCESSED, $recovered->ack_status);
        $this->assertNotNull($recovered->processed_at);
        $this->assertSame((int) Expense::firstOrFail()->id, (int) $recovered->result_json['expense_id']);
        $this->assertSame(SyncEvent::STATUS_RECEIVED, $fresh->fresh()->ack_status);
        $this->assertSame(SyncEvent::STATUS_RECEIVED, $handlerless->fresh()->ack_status);
        $this->assertSame(SyncEvent::STATUS_RECEIVED, $unknown->fresh()->ack_status);
        $this->assertSame(SyncEvent::STATUS_FAILED, $failed->fresh()->ack_status);
        $this->assertSame(SyncEvent::STATUS_PROCESSED, $processed->fresh()->ack_status);
        $this->assertDatabaseCount('pos_expenses', 1);

        $this->artisan('sync:sweep-stranded-events')->assertSuccessful();
        $this->assertDatabaseCount('pos_expenses', 1);
    }

    public function test_sweep_marks_a_handler_rejection_failed_and_continues(): void
    {
        $device = $this->device();
        $invalid = $this->event($device, [
            'payload_json' => ['category' => 'bogus', 'amount_baisas' => 5000, 'staff_id' => 7],
        ]);
        $valid = $this->event($device);

        $this->artisan('sync:sweep-stranded-events')
            ->expectsOutput('processed=1 failed=1 skipped=0')
            ->assertSuccessful();

        $this->assertSame(SyncEvent::STATUS_FAILED, $invalid->fresh()->ack_status);
        $this->assertStringContainsString('category', (string) $invalid->fresh()->result_json['error']);
        $this->assertSame(SyncEvent::STATUS_PROCESSED, $valid->fresh()->ack_status);
        $this->assertDatabaseCount('pos_expenses', 1);
    }

    public function test_sweep_serializes_with_other_redispatch_paths(): void
    {
        $event = $this->event($this->device());
        $lock = app(SyncEventDispatchLock::class)->forEvent($event);
        $this->assertTrue($lock->get());

        try {
            $this->artisan('sync:sweep-stranded-events')
                ->expectsOutput('processed=0 failed=0 skipped=1')
                ->assertSuccessful();
            $this->assertSame(SyncEvent::STATUS_RECEIVED, $event->fresh()->ack_status);
            $this->assertDatabaseCount('pos_expenses', 0);
        } finally {
            $lock->release();
        }

        $this->artisan('sync:sweep-stranded-events')->assertSuccessful();
        $this->assertSame(SyncEvent::STATUS_PROCESSED, $event->fresh()->ack_status);
        $this->assertDatabaseCount('pos_expenses', 1);
    }

    public function test_sweep_uses_soft_deleted_devices_and_skips_unsafe_assignment(): void
    {
        Log::spy();

        $softDevice = $this->device();
        $softDeleted = $this->event($softDevice);
        $softDevice->delete();
        $missing = $this->event($softDevice, ['device_id' => null]);

        $reassignedDevice = $this->device('mdev_reassigned');
        $reassigned = $this->event($reassignedDevice);
        $reassignedDevice->forceFill(['assigned_at' => now()])->save();

        $this->artisan('sync:sweep-stranded-events')
            ->expectsOutput('processed=1 failed=0 skipped=2')
            ->assertSuccessful();

        $this->assertSame(SyncEvent::STATUS_PROCESSED, $softDeleted->fresh()->ack_status);
        $this->assertSame(SyncEvent::STATUS_NEEDS_REVIEW, $missing->fresh()->ack_status);
        $this->assertSame(SyncEvent::STATUS_NEEDS_REVIEW, $reassigned->fresh()->ack_status);
        $this->assertDatabaseCount('pos_expenses', 1);
        Log::shouldHaveReceived('warning')->twice();
        Log::shouldHaveReceived('info')->once();
    }

    public function test_sweep_refuses_an_age_guard_shorter_than_the_live_request_ceiling(): void
    {
        $event = $this->event($this->device());

        $this->artisan('sync:sweep-stranded-events', ['--older-than' => 9])
            ->expectsOutput('--older-than must be an integer of at least 10.')
            ->assertExitCode(Command::INVALID);

        $this->assertSame(SyncEvent::STATUS_RECEIVED, $event->fresh()->ack_status);
        $this->assertDatabaseCount('pos_expenses', 0);
    }

    public function test_permanent_skip_before_the_limit_does_not_starve_a_later_event(): void
    {
        $device = $this->device();
        $unsafe = $this->event($device, ['device_id' => null]);
        $recoverable = $this->event($device);

        $this->artisan('sync:sweep-stranded-events', ['--limit' => 1])
            ->expectsOutput('processed=1 failed=0 skipped=1')
            ->assertSuccessful();

        $this->assertLessThan($recoverable->id, $unsafe->id);
        $this->assertSame(SyncEvent::STATUS_NEEDS_REVIEW, $unsafe->fresh()->ack_status);
        $this->assertSame(SyncEvent::STATUS_PROCESSED, $recoverable->fresh()->ack_status);
        $this->assertDatabaseCount('pos_expenses', 1);
    }

    public function test_production_runtime_keeps_scheduler_and_cached_config_on_one_stable_contract(): void
    {
        $compose = file_get_contents(base_path('../docker-compose.prod.yml'));
        $deploy = file_get_contents(base_path('../deploy/deploy.sh'));

        $this->assertIsString($compose);
        $this->assertIsString($deploy);

        $servicesMarker = strpos($compose, "\nservices:\n");
        $this->assertNotFalse($servicesMarker);

        $anchor = substr($compose, 0, $servicesMarker);
        foreach ([
            'APP_ENV: production',
            'APP_DEBUG: "false"',
            'LOG_CHANNEL: stderr',
            'CACHE_STORE: redis',
            'REDIS_HOST: pos_api_redis',
        ] as $setting) {
            $this->assertStringContainsString($setting, $anchor);
        }

        $this->assertStringNotContainsString('SYNC_STRANDED_SWEEP_', $anchor);
        $this->assertStringNotContainsString('BROADCAST_CONNECTION', $anchor);
        $this->assertStringNotContainsString('QUEUE_CONNECTION', $anchor);
        $this->assertSame(3, substr_count($compose, '<<: *pos-api-runtime-environment'));
        $this->assertStringContainsString('command: ["php", "artisan", "schedule:work"]', $compose);
        $this->assertStringContainsString('docker compose -f "$C" restart pos_api', $deploy);
        $this->assertStringNotContainsString('docker restart ', $deploy);
        $this->assertStringNotContainsString('restart scheduler', $deploy);
        $this->assertStringContainsString('for service in pos_api scheduler; do', $deploy);
        $this->assertStringContainsString(
            'logs --since "$deploy_restart_since" --no-color pos_api scheduler',
            $deploy,
        );
        $this->assertStringContainsString('if ! code=$(curl', $deploy);
        $this->assertStringContainsString('[[ "$code" =~ ^[1-4][0-9]{2}$ ]]', $deploy);
        $this->assertStringNotContainsString('[ "$code" -lt 500 ]', $deploy);

        $cacheBuild = strpos($deploy, 'timeout 300 docker compose -f "$C" --profile deploy run --rm deploy');
        $runtimeStart = strpos($deploy, 'docker compose -f "$C" up -d');
        $this->assertNotFalse($cacheBuild);
        $this->assertNotFalse($runtimeStart);
        $this->assertLessThan($runtimeStart, $cacheBuild);
    }

    public function test_sweeper_is_registered_every_minute_with_overlap_guards(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'sync:sweep-stranded-events'));

        $this->assertNotNull($scheduled);
        $this->assertSame('* * * * *', $scheduled->expression);
        $this->assertTrue($scheduled->withoutOverlapping);
        $this->assertSame(30, $scheduled->expiresAt);
        $this->assertTrue($scheduled->onOneServer);

        $this->assertFalse($scheduled->filtersPass(app()));

        config(['sync.stranded_sweep_enabled' => true]);

        $this->assertTrue($scheduled->filtersPass(app()));
    }
}
