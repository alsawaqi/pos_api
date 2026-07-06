<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceActivationToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Single-code device activation (POST /api/v1/auth/device/activate) — the
 * device exchanges one admin-generated code for a device_token, and receives
 * its kiosk_id + terminal_id for the Soft POS.
 */
class DeviceActivateTest extends TestCase
{
    use RefreshDatabase;

    public function test_activates_with_a_valid_code(): void
    {
        $device = Device::factory()->create([
            'kiosk_id' => 'KIOSK-ACT',
            'terminal_id' => 'TERM-ACT',
            'terminal_pin' => '4821',
        ]);
        DeviceActivationToken::factory()->for($device)->forPlaintext('code_abc')->create();

        $res = $this->postJson('/api/v1/auth/device/activate', ['code' => 'code_abc'])->assertOk();

        $this->assertNotEmpty($res->json('data.device_token'));
        $this->assertSame('KIOSK-ACT', $res->json('data.device.kiosk_id'));
        $this->assertSame('TERM-ACT', $res->json('data.device.terminal_id'));
        $this->assertSame('4821', $res->json('data.device.terminal_pin'));
        $this->assertSame((int) $device->company_id, $res->json('data.device.company_id'));

        $device->refresh();
        $this->assertSame($res->json('data.device_token'), $device->device_token);
    }

    public function test_rejects_an_unknown_code(): void
    {
        $this->postJson('/api/v1/auth/device/activate', ['code' => 'nope'])->assertStatus(422);
    }

    public function test_rejects_an_expired_code(): void
    {
        $device = Device::factory()->create();
        DeviceActivationToken::factory()->for($device)->forPlaintext('exp_code')->expired()->create();

        $this->postJson('/api/v1/auth/device/activate', ['code' => 'exp_code'])->assertStatus(422);
        $this->assertNull($device->fresh()->device_token);
    }

    public function test_rejects_an_already_used_code(): void
    {
        $device = Device::factory()->create();
        DeviceActivationToken::factory()->for($device)->forPlaintext('used_code')->used()->create();

        $this->postJson('/api/v1/auth/device/activate', ['code' => 'used_code'])->assertStatus(422);
    }

    public function test_rejects_an_unassigned_device(): void
    {
        $device = Device::factory()->unassigned()->create();
        DeviceActivationToken::factory()->for($device)->forPlaintext('una_code')->create();

        $this->postJson('/api/v1/auth/device/activate', ['code' => 'una_code'])->assertStatus(422);
        $this->assertNull($device->fresh()->device_token);
    }

    public function test_a_code_cannot_be_reused_after_a_successful_activation(): void
    {
        $device = Device::factory()->create();
        DeviceActivationToken::factory()->for($device)->forPlaintext('once_code')->create();

        $this->postJson('/api/v1/auth/device/activate', ['code' => 'once_code'])->assertOk();
        $this->postJson('/api/v1/auth/device/activate', ['code' => 'once_code'])->assertStatus(422);
    }

    public function test_validation_requires_a_code(): void
    {
        $this->postJson('/api/v1/auth/device/activate', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }
}
