<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceActivationToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $device = Device::factory()->withSoftPos()->create([
            'kiosk_id' => 'KIOSK-ACT',
            'terminal_id' => 'TERM-ACT',
            'terminal_pin' => '4821',
        ]);
        DeviceActivationToken::factory()->for($device)->forPlaintext('code_abc')->create();

        $res = $this->postJson('/api/v1/auth/device/activate', ['code' => 'code_abc', 'serial' => $device->serial_number])->assertOk();

        $this->assertNotEmpty($res->json('data.device_token'));
        $this->assertSame('KIOSK-ACT', $res->json('data.device.kiosk_id'));
        $this->assertSame('TERM-ACT', $res->json('data.device.terminal_id'));
        $this->assertSame('4821', $res->json('data.device.terminal_pin'));
        $this->assertSame((int) $device->company_id, $res->json('data.device.company_id'));

        $device->refresh();
        $this->assertSame(hash('sha256', $res->json('data.device_token')), $device->device_token);
        $this->assertSame('active', $device->status);
    }

    public function test_assigned_payment_station_can_rotate_immediately_after_activation(): void
    {
        $device = Device::factory()->create([
            'device_type' => 'payment_station',
            'status' => 'assigned',
        ]);
        DeviceActivationToken::factory()->for($device)->forPlaintext('station_code')->create();

        $activation = $this->postJson('/api/v1/auth/device/activate', [
            'code' => 'station_code',
            'serial' => $device->serial_number,
            'app' => 'station',
        ])->assertOk();

        $deviceToken = $activation->json('data.device_token');
        $this->assertIsString($deviceToken);
        $this->assertNotSame('', $deviceToken);
        $this->assertSame('active', $device->fresh()->status);

        $rotation = $this->withToken($deviceToken)
            ->postJson('/api/v1/device/qr/rotate')
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['token', 'token_expires_at', 'expires_at'],
                'errors',
            ])
            ->assertJsonPath('errors', []);

        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $rotation->json('data.token'));
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

    #[DataProvider('nonActivatableStatuses')]
    public function test_blocked_or_inactive_device_is_not_promoted_and_code_is_not_consumed(string $status): void
    {
        $code = $status.'_code';
        $device = Device::factory()->create(['status' => $status]);
        $activationToken = DeviceActivationToken::factory()
            ->for($device)
            ->forPlaintext($code)
            ->create();

        $this->postJson('/api/v1/auth/device/activate', ['code' => $code])
            ->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'activation_failed')
            ->assertJsonPath('errors.0.message', 'Activation failed: device is not active.');

        $device->refresh();
        $activationToken->refresh();

        $this->assertSame($status, $device->status);
        $this->assertNull($device->device_token);
        $this->assertNull($activationToken->used_at);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonActivatableStatuses(): array
    {
        return [
            'blocked' => ['blocked'],
            'inactive' => ['inactive'],
        ];
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

        $claim = ['code' => 'once_code', 'serial' => $device->serial_number];
        $this->postJson('/api/v1/auth/device/activate', $claim)->assertOk();
        $this->postJson('/api/v1/auth/device/activate', $claim)->assertStatus(422)
            ->assertJsonPath('code', 'activation_failed');
    }

    public function test_validation_requires_a_code(): void
    {
        $this->postJson('/api/v1/auth/device/activate', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }
}
