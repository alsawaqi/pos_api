<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Shift;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DeviceStationShiftRuleTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $token, string $type): Device
    {
        return Device::factory()->paired($token)->create([
            'company_id' => 100,
            'branch_id' => 10,
            'device_type' => $type,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function openEvent(): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'shift.open',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => [
                'uuid' => (string) Str::uuid(),
                'opening_cash_baisas' => 0,
                'opened_at' => now()->toIso8601String(),
            ],
        ];
    }

    public function test_payment_stations_and_customer_tablets_cannot_open_shifts(): void
    {
        foreach (['payment_station', 'customer_tablet'] as $index => $type) {
            $token = 'station-denied-'.$index;
            $this->device($token, $type);
            $this->app['auth']->forgetGuards();

            $response = $this->withToken($token)
                ->postJson('/api/v1/device/sync/push', ['events' => [$this->openEvent()]]);
            if ($type === 'customer_tablet') {
                // LAUNCH-P6 (tester call 1) — a tablet's token cannot reach sync at all.
                $response->assertForbidden()->assertJsonPath('errors.0.code', 'device_not_allowed_for_tablet');

                continue;
            }
            $response->assertOk();

            $this->assertSame('failed', $response->json('data.results.0.status'), $type);
            $this->assertStringContainsString(
                'device type cannot open shifts',
                (string) $response->json('data.results.0.result.error'),
                $type,
            );
        }

        $this->assertSame(0, Shift::query()->count());
    }

    public function test_fixed_pos_and_handheld_devices_can_still_open_shifts(): void
    {
        foreach (['fixed_pos', 'handheld'] as $index => $type) {
            $token = 'station-allowed-'.$index;
            $this->device($token, $type);
            $this->app['auth']->forgetGuards();

            $response = $this->withToken($token)
                ->postJson('/api/v1/device/sync/push', ['events' => [$this->openEvent()]])
                ->assertOk();

            $this->assertSame('processed', $response->json('data.results.0.status'), $type);
            $this->assertSame('open', $response->json('data.results.0.result.status'), $type);
        }

        $this->assertSame(2, Shift::query()->count());
    }
}
