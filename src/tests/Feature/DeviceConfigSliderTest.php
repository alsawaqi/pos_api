<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 3 — the marketing `sliders` slice of the device config bundle.
 *
 * Sliders are PLATFORM advertising, NOT company-scoped: a slider reaches a
 * device by the pos_marketing_slider_targets table (this device / this branch /
 * everywhere), gated by status=active + the validity window. The device's own
 * company (100 / branch 10) is irrelevant to slider matching — these tests use
 * a foreign advertiser's content on a foreign-owned slider on purpose.
 */
class DeviceConfigSliderTest extends TestCase
{
    use RefreshDatabase;

    private string $old;

    protected function setUp(): void
    {
        parent::setUp();
        $this->old = now()->subDay()->toDateTimeString();
        // Deterministic, device-reachable media base for URL assertions.
        config()->set('services.marketing.public_url', 'https://cdn.test');
    }

    private function pairedDevice(): Device
    {
        return Device::factory()->paired('mdev_slider')->create(['company_id' => 100, 'branch_id' => 10]);
    }

    /** @param array<string, mixed> $attrs */
    private function asset(int $id, string $type, string $status, array $attrs = []): void
    {
        DB::table('content_assets')->insert([
            'id' => $id,
            'advertiser_id' => 7,
            'type' => $type,
            'title' => "asset {$id}",
            'status' => $status,
            'path' => "marketing/ad{$id}.".($type === 'video' ? 'mp4' : 'jpg'),
            'thumbnail_path' => "marketing/thumb{$id}.jpg",
            'duration_seconds' => $type === 'video' ? 30 : null,
            'created_at' => $this->old,
            'updated_at' => $this->old,
        ] + $attrs);
    }

    private function slider(int $id, string $status, array $overrides = []): void
    {
        // array_merge (not +) so $overrides win on duplicate keys like ends_at.
        DB::table('pos_marketing_sliders')->insert(array_merge([
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'name' => "slider {$id}",
            'loop_interval_seconds' => 6,
            'status' => $status,
            'starts_at' => null,
            'ends_at' => null,
            'created_at' => $this->old,
            'updated_at' => $this->old,
        ], $overrides));
    }

    private function item(int $sliderId, int $assetId, int $sort, ?int $duration = null): void
    {
        DB::table('pos_marketing_slider_items')->insert([
            'slider_id' => $sliderId,
            'content_asset_id' => $assetId,
            'advertiser_id' => 7,
            'sort_order' => $sort,
            'duration_seconds' => $duration,
            'created_at' => $this->old,
            'updated_at' => $this->old,
        ]);
    }

    private function target(int $sliderId, ?int $branchId, ?int $deviceId): void
    {
        DB::table('pos_marketing_slider_targets')->insert([
            'slider_id' => $sliderId,
            'branch_id' => $branchId,
            'device_id' => $deviceId,
            'created_at' => $this->old,
            'updated_at' => $this->old,
        ]);
    }

    public function test_active_slider_targeting_the_branch_rides_in_the_config_with_ordered_media(): void
    {
        $device = $this->pairedDevice();
        $this->asset(1, 'image', 'approved');
        $this->asset(2, 'video', 'approved');
        $this->slider(1, 'active');
        // Out of insertion order on purpose — the slice must sort by sort_order.
        $this->item(1, 2, 1, 12);
        $this->item(1, 1, 0, 8);
        $this->target(1, 10, $device->id);

        $res = $this->withToken('mdev_slider')->getJson('/api/v1/device/config')->assertOk();

        $sliders = $res->json('data.sliders');
        $this->assertCount(1, $sliders);
        $this->assertSame(1, $sliders[0]['id']);
        $this->assertSame(6, $sliders[0]['loop_interval_seconds']);

        $items = $sliders[0]['items'];
        $this->assertCount(2, $items);
        // sort_order 0 first.
        $this->assertSame(1, $items[0]['content_asset_id']);
        $this->assertSame('image', $items[0]['type']);
        $this->assertSame(8, $items[0]['duration_seconds']);
        $this->assertSame('https://cdn.test/storage/marketing/ad1.jpg', $items[0]['url']);
        // The video, capped at its builder duration (12), keeps its type.
        $this->assertSame('video', $items[1]['type']);
        $this->assertSame(12, $items[1]['duration_seconds']);
        $this->assertSame('https://cdn.test/storage/marketing/ad2.mp4', $items[1]['url']);
    }

    public function test_item_duration_falls_back_to_the_loop_interval(): void
    {
        $device = $this->pairedDevice();
        $this->asset(1, 'image', 'approved');
        $this->slider(1, 'active', ['loop_interval_seconds' => 9]);
        $this->item(1, 1, 0, null);
        $this->target(1, 10, $device->id);

        $res = $this->withToken('mdev_slider')->getJson('/api/v1/device/config')->assertOk();

        $this->assertSame(9, $res->json('data.sliders.0.items.0.duration_seconds'));
    }

    public function test_a_no_target_slider_plays_everywhere(): void
    {
        $this->pairedDevice();
        $this->asset(1, 'image', 'approved');
        $this->slider(1, 'active');
        $this->item(1, 1, 0);
        // No target rows at all.

        $res = $this->withToken('mdev_slider')->getJson('/api/v1/device/config')->assertOk();

        $this->assertCount(1, $res->json('data.sliders'));
    }

    public function test_paused_expired_and_foreign_device_sliders_are_excluded(): void
    {
        $device = $this->pairedDevice();
        $this->asset(1, 'image', 'approved');

        // Paused — excluded.
        $this->slider(1, 'paused');
        $this->item(1, 1, 0);
        $this->target(1, 10, $device->id);

        // Active but its window already closed — excluded.
        $this->slider(2, 'active', ['ends_at' => now()->subHour()->toDateTimeString()]);
        $this->item(2, 1, 0);
        $this->target(2, 10, $device->id);

        // Active but bound to a DIFFERENT device in the same branch — excluded.
        $this->slider(3, 'active');
        $this->item(3, 1, 0);
        $this->target(3, 10, 999_999);

        $res = $this->withToken('mdev_slider')->getJson('/api/v1/device/config')->assertOk();

        $this->assertCount(0, $res->json('data.sliders'));
    }

    public function test_unapproved_items_are_dropped_from_the_loop(): void
    {
        $device = $this->pairedDevice();
        $this->asset(1, 'image', 'approved');
        $this->asset(2, 'image', 'pending'); // not approved → dropped
        $this->slider(1, 'active');
        $this->item(1, 1, 0);
        $this->item(1, 2, 1);
        $this->target(1, 10, $device->id);

        $res = $this->withToken('mdev_slider')->getJson('/api/v1/device/config')->assertOk();

        $items = $res->json('data.sliders.0.items');
        $this->assertCount(1, $items);
        $this->assertSame(1, $items[0]['content_asset_id']);
    }
}
