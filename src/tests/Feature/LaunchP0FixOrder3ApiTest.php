<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SyncEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class LaunchP0FixOrder3ApiTest extends TestCase
{
    use RefreshDatabase;

    private function expense(): array
    {
        return ['client_event_id' => (string) Str::uuid(), 'event_type' => 'expense.log',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['category' => 'utilities', 'amount_baisas' => 100, 'staff_id' => 7]];
    }

    public function test_d4_a_refused_tagged_sale_keeps_the_identity_the_device_stamped_on_it(): void
    {
        $this->seedPosStaff([7]);
        $device = Device::factory()->paired('p0-fix3')->create(['company_id' => 100, 'branch_id' => 10]);
        $event = $this->expense();
        $event['identity'] = ['company_id' => 200, 'branch_id' => 20, 'device_uuid' => $device->uuid];

        $this->withToken('p0-fix3')->postJson('/api/v1/device/sync/push', ['events' => [$event]])
            ->assertOk()->assertJsonPath('data.results.0.status', 'needs_review')
            ->assertJsonPath('data.results.0.result.code', 'identity_mismatch');

        $row = SyncEvent::query()->where('client_event_id', $event['client_event_id'])->sole();
        $this->assertNull($row->company_id);
        $this->assertSame(['company_id' => 200, 'branch_id' => 20, 'device_uuid' => $device->uuid],
            $row->result_json['claimed_identity']);
    }

    public function test_d4_a_refused_untagged_sale_claims_no_identity(): void
    {
        $this->seedPosStaff([7]);
        Device::factory()->paired('p0-fix3u')->create(['company_id' => 100, 'branch_id' => 10,
            'assignment_activated_at' => now()->subHour()]);
        $event = $this->expense();
        $event['client_timestamp'] = now()->subDay()->toIso8601String();

        $this->withToken('p0-fix3u')->postJson('/api/v1/device/sync/push', ['events' => [$event]])
            ->assertOk()->assertJsonPath('data.results.0.status', 'needs_review');

        $row = SyncEvent::query()->where('client_event_id', $event['client_event_id'])->sole();
        $this->assertArrayNotHasKey('claimed_identity', $row->result_json);
    }
}
