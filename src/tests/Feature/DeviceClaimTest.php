<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Device claim by terminal_id (POST /api/v1/auth/device/claim) — the
 * pairing-free alternative used when a device is identified by the bank
 * terminal_id set at assignment.
 */
class DeviceClaimTest extends TestCase
{
    use RefreshDatabase;

    public function test_claims_with_a_valid_terminal_id(): void
    {
        $device = Device::factory()->create(['terminal_id' => 'TERM-001']);

        $res = $this->postJson('/api/v1/auth/device/claim', [
            'terminal_id' => 'TERM-001',
        ])->assertOk();

        $this->assertNotEmpty($res->json('data.device_token'));
        $this->assertSame('TERM-001', $res->json('data.device.terminal_id'));
        $this->assertSame((int) $device->company_id, $res->json('data.device.company_id'));

        $device->refresh();
        $this->assertSame($res->json('data.device_token'), $device->device_token);
    }

    public function test_rejects_an_unknown_terminal_id(): void
    {
        $this->postJson('/api/v1/auth/device/claim', [
            'terminal_id' => 'NOPE',
        ])->assertStatus(422);
    }

    public function test_rejects_an_unassigned_device(): void
    {
        $device = Device::factory()->unassigned()->create(['terminal_id' => 'TERM-UNA']);

        $this->postJson('/api/v1/auth/device/claim', [
            'terminal_id' => 'TERM-UNA',
        ])->assertStatus(422);

        $this->assertNull($device->fresh()->device_token);
    }

    public function test_rejects_a_blocked_device(): void
    {
        Device::factory()->create(['terminal_id' => 'TERM-BLK', 'status' => 'blocked']);

        $this->postJson('/api/v1/auth/device/claim', [
            'terminal_id' => 'TERM-BLK',
        ])->assertStatus(422);
    }

    public function test_rejects_an_ambiguous_terminal_id(): void
    {
        Device::factory()->create(['terminal_id' => 'TERM-DUP', 'bank_id' => null]);
        Device::factory()->create(['terminal_id' => 'TERM-DUP', 'bank_id' => null]);

        $this->postJson('/api/v1/auth/device/claim', [
            'terminal_id' => 'TERM-DUP',
        ])->assertStatus(422);
    }

    public function test_validation_requires_terminal_id(): void
    {
        $this->postJson('/api/v1/auth/device/claim', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['terminal_id']);
    }
}
