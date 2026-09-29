<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\TableSession;
use App\Models\TableSessionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class TableSessionFeedTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-05 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_feed_initial_window_is_last_fifty_ascending_and_cursor_is_branch_local(): void
    {
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable());
        $other = $this->seatingRow($this->seatingTable('Foreign branch', 20));
        $ids = [];
        for ($i = 0; $i < 55; $i++) {
            $ids[] = $this->event($seating)->id;
            $this->event($other);
        }
        $this->withToken($device->plainTextToken)->getJson('/api/v1/device/tables/feed')
            ->assertOk()->assertJsonCount(50, 'data.events')
            ->assertJsonPath('data.events.0.id', $ids[5])
            ->assertJsonPath('data.events.49.id', $ids[54])
            ->assertJsonPath('meta.latest_id', $ids[54])->assertJsonPath('meta.has_more', false);
        $response = $this->getJson('/api/v1/device/tables/feed?after=0&limit=2')
            ->assertOk()->assertJsonCount(2, 'data.events')
            ->assertJsonPath('meta.latest_id', $ids[54])->assertJsonPath('meta.has_more', true);
        $this->assertSame(array_slice($ids, 0, 2), array_column($response->json('data.events'), 'id'));
        $this->getJson('/api/v1/device/tables/feed?after='.$ids[53])
            ->assertOk()->assertJsonCount(1, 'data.events')->assertJsonPath('data.events.0.id', $ids[54])
            ->assertJsonPath('meta.has_more', false);
        $this->getJson('/api/v1/device/tables/feed?after=999999')
            ->assertOk()->assertJsonPath('data.events', [])->assertJsonPath('meta.latest_id', $ids[54])
            ->assertJsonPath('meta.has_more', false);
    }

    public function test_empty_branch_cursor_is_zero_even_when_other_branch_has_events(): void
    {
        $device = $this->seatingDevice();
        $this->event($this->seatingRow($this->seatingTable('Foreign branch', 20)));
        $this->withToken($device->plainTextToken)->getJson('/api/v1/device/tables/feed')
            ->assertExactJson(['data' => ['events' => []], 'meta' => ['latest_id' => 0, 'has_more' => false], 'errors' => []]);
    }

    public function test_feed_rejects_bad_cursors_and_limits_but_includes_full_one_hundred_row_window(): void
    {
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable());
        for ($i = 0; $i < 101; $i++) {
            $this->event($seating);
        }
        $this->withToken($device->plainTextToken)->getJson('/api/v1/device/tables/feed?after=0&limit=100')
            ->assertOk()->assertJsonCount(100, 'data.events')->assertJsonPath('meta.has_more', true);
        foreach (['after=-1', 'after=abc', 'after=1.2', 'limit=0', 'limit=101', 'limit=abc'] as $query) {
            $this->getJson('/api/v1/device/tables/feed?'.$query)->assertStatus(422)
                ->assertJsonPath('errors.0.code', 'validation_failed');
        }
    }

    public function test_feed_keeps_event_time_identity_after_bill_link_changes_and_quotes_literal_json(): void
    {
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable(), ['uuid' => '11111111-1111-4111-8111-111111111111']);
        $event = $this->event($seating, ['device_id' => $device->id]);
        $this->seatingOrder($seating, ['uuid' => '22222222-2222-4222-8222-222222222222']);
        $expected = [
            'data' => ['events' => [[
                'id' => (int) $event->id, 'event_type' => 'opened', 'table_id' => (int) $seating->table_id,
                'table_session_uuid' => $seating->uuid, 'order_uuid' => null,
                'payload' => ['table_session_uuid' => $seating->uuid, 'order_uuid' => null],
                'device_id' => (int) $device->id, 'created_at' => '2026-09-05T12:00:00+00:00',
            ]]],
            'meta' => ['latest_id' => (int) $event->id, 'has_more' => false], 'errors' => [],
        ];
        $response = $this->withToken($device->plainTextToken)->getJson('/api/v1/device/tables/feed')->assertOk();
        $this->assertSame($expected, $response->json());
        fwrite(STDOUT, "\nT4_FEED_JSON=".$response->getContent()."\n");
    }

    public function test_feed_company_scope_does_not_leak_even_when_corrupt_journal_row_reuses_branch_id(): void
    {
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable());
        $this->event($seating, ['company_id' => 200]);
        $this->withToken($device->plainTextToken)->getJson('/api/v1/device/tables/feed')
            ->assertOk()->assertJsonPath('data.events', [])->assertJsonPath('meta.latest_id', 0);
    }

    private function event(TableSession $seating, array $attributes = []): TableSessionEvent
    {
        return TableSessionEvent::query()->create(array_replace([
            'company_id' => $seating->company_id, 'branch_id' => $seating->branch_id,
            'table_session_id' => $seating->id, 'table_id' => $seating->table_id,
            'event_type' => 'opened', 'payload' => ['table_session_uuid' => $seating->uuid, 'order_uuid' => null],
            'created_at' => now(),
        ], $attributes));
    }
}
