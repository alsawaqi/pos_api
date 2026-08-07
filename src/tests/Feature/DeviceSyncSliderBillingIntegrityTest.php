<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SyncEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Billing integrity of `slider.display` — the impressions an advertiser is
 * INVOICED from.
 *
 * Two defects, found by the 2026-08-06 tenancy re-audit:
 *
 *  1. played_at came straight off the device, so one terminal could spread
 *     fabricated plays across any number of dates (screen-days = COUNT of
 *     DISTINCT device@date) and backdate them into an unbilled period. The
 *     (device_id, client_event_id) replay guard is no defence — fresh uuids
 *     satisfy it.
 *  2. The "served to this device" gate copied only the TARGETING half of the
 *     device-config predicate and dropped status + validity window, so draft,
 *     paused and long-ended campaigns kept accruing billable plays. Because an
 *     untargeted slider plays everywhere, every such campaign on the platform
 *     was reachable from every device.
 */
class DeviceSyncSliderBillingIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private function device(string $token = 'mdev_bill'): Device
    {
        return Device::factory()->paired($token)->create([
            'company_id' => 100,
            'branch_id' => 10,
        ]);
    }

    /** @param array<string, mixed> $slider */
    private function seedSlider(array $slider = []): void
    {
        $t = ['created_at' => now(), 'updated_at' => now()];
        DB::table('pos_marketing_sliders')->insert([
            array_merge(
                ['id' => 5, 'uuid' => (string) Str::uuid(), 'name' => 'Loop A', 'status' => 'active'],
                $slider,
            ) + $t,
        ]);
        DB::table('pos_marketing_slider_items')->insert([
            ['id' => 51, 'slider_id' => 5, 'content_asset_id' => 900, 'advertiser_id' => 7, 'sort_order' => 1] + $t,
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

    // ---- 1. played_at is clamped ------------------------------------------

    public function test_a_backdated_play_cannot_reach_an_arbitrary_billing_period(): void
    {
        $this->device();
        $this->seedSlider();

        // A year back — straight into a long-closed billing period.
        $this->push('mdev_bill', [
            $this->displayEvent(['played_at' => now()->subDays(365)->toIso8601String()]),
        ])->assertOk();

        $playedAt = Carbon::parse(DB::table('pos_marketing_impressions')->value('played_at'));

        // Stamped at receipt, NOT anchored to some horizon date: materialising
        // a play on a date nobody reported would invent a billable screen-day.
        $this->assertLessThanOrEqual(2, $playedAt->diffInMinutes(now(), absolute: true));
    }

    public function test_a_future_play_is_pulled_back_to_now(): void
    {
        $this->device();
        $this->seedSlider();

        $this->push('mdev_bill', [
            $this->displayEvent(['played_at' => now()->addDays(30)->toIso8601String()]),
        ])->assertOk();

        $playedAt = Carbon::parse(DB::table('pos_marketing_impressions')->value('played_at'));

        $this->assertLessThanOrEqual(2, $playedAt->diffInMinutes(now(), absolute: true));
    }

    public function test_a_timezone_offset_cannot_smuggle_a_play_into_another_day(): void
    {
        $this->device();
        $this->seedSlider();

        // The instant is NOW, but expressed in a +14:00 zone. Carbon keeps the
        // declared offset and Eloquent writes the WALL CLOCK into a
        // timezone-less column — so without UTC normalisation this lands a day
        // ahead, defeating every check above it.
        $this->push('mdev_bill', [
            $this->displayEvent(['played_at' => now()->utc()->setTimezone('+14:00')->toIso8601String()]),
        ])->assertOk();

        $stored = Carbon::parse(DB::table('pos_marketing_impressions')->value('played_at'));

        $this->assertSame(
            now()->utc()->toDateString(),
            $stored->toDateString(),
            'a declared UTC offset shifted the billable screen-day',
        );
    }

    public function test_forged_date_spread_cannot_manufacture_screen_days(): void
    {
        $this->device();
        $this->seedSlider();

        // The actual attack: 40 events, each claiming a distinct day, each
        // with a fresh client_event_id so the replay guard never fires.
        $events = [];
        for ($i = 1; $i <= 40; $i++) {
            $events[] = $this->displayEvent(['played_at' => now()->subDays($i)->toIso8601String()]);
        }
        $this->push('mdev_bill', $events)->assertOk();

        $screenDays = DB::table('pos_marketing_impressions')
            ->selectRaw('COUNT(DISTINCT DATE(played_at)) AS d')
            ->value('d');

        // Every row lands on the real receipt day: 40 fabricated dates buy
        // exactly ONE screen-day, the one that genuinely elapsed.
        $this->assertDatabaseCount('pos_marketing_impressions', 40);
        $this->assertSame(1, (int) $screenDays, 'forged spread manufactured screen-days');
    }

    public function test_a_play_reported_within_tolerance_keeps_its_own_time(): void
    {
        $this->device();
        $this->seedSlider();

        // The device's own timestamp is more precise than receipt time and is
        // kept when it is close enough to be believable.
        $reported = now()->utc()->subMinutes(3)->startOfMinute();
        $this->push('mdev_bill', [
            $this->displayEvent(['played_at' => $reported->toIso8601String()]),
        ])->assertOk();

        $this->assertSame(
            $reported->format('Y-m-d H:i'),
            Carbon::parse(DB::table('pos_marketing_impressions')->value('played_at'))->format('Y-m-d H:i'),
        );
    }

    public function test_a_replay_cannot_move_the_billing_anchor(): void
    {
        $device = $this->device();
        $this->seedSlider();

        // Drive the handler twice over the SAME sync-event row. The push
        // endpoint dedups a repeat before dispatch, so only the failed-event
        // re-dispatch path re-enters the handler — that is what this covers.
        $event = SyncEvent::query()->create([
            'device_id' => $device->id,
            'company_id' => $device->company_id,
            'branch_id' => $device->branch_id,
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'slider.display',
            'client_timestamp' => now(),
            'payload_json' => [
                'slider_id' => 5,
                'slider_item_id' => 51,
                'content_asset_id' => 900,
                'advertiser_id' => 7,
                'duration_ms' => 8000,
                'played_at' => now()->toIso8601String(),
            ],
            'ack_status' => 'failed',
        ]);

        $handler = app(\App\Actions\Device\Sync\Handlers\SliderDisplayHandler::class);
        $handler->handle($event, $device);
        $first = DB::table('pos_marketing_impressions')->value('played_at');
        $firstDuration = DB::table('pos_marketing_impressions')->value('play_duration_ms');

        // Re-dispatch carrying a different play time, advertiser slide and
        // duration — none of which may move, or one play bills twice.
        $event->payload_json = [
            'slider_id' => 5,
            'slider_item_id' => 51,
            'content_asset_id' => 900,
            'advertiser_id' => 7,
            'duration_ms' => 999000,
            'played_at' => now()->addMinutes(90)->toIso8601String(),
        ];
        $handler->handle($event, $device);

        $this->assertDatabaseCount('pos_marketing_impressions', 1);
        $this->assertSame($first, DB::table('pos_marketing_impressions')->value('played_at'));
        $this->assertSame($firstDuration, DB::table('pos_marketing_impressions')->value('play_duration_ms'));
    }

    public function test_an_absurd_play_duration_is_refused_rather_than_wedging_sync(): void
    {
        $this->device();
        $this->seedSlider();

        // Past the signed-int ceiling this would pass validation and then die
        // at INSERT on Postgres — and a failed event is re-dispatched on every
        // later push, so it would block that device's sync permanently.
        $res = $this->push('mdev_bill', [
            $this->displayEvent(['duration_ms' => 2147483647]),
        ])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertDatabaseCount('pos_marketing_impressions', 0);
    }

    // ---- 2. status + validity window are enforced --------------------------

    public function test_a_draft_campaign_cannot_accrue_billable_plays(): void
    {
        $this->device();
        $this->seedSlider(['status' => 'draft']);

        $res = $this->push('mdev_bill', [$this->displayEvent()])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertDatabaseCount('pos_marketing_impressions', 0);
    }

    public function test_a_paused_campaign_cannot_accrue_billable_plays(): void
    {
        $this->device();
        $this->seedSlider(['status' => 'paused']);

        $this->push('mdev_bill', [$this->displayEvent()])->assertOk();

        $this->assertDatabaseCount('pos_marketing_impressions', 0);
    }

    public function test_an_ended_campaign_cannot_accrue_billable_plays(): void
    {
        $this->device();
        $this->seedSlider([
            'starts_at' => now()->subDays(60),
            'ends_at' => now()->subDays(30),
        ]);

        $this->push('mdev_bill', [$this->displayEvent()])->assertOk();

        $this->assertDatabaseCount('pos_marketing_impressions', 0);
    }

    public function test_a_play_reported_just_before_a_campaign_ends_still_counts(): void
    {
        $this->device();
        // Campaign runs until a minute from now; the play is reported a few
        // minutes late, as a slow link would.
        $this->seedSlider([
            'starts_at' => now()->subDays(10),
            'ends_at' => now()->addMinute(),
        ]);

        $this->push('mdev_bill', [
            $this->displayEvent(['played_at' => now()->utc()->subMinutes(3)->toIso8601String()]),
        ])->assertOk();

        $this->assertDatabaseCount('pos_marketing_impressions', 1);
    }

    public function test_one_device_cannot_post_an_implausible_number_of_plays_in_a_day(): void
    {
        $device = $this->device();
        $this->seedSlider();

        // The ceiling is configurable, so exercise it at a small value rather
        // than seeding 25,000 rows.
        config(['services.marketing.max_plays_per_device_per_day' => 3]);

        $this->push('mdev_bill', [
            $this->displayEvent(),
            $this->displayEvent(),
            $this->displayEvent(),
        ])->assertOk();
        $this->assertDatabaseCount('pos_marketing_impressions', 3);

        $res = $this->push('mdev_bill', [$this->displayEvent()])->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertStringContainsString('plausible daily play count', $res->json('data.results.0.result.error'));
        $this->assertDatabaseCount('pos_marketing_impressions', 3);
    }

    public function test_a_campaign_not_yet_started_cannot_accrue_plays(): void
    {
        $this->device();
        $this->seedSlider(['starts_at' => now()->addDays(5)]);

        $this->push('mdev_bill', [$this->displayEvent()])->assertOk();

        $this->assertDatabaseCount('pos_marketing_impressions', 0);
    }
}
