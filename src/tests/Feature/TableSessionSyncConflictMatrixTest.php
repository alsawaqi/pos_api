<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\TableSessionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class TableSessionSyncConflictMatrixTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-05 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seatingBranch();
        $this->productId = (int) $this->seatingProduct()->id;
        DB::table('pos_branch_product')->insert([
            'branch_id' => 10, 'product_id' => $this->productId, 'is_available' => true, 'stock_qty' => null,
        ]);
    }

    #[DataProvider('offlineArrivalOrders')]
    public function test_case_1_first_server_arrival_wins_in_every_offline_wall_time_order(string $firstTime, string $secondTime): void
    {
        $firstDevice = $this->seatingDevice();
        $secondDevice = $this->seatingDevice('handheld');
        $table = $this->seatingTable();
        $first = $this->payload($table, true) + ['opened_at' => $firstTime];
        $second = $this->payload($table, true) + ['opened_at' => $secondTime];
        $winnerAck = $this->push($firstDevice, 'open', $first, 'opened', true);
        $this->push($firstDevice, 'round', $this->roundPayload($first), 'appended', true);
        $winner = TableSession::query()->where('uuid', $winnerAck['table_session_uuid'])->sole();
        $bill = $winner->order()->sole();
        $childrenBefore = OrderItem::query()->where('order_id', $bill->id)->orderBy('id')->get()->toArray();
        $aliasAck = $this->push($secondDevice, 'open', $second, 'merged', true);
        $alias = TableSession::query()->where('uuid', $aliasAck['table_session_uuid'])->sole();
        $this->assertSame((int) $winner->id, (int) $alias->merged_into_id);
        $this->assertSame($winner->uuid, $aliasAck['winner_table_session_uuid']);
        $this->assertSame('merged', $alias->status);
        $this->assertSame('merged', $alias->close_reason);
        $this->assertNull($alias->order_id);
        $this->assertNull($alias->temp_reference);
        $this->assertSame($secondTime, $alias->opened_at->toIso8601String());
        $this->assertTrue($alias->expires_at->equalTo(Carbon::parse($secondTime)->addHours(6)));
        $roundAck = $this->push($secondDevice, 'round', $this->roundPayload($second), 'merged', true);
        $round = QrOrderRound::query()->findOrFail($roundAck['round_id']);
        $this->assertSame((int) $winner->id, (int) $round->table_session_id);
        $this->assertSame((int) $alias->id, (int) $round->origin_table_session_id);
        $this->assertSame((int) $bill->id, (int) $round->order_id);
        $this->assertNull($round->qr_session_id);
        $this->assertTrue((bool) $round->needs_review);
        $this->assertSame('pending_confirmation', $round->status);
        $this->assertNull($round->accepted_seq);
        $this->assertNotNull($round->confirm_payload);
        $this->assertSame('1.000', $bill->fresh()->grand_total);
        $this->assertSame($childrenBefore, OrderItem::query()->where('order_id', $bill->id)->orderBy('id')->get()->toArray());
        $this->assertSame(1, TableSession::query()->whereIn('status', TableSession::LIVE_STATUSES)->count());
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseCount('pos_order_sequences', 0);
        $this->assertSame(2, (int) DB::table('pos_temp_reference_sequences')->sole()->next_number);
        $this->assertSame(['opened', 'round_appended', 'merged', 'needs_review', 'round_pending', 'needs_review'], $this->eventTypes());
        fwrite(STDOUT, "\nT4_MATRIX_CASE_1=".json_encode(['first' => $winnerAck, 'second' => $aliasAck, 'round' => $roundAck], JSON_THROW_ON_ERROR)."\n");
    }

    public static function offlineArrivalOrders(): array
    {
        return [
            'older wall time first' => ['2026-09-05T10:00:00+00:00', '2026-09-05T11:00:00+00:00'],
            'newer wall time first' => ['2026-09-05T11:00:00+00:00', '2026-09-05T10:00:00+00:00'],
            'equal wall times' => ['2026-09-05T10:00:00+00:00', '2026-09-05T10:00:00+00:00'],
        ];
    }

    public function test_case_2_offline_open_on_free_table_allocates_once_without_creating_a_bill(): void
    {
        $device = $this->seatingDevice('handheld');
        $table = $this->seatingTable();
        $payload = $this->payload($table, true) + [
            'opened_at' => '2026-09-05T09:00:00+00:00', 'order_uuid' => (string) Str::uuid(),
        ];
        $ack = $this->push($device, 'open', $payload, 'opened', true);
        $seating = TableSession::query()->sole();
        $this->assertSame('staff_handheld', $seating->origin);
        $this->assertSame((int) $device->id, (int) $seating->opened_by_device_id);
        $this->assertSame($payload['seating_key'], $seating->client_request_id);
        $this->assertSame('2026-09-05T09:00:00+00:00', $seating->opened_at->toIso8601String());
        $this->assertSame('2026-09-05T15:00:00+00:00', $seating->expires_at->toIso8601String());
        $this->assertSame('T-0905-001', $seating->temp_reference);
        $this->assertSame($payload['order_uuid'], $ack['order_uuid']);
        $this->assertNull($seating->order_id);
        $this->assertDatabaseCount('pos_orders', 0);
        $this->push($device, 'open', $payload, 'replayed', false);
        $this->assertSame(2, (int) DB::table('pos_temp_reference_sequences')->sole()->next_number);
        $this->assertSame(['opened'], $this->eventTypes());
        fwrite(STDOUT, "\nT4_MATRIX_CASE_2=".json_encode($ack, JSON_THROW_ON_ERROR)."\n");
    }

    #[DataProvider('qrCollisionModes')]
    public function test_cases_3_7_9_qr_party_wins_and_connected_staff_attach_without_replacing_its_lines(bool $offline, string $openOutcome, string $roundOutcome): void
    {
        [$table, $seating, $order, $originalRound] = $this->qrParty();
        $device = $this->seatingDevice();
        $before = $originalRound->getRawOriginal();
        $originalItems = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get()->toArray();
        $payload = $this->payload($table, $offline) + ['opened_at' => now()->subHour()->toIso8601String()];
        $opened = $this->push($device, 'open', $payload, $openOutcome, true);
        $this->assertSame($seating->uuid, $opened['winner_table_session_uuid']);
        $round = $this->push($device, 'round', $this->roundPayload($payload), $roundOutcome, true);
        $this->assertSame($order->uuid, $round['order_uuid']);
        $this->assertSame($seating->temp_reference, $round['temp_reference']);
        $this->assertSame($before, $originalRound->fresh()->getRawOriginal());
        $this->assertSame($originalItems, OrderItem::query()->whereIn('id', array_column($originalItems, 'id'))->orderBy('id')->get()->toArray());
        $this->assertSame($offline ? '1.000' : '2.000', $order->fresh()->grand_total);
        $this->assertSame(Order::SOURCE_QR_WEB, $order->fresh()->source);
        $this->assertNull(QrOrderRound::query()->findOrFail($round['round_id'])->qr_session_id);
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertSame(2, (int) DB::table('pos_temp_reference_sequences')->sole()->next_number);
        $this->assertSame($offline
            ? ['opened', 'round_appended', 'customer_order_arrived', 'merged', 'needs_review', 'round_pending', 'needs_review']
            : ['opened', 'round_appended', 'customer_order_arrived', 'attached', 'round_appended'], $this->eventTypes());
        fwrite(STDOUT, "\nT4_MATRIX_CASE_".($offline ? '3_7' : '9').'='.json_encode(['open' => $opened, 'round' => $round], JSON_THROW_ON_ERROR)."\n");
    }

    public static function qrCollisionModes(): array
    {
        return [[true, 'merged', 'merged'], [false, 'attached', 'appended']];
    }

    public function test_case_4_close_before_open_is_a_non_journaled_tombstone_and_blocks_late_money(): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $payload = $this->payload($table, true);
        $closed = $this->push($device, 'close', $payload + ['closed_at' => now()->toIso8601String(), 'reason' => 'staff_close'], 'tombstoned', false);
        $tombstone = TableSession::query()->sole();
        $before = $tombstone->getRawOriginal();
        $this->assertSame('closed', $tombstone->status);
        $this->assertSame('staff_close', $tombstone->close_reason);
        $this->assertTrue($tombstone->opened_at->equalTo($tombstone->expires_at));
        $this->assertTrue($tombstone->opened_at->equalTo($tombstone->closed_at));
        $this->assertNull($tombstone->temp_reference);
        $opened = $this->push($device, 'open', $payload + ['opened_at' => now()->subHour()->toIso8601String()], 'already_closed', false);
        $round = $this->push($device, 'round', $this->roundPayload($payload), 'bill_terminal', false);
        $this->assertTrue($round['needs_review']);
        $this->assertSame($before, $tombstone->fresh()->getRawOriginal());
        $this->assertDatabaseCount('pos_table_session_events', 0);
        $this->assertDatabaseCount('pos_orders', 0);
        $this->assertDatabaseCount('pos_temp_reference_sequences', 0);
        fwrite(STDOUT, "\nT4_MATRIX_CASE_4=".json_encode(['close' => $closed, 'open' => $opened, 'round' => $round], JSON_THROW_ON_ERROR)."\n");
    }

    public function test_cases_5_10_old_alias_and_terminal_generation_cannot_touch_the_next_party(): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $destination = $this->seatingTable('Destination');
        $primary = $this->payload($table);
        $alias = $this->payload($table);
        $this->push($device, 'open', $primary + ['opened_at' => now()->toIso8601String()], 'opened', true);
        $this->push($device, 'open', $alias + ['opened_at' => now()->toIso8601String()], 'attached', true);
        $this->push($device, 'close', $primary + ['closed_at' => now()->toIso8601String(), 'reason' => 'staff_close'], 'closed', true);
        $next = $this->payload($table);
        $this->push($device, 'open', $next + ['opened_at' => now()->toIso8601String()], 'opened', true);
        $before = TableSession::query()->orderBy('id')->get()->toArray();
        $journalBefore = TableSessionEvent::query()->orderBy('id')->get()->toArray();
        $results = [];
        $results[] = $this->push($device, 'round', $this->roundPayload($alias), 'bill_terminal', false);
        $results[] = $this->push($device, 'close', $alias + ['closed_at' => now()->toIso8601String(), 'reason' => 'staff_close'], 'stale_generation', false);
        $results[] = $this->push($device, 'move', $alias + ['from_table_id' => $table->id, 'to_table_id' => $destination->id, 'moved_at' => now()->toIso8601String()], 'stale_generation', false);
        $results[] = $this->push($device, 'join', $alias + ['join_table_ids' => [$destination->id], 'joined_at' => now()->toIso8601String()], 'stale_generation', false);
        $results[] = $this->push($device, 'close', $primary + ['closed_at' => now()->toIso8601String(), 'reason' => 'staff_close'], 'already_closed', false);
        $this->assertSame($before, TableSession::query()->orderBy('id')->get()->toArray());
        $this->assertSame($journalBefore, TableSessionEvent::query()->orderBy('id')->get()->toArray());
        $this->assertDatabaseCount('pos_orders', 0);
        $this->assertSame(3, (int) DB::table('pos_temp_reference_sequences')->sole()->next_number);
        fwrite(STDOUT, "\nT4_MATRIX_CASE_5_10=".json_encode($results, JSON_THROW_ON_ERROR)."\n");
    }

    public function test_case_6_occupied_move_has_processed_ack_and_preserves_both_parties(): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $target = $this->seatingTable('Occupied');
        $payload = $this->payload($table);
        $this->push($device, 'open', $payload + ['opened_at' => now()->toIso8601String()], 'opened', true);
        $this->push($device, 'round', $this->roundPayload($payload), 'appended', true);
        $this->push($device, 'open', $this->payload($target) + ['opened_at' => now()->toIso8601String()], 'opened', true);
        $before = TableSession::query()->orderBy('id')->get()->toArray();
        $billBefore = Order::query()->sole()->getRawOriginal();
        $ack = $this->push($device, 'move', $payload + [
            'from_table_id' => $table->id, 'to_table_id' => $target->id, 'moved_at' => now()->toIso8601String(),
        ], 'target_occupied', false);
        $this->assertSame($before, TableSession::query()->orderBy('id')->get()->toArray());
        $this->assertSame($billBefore, Order::query()->sole()->getRawOriginal());
        $this->assertSame(['opened', 'round_appended', 'opened'], $this->eventTypes());
        fwrite(STDOUT, "\nT4_MATRIX_CASE_6=".json_encode($ack, JSON_THROW_ON_ERROR)."\n");
    }

    #[DataProvider('backstopCases')]
    public function test_case_11_server_backstop_and_future_clock_do_not_choose_a_different_winner(bool $offline, int $timestampDelta, string $outcome): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $winner = $this->push($device, 'open', $this->payload($table) + ['opened_at' => now()->toIso8601String()], 'opened', true);
        $ack = $this->push($device, 'open', $this->payload($table, $offline) + ['opened_at' => now()->subHours(2)->toIso8601String()], $outcome, true, now()->addSeconds($timestampDelta)->toIso8601String());
        $this->assertSame($winner['table_session_uuid'], $ack['winner_table_session_uuid']);
        $event = TableSessionEvent::query()->findOrFail($ack['event_id']);
        $this->assertSame($outcome === 'merged', $event->payload['queued_offline']);
        if ($timestampDelta > 300) {
            $this->assertSame($timestampDelta, $event->payload['clock_skew_seconds']);
        } else {
            $this->assertArrayNotHasKey('clock_skew_seconds', $event->payload);
        }
        fwrite(STDOUT, "\nT4_MATRIX_CASE_11=".json_encode(['flag' => $offline, 'clock_delta' => $timestampDelta, 'ack' => $ack], JSON_THROW_ON_ERROR)."\n");
    }

    public function test_case_20_catalogue_hold_on_merged_alias_preserves_both_review_reasons_and_the_bill(): void
    {
        [$table, $seating, $order] = $this->qrParty();
        $device = $this->seatingDevice('handheld');
        $payload = $this->payload($table, true) + ['opened_at' => now()->subHour()->toIso8601String()];
        $opened = $this->push($device, 'open', $payload, 'merged', true);
        $beforeBill = $order->getRawOriginal();
        $beforeItems = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get()->toArray();
        DB::table('pos_products')->where('id', $this->productId)->update(['status' => 'inactive']);
        $roundPayload = $this->roundPayload($payload);
        $ack = $this->push($device, 'round', $roundPayload, 'merged', true);
        $this->assertSame(['merged', 'catalogue'], $ack['review_reasons']);
        $this->assertSame([[
            'line_index' => 0, 'product_id' => $this->productId, 'addon_id' => null, 'reason' => 'inactive',
        ]], $ack['held_lines']);
        $this->assertSame(0, $ack['total_baisas']);
        $this->assertNull($ack['accepted_seq']);
        $this->assertFalse($ack['print_pending']);
        $this->assertSame($order->uuid, $ack['order_uuid']);
        $round = QrOrderRound::query()->findOrFail($ack['round_id']);
        $this->assertSame((int) $seating->id, (int) $round->table_session_id);
        $this->assertSame($opened['table_session_uuid'], TableSession::findOrFail($round->origin_table_session_id)->uuid);
        $this->assertSame('pending_confirmation', $round->status);
        $this->assertTrue((bool) $round->needs_review);
        $this->assertNull($round->confirm_payload);
        $this->assertSame('inactive', $round->priced_lines[0]['held_reason']);
        $this->assertSame($roundPayload['lines'][0]['notes'], $round->priced_lines[0]['notes']);
        $this->assertNull($round->priced_lines[0]['unit_price_baisas']);
        $this->assertSame($beforeBill, $order->fresh()->getRawOriginal());
        $this->assertSame($beforeItems, OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get()->toArray());
        $this->assertSame(1, Order::query()->count());
        $event = TableSessionEvent::findOrFail($ack['event_id']);
        $this->assertSame(['merged', 'catalogue'], $event->payload['review_reasons']);
        $this->assertSame([$this->productId], $event->payload['held_product_ids']);
        $this->assertSame(1, $event->payload['held_line_count']);
        $this->assertArrayNotHasKey('lines', $event->payload);
        $this->assertArrayNotHasKey('priced_lines', $event->payload);
        fwrite(STDOUT, "\nT4_MATRIX_CASE_20=".json_encode($ack, JSON_THROW_ON_ERROR)."\n");
    }

    public static function backstopCases(): array
    {
        return [
            'exactly five minutes online' => [false, -300, 'attached'],
            'five minutes plus one offline' => [false, -301, 'merged'],
            'ten minute backstop' => [false, -600, 'merged'],
            'future clock remains online' => [false, 600, 'attached'],
            'offline flag stays true despite future clock' => [true, 600, 'merged'],
        ];
    }

    /** @return array<string, mixed> */
    private function payload(Table $table, bool $offline = false): array
    {
        return ['seating_key' => (string) Str::uuid(), 'table_id' => (int) $table->id, 'queued_offline' => $offline];
    }

    /** @return array<string, mixed> */
    private function roundPayload(array $payload): array
    {
        return $payload + [
            'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(),
            'lines' => [['product_id' => $this->productId, 'qty' => 1, 'addon_ids' => [], 'notes' => null, 'unit_price' => '999.000']],
        ];
    }

    /** @return array<string, mixed> */
    private function push(Device $device, string $operation, array $payload, string $outcome, bool $changed, ?string $clientAt = null): array
    {
        app('auth')->forgetGuards();
        $response = $this->withToken((string) $device->plainTextToken)->postJson('/api/v1/device/sync/push', [
            'events' => [[
                'client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.'.$operation,
                'client_timestamp' => $clientAt ?? now()->toIso8601String(), 'payload' => $payload,
            ]],
        ])->assertOk();
        $this->assertSame('processed', $response->json('data.results.0.status'), $response->getContent());
        $result = $response->json('data.results.0.result');
        $this->assertIsArray($result, $response->getContent());
        $this->assertSame($outcome, $result['outcome'], $response->getContent());
        foreach (['table_session_uuid', 'winner_table_session_uuid', 'order_uuid', 'temp_reference', 'needs_review', 'event_id', 'seating_key', 'table_id'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame($payload['seating_key'], $result['seating_key']);
        $this->assertSame((int) $payload['table_id'], $result['table_id']);
        $this->assertIsBool($result['needs_review']);
        if ($changed) {
            $this->assertIsInt($result['event_id']);
            $this->assertGreaterThan(0, $result['event_id']);
            $this->assertDatabaseHas('pos_table_session_events', [
                'id' => $result['event_id'], 'company_id' => $device->company_id, 'branch_id' => $device->branch_id,
            ]);
            if (isset($result['event_ids'])) {
                $this->assertSame($result['event_id'], $result['event_ids'][0]);
                $this->assertSame($result['event_ids'], TableSessionEvent::query()->whereIn('id', $result['event_ids'])->orderBy('id')->pluck('id')->all());
            }
        } else {
            $this->assertNull($result['event_id']);
        }

        return $result;
    }

    private function eventTypes(): array
    {
        return TableSessionEvent::query()->orderBy('id')->pluck('event_type')->all();
    }

    /** @return array{Table, TableSession, Order, QrOrderRound} */
    private function qrParty(): array
    {
        $station = $this->seatingDevice('payment_station');
        $table = $this->seatingTable('QR party');
        app('auth')->forgetGuards();
        $response = $this->withToken((string) $station->plainTextToken)->postJson('/api/v1/device/qr/open-table', ['table_id' => $table->id])->assertCreated();
        $session = QrSession::query()->where('uuid', $response->json('data.session_uuid'))->sole();
        $secret = 't4-qr-party-secret';
        $this->postJson('/api/v1/public/qr/table-bind', ['table_token' => $table->qr_token, 'client_secret' => $secret])->assertOk();
        $this->withHeaders(['X-QR-Session' => $session->uuid, 'X-QR-Client-Secret' => $secret])
            ->postJson('/api/v1/public/qr/table-round', [
                'client_request_id' => (string) Str::uuid(), 'phone' => '92009901',
                'lines' => [['product_id' => $this->productId, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
            ])->assertCreated();

        return [$table, $session->fresh()->tableSession()->sole(), Order::query()->sole(), QrOrderRound::query()->sole()];
    }
}
