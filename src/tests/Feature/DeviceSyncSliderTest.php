<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 3 — advertising play-time telemetry (`slider.display` → the
 * pos_marketing_impressions table). One event per slide shown on the device's
 * customer screen, scoped to the device's company + branch, idempotent on
 * client_event_id.
 */
class DeviceSyncSliderTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $token = 'mdev_slider'): Device
    {
        return Device::factory()->paired($token)->create([
            'company_id' => 100,
            'branch_id' => 10,
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function displayEvent(array $payload = []): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'slider.display',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => array_merge([
                'slider_id' => 5,
                'slider_item_id' => 51,
                'content_asset_id' => 900,
                'advertiser_id' => 7,
                'duration_ms' => 8000,
                'played_at' => now()->toIso8601String(),
            ], $payload),
        ];
    }

    /** @param array<int, array<string, mixed>> $events */
    private function push(string $token, array $events): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }

    public function test_a_slider_display_records_an_impression_scoped_to_the_device(): void
    {
        $device = $this->device();

        $res = $this->push('mdev_slider', [$this->displayEvent()])->assertOk();
        $r = $res->json('data.results.0');

        $this->assertSame('processed', $r['status']);
        $this->assertNotNull($r['result']['impression_id']);

        $this->assertDatabaseHas('pos_marketing_impressions', [
            'device_id' => $device->id,
            'company_id' => 100,
            'branch_id' => 10,
            'slider_id' => 5,
            'slider_item_id' => 51,
            'content_asset_id' => 900,
            'advertiser_id' => 7,
            'play_duration_ms' => 8000,
        ]);
    }

    public function test_replaying_a_display_does_not_double_count(): void
    {
        $this->device();
        $event = $this->displayEvent();

        $this->push('mdev_slider', [$event])->assertOk();
        $res = $this->push('mdev_slider', [$event])->assertOk();

        $res->assertJsonPath('data.summary.duplicates', 1);
        $this->assertDatabaseCount('pos_marketing_impressions', 1);
    }

    public function test_a_display_with_a_null_advertiser_still_records(): void
    {
        $this->device();

        $this->push('mdev_slider', [$this->displayEvent(['advertiser_id' => null])])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed');

        $this->assertDatabaseHas('pos_marketing_impressions', [
            'slider_id' => 5,
            'advertiser_id' => null,
        ]);
    }

    public function test_an_invalid_display_payload_is_rejected(): void
    {
        $this->device();

        // Missing slider_item_id + zero duration → the handler rejects it.
        $res = $this->push('mdev_slider', [
            $this->displayEvent(['slider_item_id' => null, 'duration_ms' => 0]),
        ])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertDatabaseCount('pos_marketing_impressions', 0);
    }

    public function test_a_display_records_anonymous_audience_metrics_when_present(): void
    {
        $device = $this->device();

        $this->push('mdev_slider', [$this->displayEvent([
            'viewers_peak' => 4,
            'viewers_avg' => 2,
            'viewers_distinct' => 6,
            'attention_ms' => 5200,
        ])])->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        $this->assertDatabaseHas('pos_marketing_impressions', [
            'device_id' => $device->id,
            'slider_id' => 5,
            'viewers_peak' => 4,
            'viewers_avg' => 2,
            'viewers_distinct' => 6,
            'attention_ms' => 5200,
        ]);
    }

    public function test_a_display_without_audience_metrics_stores_nulls(): void
    {
        $this->device();

        $this->push('mdev_slider', [$this->displayEvent()])->assertOk();

        $this->assertDatabaseHas('pos_marketing_impressions', [
            'slider_id' => 5,
            'viewers_peak' => null,
            'viewers_distinct' => null,
            'attention_ms' => null,
        ]);
    }
}
