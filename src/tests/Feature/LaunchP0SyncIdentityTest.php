<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Device\IngestSyncEventsAction;
use App\Actions\Device\Sync\SyncEventDispatcher;
use App\Models\Device;
use App\Models\SyncEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LaunchP0SyncIdentityTest extends TestCase
{
    use RefreshDatabase;

    private function expense(): array
    {
        return ['client_event_id' => (string) Str::uuid(), 'event_type' => 'expense.log',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['category' => 'utilities', 'amount_baisas' => 100, 'staff_id' => 7]];
    }

    public function test_w3_receipt_is_stamped_and_w2_mismatched_tags_are_permanent(): void
    {
        $this->seedPosStaff([7]);
        $device = Device::factory()->paired('p0-sync')->create(['company_id' => 100, 'branch_id' => 10]);
        $event = $this->expense();
        $this->withToken('p0-sync')->postJson('/api/v1/device/sync/push', ['events' => [$event]])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertDatabaseHas('pos_sync_events', ['company_id' => 100, 'branch_id' => 10, 'client_event_id' => $event['client_event_id']]);
        $event = $this->expense();
        $event['identity'] = ['company_id' => 200, 'branch_id' => 20, 'device_uuid' => $device->uuid];
        $this->withToken('p0-sync')->postJson('/api/v1/device/sync/push', ['events' => [$event]])
            ->assertOk()->assertJsonPath('data.results.0.result.code', 'identity_mismatch')
            ->assertJsonPath('data.results.0.result.permanent', true);
        $this->assertDatabaseCount('pos_expenses', 1);
    }

    public function test_w3_retry_after_move_cannot_attribute_an_old_expense_to_new_company(): void
    {
        $device = Device::factory()->paired()->create(['company_id' => 100, 'branch_id' => 10]);
        $event = $this->expense();
        app(IngestSyncEventsAction::class)->handle($device, [$event]); // unknown staff => failed
        $row = SyncEvent::firstOrFail();
        $this->assertSame('failed', $row->ack_status);
        $device->forceFill(['company_id' => 200, 'branch_id' => 20])->save();
        $result = app(IngestSyncEventsAction::class)->handle($device, [$event]);
        $this->assertSame('needs_review', $result['data']['results'][0]['status']);
        $this->assertSame(100, (int) $row->fresh()->company_id);
        $this->assertDatabaseCount('pos_expenses', 0);
    }

    public function test_w3_dispatch_rechecks_stored_identity_under_lock(): void
    {
        $device = Device::factory()->paired()->create(['company_id' => 100, 'branch_id' => 10]);
        foreach (['expense.log', 'shift.open', 'slider.display'] as $type) {
            $row = SyncEvent::create([
                'client_event_id' => (string) Str::uuid(), 'device_id' => $device->id,
                'company_id' => 200, 'branch_id' => 20, 'event_type' => $type,
                'client_timestamp' => now(), 'payload_json' => [], 'ack_status' => 'received', 'server_received_at' => now(),
            ]);
            app(SyncEventDispatcher::class)->dispatch($row, $device);
            $this->assertSame('needs_review', $row->fresh()->ack_status);
        }
        $this->assertDatabaseCount('pos_expenses', 0);
        $this->assertDatabaseCount('pos_shifts', 0);
    }

    public function test_w7_suspension_blocks_config_and_the_live_l11_expense(): void
    {
        $device = Device::factory()->paired('p0-suspended')->create();
        DB::table('pos_companies')->where('id', $device->company_id)->update(['status' => 'suspended']);
        $this->withToken('p0-suspended')->getJson('/api/v1/device/config')
            ->assertStatus(503)->assertJsonPath('errors.0.code', 'company_suspended');
        $this->withToken('p0-suspended')->postJson('/api/v1/device/sync/push', ['events' => [$this->expense()]])
            ->assertStatus(503)->assertJsonPath('errors.0.code', 'company_suspended');
        $this->assertDatabaseCount('pos_expenses', 0);
    }
}
