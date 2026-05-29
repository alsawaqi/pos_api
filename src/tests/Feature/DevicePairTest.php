<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceActivationToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 8.0 — device pairing (POST /api/v1/auth/device/pair).
 */
class DevicePairTest extends TestCase
{
    use RefreshDatabase;

    public function test_pairs_with_a_valid_activation_token(): void
    {
        $device = Device::factory()->create(['kiosk_id' => 'KIOSK-AAA']);
        $token = DeviceActivationToken::factory()->for($device)->forPlaintext('mithqal_secret')->create();

        $res = $this->postJson('/api/v1/auth/device/pair', [
            'kiosk_id' => 'KIOSK-AAA',
            'activation_token' => 'mithqal_secret',
        ])->assertOk();

        $this->assertNotEmpty($res->json('data.device_token'));
        $this->assertSame((int) $device->company_id, $res->json('data.device.company_id'));

        $device->refresh();
        $this->assertNotNull($device->device_token);
        $this->assertSame($res->json('data.device_token'), $device->device_token);

        // Activation token is single-use.
        $token->refresh();
        $this->assertNotNull($token->used_at);
    }

    public function test_rejects_an_unknown_kiosk(): void
    {
        $this->postJson('/api/v1/auth/device/pair', [
            'kiosk_id' => 'NOPE',
            'activation_token' => 'whatever',
        ])->assertStatus(422);
    }

    public function test_rejects_an_expired_token(): void
    {
        $device = Device::factory()->create(['kiosk_id' => 'KIOSK-EXP']);
        DeviceActivationToken::factory()->for($device)->forPlaintext('expired_tok')->expired()->create();

        $this->postJson('/api/v1/auth/device/pair', [
            'kiosk_id' => 'KIOSK-EXP',
            'activation_token' => 'expired_tok',
        ])->assertStatus(422);

        $this->assertNull($device->fresh()->device_token);
    }

    public function test_rejects_an_already_used_token(): void
    {
        $device = Device::factory()->create(['kiosk_id' => 'KIOSK-USED']);
        DeviceActivationToken::factory()->for($device)->forPlaintext('used_tok')->used()->create();

        $this->postJson('/api/v1/auth/device/pair', [
            'kiosk_id' => 'KIOSK-USED',
            'activation_token' => 'used_tok',
        ])->assertStatus(422);
    }

    public function test_rejects_an_unassigned_device(): void
    {
        $device = Device::factory()->unassigned()->create(['kiosk_id' => 'KIOSK-UNA']);
        DeviceActivationToken::factory()->for($device)->forPlaintext('tok')->create();

        $this->postJson('/api/v1/auth/device/pair', [
            'kiosk_id' => 'KIOSK-UNA',
            'activation_token' => 'tok',
        ])->assertStatus(422);
    }

    public function test_validation_requires_kiosk_and_token(): void
    {
        $this->postJson('/api/v1/auth/device/pair', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['kiosk_id', 'activation_token']);
    }

    public function test_a_token_cannot_be_reused_after_a_successful_pair(): void
    {
        $device = Device::factory()->create(['kiosk_id' => 'KIOSK-ONCE']);
        DeviceActivationToken::factory()->for($device)->forPlaintext('once_tok')->create();

        $this->postJson('/api/v1/auth/device/pair', ['kiosk_id' => 'KIOSK-ONCE', 'activation_token' => 'once_tok'])->assertOk();
        // Second attempt with the now-consumed token must fail.
        $this->postJson('/api/v1/auth/device/pair', ['kiosk_id' => 'KIOSK-ONCE', 'activation_token' => 'once_tok'])->assertStatus(422);
    }
}
