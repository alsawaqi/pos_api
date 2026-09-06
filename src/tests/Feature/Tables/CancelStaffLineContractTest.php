<?php

declare(strict_types=1);

namespace Tests\Feature\Tables;

use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
use App\Models\TableSession;
use App\Models\TableSessionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class CancelStaffLineContractTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-06 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_sync_request_accepts_cancel_type_and_matching_is_addon_set_and_normalised_notes(): void
    {
        [$device, $seating, $order, $round, $item, $payload] = $this->fixture();
        $lines = $round->priced_lines;
        $lines[0]['addons'] = [['add_on_id' => 7], ['add_on_id' => 3]];
        $lines[0]['notes'] = "  NO \t Sugar  ";
        $round->update(['priced_lines' => $lines]);
        $before = $this->snapshot($order, $round);
        $this->push($device, array_replace($payload, ['addon_ids' => [3], 'notes' => 'no sugar']), 'nothing_to_cancel');
        $this->assertSame($before, $this->snapshot($order, $round));
        $this->push($device, array_replace($payload, ['addon_ids' => [3, 7], 'notes' => 'extra sugar']), 'nothing_to_cancel');
        $this->assertSame($before, $this->snapshot($order, $round));
        $ack = $this->push($device, array_replace($payload, ['addon_ids' => [3, 7], 'notes' => 'no sugar']), 'cancelled');
        $this->assertSame(1, $ack['cancelled_qty']);
        $this->assertSame(1000, $ack['grand_total_baisas']);
        $this->assertSame('1.000', $item->fresh()->qty);
        $this->assertSame([3, 7], TableSessionEvent::query()->sole()->payload['addon_ids']);
        $this->assertSame('no sugar', TableSessionEvent::query()->sole()->payload['notes_normalised']);
    }

    public function test_online_twin_and_new_sync_envelope_replay_share_the_same_journal_identity(): void
    {
        [$device, $seating, $order, $round, $item, $payload] = $this->fixture();
        $response = $this->withToken($device->device_token)
            ->postJson('/api/v1/device/tables/'.$seating->uuid.'/cancel-line', $payload)
            ->assertOk()->assertJsonPath('data.outcome', 'cancelled');
        $ack = $response->json('data');
        $this->assertSame(1, $ack['cancelled_qty']);
        $this->assertSame(1000, $ack['grand_total_baisas']);
        $snapshot = $this->snapshot($order, $round);
        $replayed = $this->push($device, $payload, 'replayed');
        $this->assertSame($ack['rounds'], $replayed['rounds']);
        $this->assertSame($ack['cancelled_qty'], $replayed['cancelled_qty']);
        $this->assertSame($ack['grand_total_baisas'], $replayed['grand_total_baisas']);
        $this->assertSame($snapshot, $this->snapshot($order, $round));
        $this->assertSame('1.000', $item->fresh()->qty);
    }

    public function test_joined_member_and_merged_alias_cancel_only_the_primary_bill_and_replay_across_family(): void
    {
        [$device, $primary, $order, $round, $item, $payload] = $this->fixture();
        $member = $this->seatingRow($this->seatingTable('Joined'), [
            'opened_by_device_id' => $device->id, 'merged_into_id' => $primary->id, 'order_id' => $order->id,
        ]);
        $alias = $this->seatingRow($this->seatingTable('Alias history'), [
            'status' => TableSession::STATUS_MERGED, 'merged_into_id' => $primary->id,
            'close_reason' => TableSession::CLOSE_MERGED, 'closed_at' => now(),
        ]);
        $ack = $this->push($device, array_replace($payload, [
            'seating_key' => $member->client_request_id, 'table_id' => (int) $member->table_id,
        ]), 'cancelled');
        $this->assertSame($primary->uuid, $ack['winner_table_session_uuid']);
        $this->assertSame($order->uuid, $ack['order_uuid']);
        $this->assertSame((int) $primary->id, (int) TableSessionEvent::query()->sole()->table_session_id);
        $snapshot = $this->snapshot($order, $round);
        $replay = $this->push($device, array_replace($payload, [
            'seating_key' => $alias->client_request_id, 'table_id' => (int) $alias->table_id,
        ]), 'replayed');
        $this->assertSame(1, $replay['cancelled_qty']);
        $this->assertSame($snapshot, $this->snapshot($order, $round));
        $this->assertDatabaseCount('pos_orders', 1);
    }

    public function test_pending_merged_round_and_held_lines_are_not_cancelled_and_unlinked_lines_are_counted(): void
    {
        [$device, $seating, $order, $round, $item, $payload] = $this->fixture();
        $unlinked = $round->priced_lines[0];
        unset($unlinked['order_item_id']);
        $held = $unlinked + ['held_reason' => 'inactive'];
        $round->update(['priced_lines' => [$unlinked, $held]]);
        $pending = $this->seatingRound($seating, $order, [
            'round_no' => 2, 'status' => QrOrderRound::STATUS_PENDING_CONFIRMATION,
            'needs_review' => true, 'origin_table_session_id' => $seating->id,
            'priced_lines' => [$unlinked + ['order_item_id' => (int) $item->id]],
        ]);
        $before = $this->snapshot($order, $round);
        $pendingBefore = $pending->fresh()->getRawOriginal();
        $ack = $this->push($device, $payload, 'nothing_to_cancel');
        $this->assertSame(1, $ack['unlinked_line_count']);
        $this->assertSame(0, $ack['cancelled_qty']);
        $this->assertSame($before, $this->snapshot($order, $round));
        $this->assertSame($pendingBefore, $pending->fresh()->getRawOriginal());
        $this->assertSame('2.000', $item->fresh()->qty);
    }

    public function test_paid_bill_is_terminal_without_writes_and_unknown_seating_is_processed(): void
    {
        [$device, $seating, $order, $round, $item, $payload] = $this->fixture();
        $order->update(['status' => Order::STATUS_PAID]);
        $before = $this->snapshot($order, $round);
        $ack = $this->push($device, $payload, 'bill_terminal');
        $this->assertSame(0, $ack['cancelled_qty']);
        $this->assertSame($before, $this->snapshot($order, $round));
        $this->push($device, array_replace($payload, ['seating_key' => (string) Str::uuid()]), 'unknown_seating');
        $this->assertSame($before, $this->snapshot($order, $round));
        $this->assertSame('2.000', $item->fresh()->qty);
    }

    public function test_same_round_cancels_highest_line_index_first_and_preserves_addon_rows(): void
    {
        [$device, $seating, $order, $round, $item, $payload] = $this->fixture();
        $newItem = $item->replicate();
        $newItem->save();
        $newLine = array_replace($round->priced_lines[0], ['line_index' => 5, 'order_item_id' => (int) $newItem->id]);
        $round->update(['priced_lines' => [$newLine, $round->priced_lines[0]], 'subtotal_baisas' => 4000, 'total_baisas' => 4000]);
        $order->update(['subtotal' => '4.000', 'grand_total' => '4.000']);
        $addonsBefore = $item->addons()->get()->toArray();
        $ack = $this->push($device, $payload, 'cancelled');
        $this->assertSame(5, $ack['rounds'][0]['line_index']);
        $this->assertSame('2.000', $item->fresh()->qty);
        $this->assertSame('1.000', $newItem->fresh()->qty);
        $this->assertSame($addonsBefore, $item->addons()->get()->toArray());
    }

    private function fixture(): array
    {
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
        $order = $this->seatingOrder($seating, ['subtotal' => '2.000', 'grand_total' => '2.000']);
        $product = $this->seatingProduct();
        $item = OrderItem::query()->create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name_snapshot' => 'Frozen',
            'qty' => 2, 'unit_price_snapshot' => '1.000', 'line_total' => '2.000',
            'line_discount' => '0.000', 'status' => OrderItem::STATUS_OPEN,
        ]);
        $round = $this->seatingRound($seating, $order, [
            'priced_lines' => [[
                'line_index' => 0, 'product_id' => (int) $product->id, 'qty' => 2, 'unit_price_baisas' => 1000,
                'line_discount_baisas' => 0, 'line_total_baisas' => 2000, 'addons' => [], 'notes' => null,
                'order_item_id' => (int) $item->id,
            ]], 'subtotal_baisas' => 2000, 'total_baisas' => 2000,
        ]);

        return [$device, $seating, $order, $round, $item, [
            'seating_key' => $seating->client_request_id, 'table_id' => (int) $seating->table_id,
            'queued_offline' => false, 'client_request_id' => (string) Str::uuid(),
            'product_id' => (int) $product->id, 'addon_ids' => [], 'notes' => null,
            'qty' => 1, 'prepared' => false, 'cancelled_at' => now()->toIso8601String(),
        ]];
    }

    private function snapshot(Order $order, QrOrderRound $round): array
    {
        return [$order->fresh()->getRawOriginal(), $round->fresh()->getRawOriginal(),
            OrderItem::query()->orderBy('id')->get()->toArray(), TableSessionEvent::query()->orderBy('id')->get()->toArray()];
    }

    private function push(Device $device, array $payload, string $outcome): array
    {
        $response = $this->withToken($device->device_token)->postJson('/api/v1/device/sync/push', [
            'events' => [[
                'client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.cancel_line',
                'client_timestamp' => now()->toIso8601String(), 'payload' => $payload,
            ]],
        ])->assertOk()->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.outcome', $outcome);
        $ack = $response->json('data.results.0.result');
        fwrite(STDOUT, "\nT6_CANCEL_".strtoupper($outcome).'='.json_encode($ack, JSON_THROW_ON_ERROR)."\n");

        return $ack;
    }
}
