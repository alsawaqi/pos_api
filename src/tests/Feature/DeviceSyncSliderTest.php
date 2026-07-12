<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

    /**
     * Phase 4 — the loop the device (company 100 / branch 10) is served: a
     * slider (id 5) with NO targets plays everywhere, carrying an advertiser
     * slide (item 51 → asset 900 / advertiser 7) and an unsold slide (item 52 →
     * asset 901 / no advertiser). The handler now resolves the slide under its
     * slider and re-derives asset/advertiser from these rows.
     */
    private function seedServedSlider(): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_marketing_sliders')->insert([
            ['id' => 5, 'uuid' => (string) Str::uuid(), 'name' => 'Loop A', 'status' => 'active'] + $t,
        ]);
        DB::table('pos_marketing_slider_items')->insert([
            ['id' => 51, 'slider_id' => 5, 'content_asset_id' => 900, 'advertiser_id' => 7, 'sort_order' => 1] + $t,
            ['id' => 52, 'slider_id' => 5, 'content_asset_id' => 901, 'advertiser_id' => null, 'sort_order' => 2] + $t,
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
        $this->seedServedSlider();

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
        $this->seedServedSlider();
        $event = $this->displayEvent();

        $this->push('mdev_slider', [$event])->assertOk();
        $res = $this->push('mdev_slider', [$event])->assertOk();

        $res->assertJsonPath('data.summary.duplicates', 1);
        $this->assertDatabaseCount('pos_marketing_impressions', 1);
    }

    public function test_a_display_of_an_unsold_slide_records_a_null_advertiser(): void
    {
        $this->device();
        $this->seedServedSlider();

        // Item 52 has no advertiser — the re-derived advertiser_id is null even
        // if a device sent one, so an unsold slide's play carries no advertiser.
        $this->push('mdev_slider', [$this->displayEvent([
            'slider_item_id' => 52,
            'content_asset_id' => 901,
            'advertiser_id' => 999,
        ])])
            ->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed');

        $this->assertDatabaseHas('pos_marketing_impressions', [
            'slider_id' => 5,
            'slider_item_id' => 52,
            'content_asset_id' => 901,
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
        $this->seedServedSlider();

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
        $this->seedServedSlider();

        $this->push('mdev_slider', [$this->displayEvent()])->assertOk();

        $this->assertDatabaseHas('pos_marketing_impressions', [
            'slider_id' => 5,
            'viewers_peak' => null,
            'viewers_distinct' => null,
            'attention_ms' => null,
        ]);
    }

    public function test_a_display_of_an_unknown_slide_is_rejected(): void
    {
        $this->device();
        $this->seedServedSlider();

        // slider_item_id 999 does not exist under slider 5 → fabricated play.
        $res = $this->push('mdev_slider', [$this->displayEvent(['slider_item_id' => 999])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('unknown slide', $res->json('data.results.0.result.error'));
        $this->assertDatabaseCount('pos_marketing_impressions', 0);
    }

    public function test_a_slide_whose_slider_is_not_served_to_this_device_is_rejected(): void
    {
        $this->device(); // company 100 / branch 10
        $t = ['created_at' => now(), 'updated_at' => now()];

        // A real slider whose item exists, but it is TARGETED at another branch
        // (11) only — this device was never told to play it, so a claimed play
        // is fabricated billing.
        DB::table('pos_marketing_sliders')->insert([
            ['id' => 6, 'uuid' => (string) Str::uuid(), 'name' => 'Loop B', 'status' => 'active'] + $t,
        ]);
        DB::table('pos_marketing_slider_items')->insert([
            ['id' => 61, 'slider_id' => 6, 'content_asset_id' => 902, 'advertiser_id' => 8, 'sort_order' => 1] + $t,
        ]);
        DB::table('pos_marketing_slider_targets')->insert([
            ['slider_id' => 6, 'branch_id' => 11, 'device_id' => null] + $t,
        ]);

        $res = $this->push('mdev_slider', [$this->displayEvent([
            'slider_id' => 6,
            'slider_item_id' => 61,
            'content_asset_id' => 902,
            'advertiser_id' => 8,
        ])])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('not served to this device', $res->json('data.results.0.result.error'));
        $this->assertDatabaseCount('pos_marketing_impressions', 0);
    }

    public function test_content_asset_and_advertiser_are_re_derived_from_the_slide_not_the_payload(): void
    {
        $device = $this->device();
        $this->seedServedSlider();

        // A hostile device spoofs a different advertiser + asset for slide 51.
        // The handler ignores both and re-derives from the row (asset 900,
        // advertiser 7) so the impression cannot be misattributed.
        $this->push('mdev_slider', [$this->displayEvent([
            'slider_item_id' => 51,
            'content_asset_id' => 123456,
            'advertiser_id' => 999,
        ])])->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        $this->assertDatabaseHas('pos_marketing_impressions', [
            'device_id' => $device->id,
            'slider_id' => 5,
            'slider_item_id' => 51,
            'content_asset_id' => 900,
            'advertiser_id' => 7,
        ]);
        $this->assertDatabaseMissing('pos_marketing_impressions', ['advertiser_id' => 999]);
        $this->assertDatabaseMissing('pos_marketing_impressions', ['content_asset_id' => 123456]);
    }
}
