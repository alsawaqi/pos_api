<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Device\IngestSyncEventsAction;
use App\Models\Device;
use App\Models\DeviceActivationToken;
use App\Models\SyncEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LaunchP0FixOrder1ApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_b3_dead_token_is_bare_401_for_every_release_parser_and_wrong_pin_stays_structured(): void
    {
        $response = $this->withToken('dead')->getJson('/api/v1/device/config')->assertUnauthorized();
        $body = $response->json();
        $this->assertSame('Unauthenticated.', $body['message']);
        $this->assertArrayNotHasKey('errors', $body);
        // All three main releases only reset setup when a 401 has no errors.
        foreach (['till 01d17de', 'handheld 8b3dc8d', 'station 2491f73'] as $release) {
            $this->assertTrue($this->legacyNeedsSetup(401, $body), $release);
        }
        $device = Device::factory()->paired('pin-device')->create();
        Auth::forgetGuards();
        $pin = $this->withToken('pin-device')->postJson('/api/v1/device/auth/verify-manager-pin', ['pin' => '9999'])
            ->assertUnauthorized()->assertJsonPath('errors.0.code', 'invalid_pin');
        $this->assertFalse($this->legacyNeedsSetup(401, $pin->json()));
    }

    private function legacyNeedsSetup(int $status, array $body): bool
    {
        if (isset($body['errors']) && is_array($body['errors']) && count($body['errors']) > 0) {
            return false;
        }

        return $status === 401;
    }

    public function test_b3_b7_all_company_statuses_and_retryable_suspension(): void
    {
        $device = Device::factory()->paired('status-device')->create();
        foreach (['active' => 200, 'onboarding' => 200, 'suspended' => 503, 'inactive' => 503] as $status => $expected) {
            DB::table('pos_companies')->where('id', $device->company_id)->update(['status' => $status]);
            Auth::forgetGuards();
            $r = $this->withToken('status-device')->postJson('/api/v1/device/heartbeat')->assertStatus($expected);
            if ($expected === 503) {
                $r->assertHeader('Retry-After', '60')->assertJsonPath('errors.0.code', 'company_suspended');
                $this->assertFalse($this->legacyNeedsSetup(503, $r->json()));
                // The release till's deterministic refusal rule excludes 5xx;
                // repeated suspension never reaches its five-refusal parking path.
                $deterministic = $r->status() >= 400 && $r->status() < 500;
                $this->assertFalse($deterministic);
            }
        }
    }

    public function test_b6_processed_historical_duplicate_keeps_original_ack_and_result(): void
    {
        $device = Device::factory()->paired()->create();
        $event = ['client_event_id' => (string) Str::uuid(), 'event_type' => 'sync.noop', 'client_timestamp' => now()->subDay()->toIso8601String(), 'payload' => []];
        $row = SyncEvent::create(['device_id' => $device->id,
            'company_id' => $device->company_id, 'branch_id' => $device->branch_id,
            'client_event_id' => $event['client_event_id'], 'event_type' => $event['event_type'],
            'client_timestamp' => now()->subDay(), 'server_received_at' => now()->subDay(), 'ack_status' => 'processed',
            'processed_at' => now()->subDay(), 'payload_json' => [], 'result_json' => ['receipt' => 'original']]);
        $result = app(IngestSyncEventsAction::class)->handle($device, [$event])['data']['results'][0];
        $this->assertSame('processed', $result['status']);
        $this->assertSame(['receipt' => 'original'], $result['result']);
        $this->assertSame('processed', $row->fresh()->ack_status);
    }

    public function test_b6_unknown_processed_history_is_acked_without_exposing_an_unproven_receipt(): void
    {
        $device = Device::factory()->paired()->create();
        $event = ['client_event_id' => (string) Str::uuid(), 'event_type' => 'sync.noop',
            'client_timestamp' => now()->subDay()->toIso8601String(), 'payload' => []];
        SyncEvent::create(['device_id' => $device->id, 'client_event_id' => $event['client_event_id'],
            'event_type' => 'sync.noop', 'client_timestamp' => now()->subDay(),
            'server_received_at' => now()->subDay(), 'ack_status' => 'processed',
            'payload_json' => ['different_original' => true], 'result_json' => ['receipt' => 'unproven merchant']]);
        $ack = app(IngestSyncEventsAction::class)->handle($device, [$event])['data']['results'][0];
        $this->assertSame('processed', $ack['status']);
        $this->assertNull($ack['result']);
    }

    public function test_b8_untagged_old_shape_has_exact_five_minute_drift_allowance(): void
    {
        $device = Device::factory()->paired()->create();
        // Attribute on the model lets this test demonstrate candidate behavior
        // before the additive schema change exists.
        $device->setAttribute('token_issued_at', now());
        foreach ([-301, -300, 1] as $seconds) {
            $event = ['client_event_id' => (string) Str::uuid(), 'event_type' => 'sync.noop',
                'client_timestamp' => now()->addSeconds($seconds)->toIso8601String(), 'payload' => []];
            $result = app(IngestSyncEventsAction::class)->handle($device, [$event])['data']['results'][0];
            if ($seconds < -300) {
                $this->assertSame('identity_mismatch', $result['result']['code'] ?? null);
                $this->assertTrue($result['result']['permanent']);
                $this->assertDatabaseHas('pos_sync_events', ['client_event_id' => $event['client_event_id'],
                    'device_id' => $device->id, 'ack_status' => 'needs_review', 'company_id' => null, 'branch_id' => null]);
            } else {
                $this->assertSame('received', $result['status']);
            }
        }
    }

    public function test_fix1_web_broadcast_route_rejects_suspended_company(): void
    {
        $device = Device::factory()->paired('web-broadcast')->create();
        DB::table('pos_companies')->where('id', $device->company_id)->update(['status' => 'suspended']);
        $this->withToken('web-broadcast')->postJson('/broadcasting/auth', [
            'socket_id' => '1.1', 'channel_name' => 'private-device.'.$device->uuid,
        ])->assertStatus(503)->assertJsonPath('errors.0.code', 'company_suspended');
    }

    public function test_fix1_activation_rejects_code_older_than_current_assignment(): void
    {
        $device = Device::factory()->create(['assigned_at' => now()]);
        DeviceActivationToken::factory()->for($device)->forPlaintext('old-assignment-code')
            ->create(['created_at' => now()->subHour()]);
        $this->postJson('/api/v1/auth/device/activate', ['code' => 'old-assignment-code'])
            ->assertUnprocessable();
        $this->assertNull($device->fresh()->device_token);
    }
}
