<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceActivationToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LaunchP0DeviceIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_activation_stores_only_hash_and_binds_both_identity_fields(): void
    {
        $device = Device::factory()->create();
        DeviceActivationToken::factory()->for($device)->forPlaintext('p0-code')->create();
        $raw = $this->postJson('/api/v1/auth/device/activate', ['code' => 'p0-code'])
            ->assertOk()->json('data.device_token');

        $stored = DB::table('pos_devices')->where('id', $device->id)->first();
        $this->assertNotSame($raw, $stored->device_token);
        $this->assertSame(hash('sha256', $raw), $stored->device_token);
        $this->assertSame((int) $device->company_id, (int) $stored->token_company_id);
        $this->assertSame((int) $device->branch_id, (int) $stored->token_branch_id);
        Auth::forgetGuards();
        $this->withToken($raw)->postJson('/api/v1/device/heartbeat')->assertOk();
        Auth::forgetGuards();
        $this->withToken($stored->device_token)->postJson('/api/v1/device/heartbeat')->assertUnauthorized();
    }

    public function test_old_token_is_refused_on_every_surface_after_identity_change(): void
    {
        $device = Device::factory()->create();
        DeviceActivationToken::factory()->for($device)->forPlaintext('move-code')->create();
        $raw = $this->postJson('/api/v1/auth/device/activate', ['code' => 'move-code'])
            ->assertOk()->json('data.device_token');
        DB::table('pos_devices')->where('id', $device->id)->update(['branch_id' => 999]);
        foreach ([
            ['get', 'device/config'], ['get', 'device/customers/search?q=90909090'],
            ['get', 'device/reports/branch'], ['get', 'device/orders/history'],
            ['post', 'device/sync/push'], ['post', 'broadcasting/auth'],
        ] as [$method, $path]) {
            Auth::forgetGuards();
            $this->withToken($raw)->json(strtoupper($method), '/api/v1/'.$path)
                ->assertUnauthorized()->assertJsonPath('errors.0.code', 'device_reactivation_required');
        }
    }

    public function test_inactive_or_unassigned_device_is_never_authenticated(): void
    {
        foreach (['registered', 'assigned', 'inactive', 'blocked'] as $status) {
            $device = Device::factory()->create();
            $code = 'status-'.$status;
            DeviceActivationToken::factory()->for($device)->forPlaintext($code)->create();
            Auth::forgetGuards();
            $raw = $this->postJson('/api/v1/auth/device/activate', ['code' => $code])
                ->assertOk()->json('data.device_token');
            DB::table('pos_devices')->where('id', $device->id)->update(['status' => $status]);
            Auth::forgetGuards();
            $this->withToken($raw)->postJson('/api/v1/device/heartbeat')
                ->assertUnauthorized()->assertJsonPath('errors.0.code', 'device_reactivation_required');
        }
    }
}
