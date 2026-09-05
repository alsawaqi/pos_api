<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Device\Sync\SyncEventDispatcher;
use App\Actions\Qr\QrCatalogueException;
use App\Models\Device;
use App\Models\Product;
use App\Models\QrOrderRound;
use App\Models\SyncEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * Phase 8.2 — device offline-sync ingestion (POST /api/v1/device/sync/push).
 *
 * The inbound counterpart to 8.1's config bundle: a paired terminal pushes a
 * batch of offline events into the pos_sync_events ledger and gets a per-event
 * ACK. The contract under test is EXACTLY-ONCE settlement keyed on
 * client_event_id — the offline-replay case (50 events, 4 hours late, pushed
 * twice) must land 50 rows, not 100.
 */
class DeviceSyncPushTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    private function assignedDevice(string $token): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => 100, 'branch_id' => 10]);
    }

    /**
     * A well-formed event. Defaults to a 4-hours-stale client_timestamp — the
     * normal case for a backlog drained after the terminal comes back online.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function event(string $type = 'sync.noop', array $overrides = []): array
    {
        return array_merge([
            'client_event_id' => (string) Str::uuid(),
            'event_type' => $type,
            'client_timestamp' => now()->subHours(4)->toIso8601String(),
            'payload' => ['note' => 'offline'],
        ], $overrides);
    }

    public function test_push_requires_a_device_token(): void
    {
        $this->postJson('/api/v1/device/sync/push', ['events' => [$this->event()]])
            ->assertStatus(401);
    }

    public function test_an_unassigned_device_is_rejected(): void
    {
        Device::factory()->paired('mdev_sync_unassigned')->create(['company_id' => null, 'branch_id' => null]);

        $this->withToken('mdev_sync_unassigned')
            ->postJson('/api/v1/device/sync/push', ['events' => [$this->event()]])
            ->assertStatus(409);

        $this->assertDatabaseCount('pos_sync_events', 0);
    }

    public function test_push_ingests_new_events_and_acks_each(): void
    {
        $device = $this->assignedDevice('mdev_sync');
        // Use the UNHANDLED event type (sync.noop — deferred) so this
        // stays a pure ingestion/dedup test; order.*/shift.*/expense.log/
        // restock.request all get processed by their own handlers now.
        $order = $this->event('sync.noop', ['payload' => ['order' => ['total_baisas' => 1500]]]);
        $shift = $this->event('sync.noop');

        $res = $this->withToken('mdev_sync')
            ->postJson('/api/v1/device/sync/push', ['events' => [$order, $shift]])
            ->assertOk();

        $res->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.accepted', 2)
            ->assertJsonPath('data.summary.duplicates', 0)
            ->assertJsonPath('meta.device_id', $device->id)
            ->assertJsonPath('errors', []);

        $this->assertCount(2, $res->json('data.results'));
        $first = $res->json('data.results.0');
        $this->assertSame($order['client_event_id'], $first['client_event_id']);
        $this->assertFalse($first['duplicate']);
        $this->assertSame('received', $first['status']);
        $this->assertNotNull($first['event_id']);
        $this->assertNull($first['processed_at']);
        $this->assertNull($first['result']);

        // Durably ledgered with the right attribution + payload.
        $this->assertDatabaseCount('pos_sync_events', 2);
        $this->assertDatabaseHas('pos_sync_events', [
            'client_event_id' => $order['client_event_id'],
            'device_id' => $device->id,
            'event_type' => 'sync.noop',
            'ack_status' => 'received',
        ]);

        $row = SyncEvent::query()->where('client_event_id', $order['client_event_id'])->firstOrFail();
        $this->assertSame(['order' => ['total_baisas' => 1500]], $row->payload_json);
        $this->assertNotNull($row->server_received_at);
        // The 4-hours-late device timestamp is preserved, distinct from arrival.
        $this->assertTrue($row->client_timestamp->lessThan(now()->subHours(3)));
    }

    public function test_push_validates_the_batch_envelope(): void
    {
        $this->assignedDevice('mdev_v');

        $this->withToken('mdev_v')
            ->postJson('/api/v1/device/sync/push', ['events' => []])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['events']);

        $this->withToken('mdev_v')
            ->postJson('/api/v1/device/sync/push', ['events' => [
                ['client_event_id' => 'not-a-uuid', 'event_type' => 'order.bogus', 'client_timestamp' => now()->toIso8601String()],
            ]])
            ->assertStatus(422)
            ->assertJsonValidationErrors([
                'events.0.client_event_id',
                'events.0.event_type',
                'events.0.payload',
            ]);

        $this->assertDatabaseCount('pos_sync_events', 0);
    }

    public function test_push_caps_the_batch_at_fifty(): void
    {
        $this->assignedDevice('mdev_cap');

        $events = [];
        for ($i = 0; $i < 51; $i++) {
            $events[] = $this->event();
        }

        $this->withToken('mdev_cap')
            ->postJson('/api/v1/device/sync/push', ['events' => $events])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['events']);

        $this->assertDatabaseCount('pos_sync_events', 0);
    }

    public function test_replaying_a_full_offline_batch_settles_exactly_once(): void
    {
        $this->assignedDevice('mdev_replay');

        // 50 events queued 4 hours ago while the terminal was offline.
        $events = [];
        for ($i = 0; $i < 50; $i++) {
            $events[] = $this->event('sync.noop', [
                'client_timestamp' => now()->subHours(4)->toIso8601String(),
                'payload' => ['seq' => $i],
            ]);
        }

        // First drain — every event is new.
        $first = $this->withToken('mdev_replay')
            ->postJson('/api/v1/device/sync/push', ['events' => $events])
            ->assertOk();
        $first->assertJsonPath('data.summary.accepted', 50)
            ->assertJsonPath('data.summary.duplicates', 0);
        $this->assertDatabaseCount('pos_sync_events', 50);
        $firstIds = collect($first->json('data.results'))->pluck('event_id', 'client_event_id');

        // The terminal didn't get the ACK (flaky link) and re-pushes the
        // IDENTICAL batch. Nothing new lands; every event re-returns its ACK.
        $replay = $this->withToken('mdev_replay')
            ->postJson('/api/v1/device/sync/push', ['events' => $events])
            ->assertOk();
        $replay->assertJsonPath('data.summary.accepted', 0)
            ->assertJsonPath('data.summary.duplicates', 50);
        $this->assertDatabaseCount('pos_sync_events', 50); // settle once, not 100

        foreach ($replay->json('data.results') as $r) {
            $this->assertTrue($r['duplicate']);
            $this->assertSame('received', $r['status']);
            // Re-returns the SAME ledger row as the first push.
            $this->assertSame($firstIds[$r['client_event_id']], $r['event_id']);
        }
    }

    public function test_partial_replay_ingests_only_the_new_events(): void
    {
        $this->assignedDevice('mdev_partial');
        $a = $this->event();
        $b = $this->event();
        $c = $this->event();

        $this->withToken('mdev_partial')
            ->postJson('/api/v1/device/sync/push', ['events' => [$a, $b]])
            ->assertOk();
        $this->assertDatabaseCount('pos_sync_events', 2);

        // Overlapping batch: b already settled, c is new.
        $res = $this->withToken('mdev_partial')
            ->postJson('/api/v1/device/sync/push', ['events' => [$b, $c]])
            ->assertOk();
        $res->assertJsonPath('data.summary.accepted', 1)
            ->assertJsonPath('data.summary.duplicates', 1);
        $this->assertDatabaseCount('pos_sync_events', 3);

        $byId = collect($res->json('data.results'))->keyBy('client_event_id');
        $this->assertTrue($byId[$b['client_event_id']]['duplicate']);
        $this->assertFalse($byId[$c['client_event_id']]['duplicate']);
    }

    public function test_table_events_extend_the_exact_existing_sync_taxonomy_and_all_have_handlers(): void
    {
        $this->assertSame([
            'order.create', 'order.hold', 'order.transfer', 'order.pay', 'order.deliver', 'order.void',
            'donation.record', 'expense.log', 'restock.request', 'stock.count', 'product.waste',
            'shift.open', 'shift.close', 'slider.display',
            'table.session.open', 'table.session.round', 'table.session.move', 'table.session.join', 'table.session.close',
            'sync.noop',
        ], SyncEvent::EVENT_TYPES);
        $dispatcher = app(SyncEventDispatcher::class);
        foreach (['open', 'round', 'move', 'join', 'close'] as $operation) {
            $this->assertTrue($dispatcher->handles('table.session.'.$operation));
        }
    }

    public function test_a_catalogue_exception_during_table_round_pricing_is_a_processed_hold_not_a_failed_ack(): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $product = $this->seatingProduct();
        $loads = 0;
        // Classification sees an available product. Pricing's second hydration
        // sees it inactive and throws the unchanged loader's real exception.
        Event::listen('eloquent.retrieved: '.Product::class, function (Product $row) use ($product, &$loads): void {
            if ((int) $row->id === (int) $product->id && ++$loads === 2) {
                $row->status = 'inactive';
            }
        });
        try {
            $response = $this->withToken($device->device_token)->postJson('/api/v1/device/sync/push', [
                'events' => [$this->event('table.session.round', ['payload' => [
                    'seating_key' => (string) Str::uuid(), 'table_id' => (int) $table->id,
                    'queued_offline' => true, 'client_request_id' => 'catalogue-race-held',
                    'submitted_at' => now()->subMinutes(10)->toIso8601String(),
                    'lines' => [['product_id' => (int) $product->id, 'qty' => 2, 'addon_ids' => [], 'notes' => 'Keep the request']],
                ]])],
            ])->assertOk();
        } finally {
            Event::forget('eloquent.retrieved: '.Product::class);
        }
        $this->assertGreaterThanOrEqual(2, $loads);
        $response->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.outcome', 'held')
            ->assertJsonPath('data.results.0.result.review_reasons', ['catalogue'])
            ->assertJsonPath('data.results.0.result.held_lines.0.reason', QrCatalogueException::PRODUCT_UNAVAILABLE)
            ->assertJsonPath('data.results.0.result.total_baisas', 0);
        $round = QrOrderRound::query()->sole();
        $this->assertNull($round->confirm_payload);
        $this->assertNull($round->accepted_seq);
        $this->assertSame('pending_confirmation', $round->status);
        $this->assertSame('Keep the request', $round->priced_lines[0]['notes']);
        $this->assertSame(2, $round->priced_lines[0]['qty']);
        $this->assertSame('0.000', $round->order()->sole()->grand_total);
        $this->assertDatabaseCount('pos_order_items', 0);
        $this->assertDatabaseHas('pos_table_session_events', [
            'id' => $response->json('data.results.0.result.event_id'), 'event_type' => 'round_pending',
        ]);
        fwrite(STDOUT, "\nT4_CATALOGUE_PRICING_RACE_JSON=".$response->getContent()."\n");
    }

    public function test_a_repeated_id_within_one_batch_settles_once(): void
    {
        $this->assignedDevice('mdev_inbatch');
        $event = $this->event();

        // Same client_event_id twice in a single push.
        $res = $this->withToken('mdev_inbatch')
            ->postJson('/api/v1/device/sync/push', ['events' => [$event, $event]])
            ->assertOk();

        $res->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.accepted', 1)
            ->assertJsonPath('data.summary.duplicates', 1);
        $this->assertDatabaseCount('pos_sync_events', 1);

        $results = $res->json('data.results');
        $this->assertFalse($results[0]['duplicate']);
        $this->assertTrue($results[1]['duplicate']);
        $this->assertSame($results[0]['event_id'], $results[1]['event_id']);
    }
}
