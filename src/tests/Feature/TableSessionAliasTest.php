<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
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

final class TableSessionAliasTest extends TestCase
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

    public function test_ledger_replay_retains_original_ack_while_online_and_new_event_replays_have_no_journal_id(): void
    {
        $device = $this->seatingDevice();
        $payload = $this->payload($this->seatingTable()) + ['opened_at' => now()->toIso8601String()];
        $eventId = (string) Str::uuid();
        $original = $this->push($device, 'open', $payload, 'opened', true, $eventId);
        $replayed = $this->push($device, 'open', $payload, 'opened', true, $eventId);
        $this->assertSame($original, $replayed);
        $online = $this->withToken($device->device_token)->postJson('/api/v1/device/tables/open', $payload)->assertOk();
        $this->assertSame('replayed', $online->json('data.outcome'));
        $this->assertNull($online->json('data.event_id'));
        $this->push($device, 'open', $payload, 'replayed', false);
        $this->assertDatabaseCount('pos_table_sessions', 1);
        $this->assertDatabaseCount('pos_table_session_events', 1);
        $this->assertDatabaseCount('pos_orders', 0);
        $this->assertSame(2, (int) DB::table('pos_temp_reference_sequences')->sole()->next_number);
    }

    public function test_multiple_attachment_keys_are_durable_aliases_of_the_real_winner_and_allocate_nothing(): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $primaryPayload = $this->payload($table);
        $first = $this->push($device, 'open', $primaryPayload + ['opened_at' => now()->toIso8601String()], 'opened', true);
        $primary = TableSession::query()->where('uuid', $first['table_session_uuid'])->sole();
        foreach (range(1, 3) as $index) {
            $payload = $this->payload($table) + ['opened_at' => now()->subMinutes($index)->toIso8601String()];
            $ack = $this->push($device, 'open', $payload, 'attached', true);
            $alias = TableSession::query()->where('uuid', $ack['table_session_uuid'])->sole();
            $before = $alias->getRawOriginal();
            $this->assertSame((int) $primary->id, (int) $alias->merged_into_id);
            $this->assertSame('merged', $alias->status);
            $this->assertSame('attached', $alias->close_reason);
            $this->assertNull($alias->order_id);
            $this->assertNull($alias->temp_reference);
            $this->assertSame($payload['seating_key'], $alias->client_request_id);
            $this->push($device, 'open', $payload, 'replayed', false);
            $this->assertSame($before, $alias->fresh()->getRawOriginal());
        }
        $this->assertSame(1, TableSession::query()->whereIn('status', TableSession::LIVE_STATUSES)->count());
        $this->assertSame(['opened', 'attached', 'attached', 'attached'], TableSessionEvent::query()->orderBy('id')->pluck('event_type')->all());
        $this->assertSame(2, (int) DB::table('pos_temp_reference_sequences')->sole()->next_number);
    }

    public function test_round_before_open_on_a_free_table_creates_once_and_server_prices_its_bill(): void
    {
        $device = $this->seatingDevice('handheld');
        $payload = $this->roundPayload($this->payload($this->seatingTable()));
        $payload['submitted_at'] = '2026-09-05T11:12:00+00:00';
        $ack = $this->push($device, 'round', $payload, 'seating_created', true);
        $seating = TableSession::query()->sole();
        $order = Order::query()->sole();
        $round = QrOrderRound::query()->sole();
        $this->assertSame('handheld', $order->source);
        $this->assertNull($order->qr_session_id);
        $this->assertNull($order->client_request_id);
        $this->assertNull($order->client_event_id);
        $this->assertNull($order->receipt_number);
        $this->assertSame('T-0905-001', $seating->temp_reference);
        $this->assertSame($seating->temp_reference, $order->temp_reference);
        $this->assertSame('2026-09-05T11:12:00+00:00', $seating->opened_at->toIso8601String());
        $this->assertSame('2026-09-05T17:12:00+00:00', $seating->expires_at->toIso8601String());
        $this->assertSame('1.000', $order->grand_total);
        $this->assertSame(1000, (int) $round->total_baisas);
        $this->assertSame(1000, $ack['total_baisas']);
        $this->assertNull($round->qr_session_id);
        $this->assertSame((int) $seating->id, (int) $round->table_session_id);
        $this->assertSame('accepted', $round->status);
        $this->assertFalse((bool) $round->needs_review);
        $this->assertGreaterThan(0, $round->accepted_seq);
        $before = [$seating->getRawOriginal(), $order->getRawOriginal(), $round->getRawOriginal()];
        $this->push($device, 'round', $payload, 'replayed', false);
        $this->push($device, 'open', $payload + ['opened_at' => now()->toIso8601String()], 'replayed', false);
        $this->assertSame($before, [$seating->fresh()->getRawOriginal(), $order->fresh()->getRawOriginal(), $round->fresh()->getRawOriginal()]);
        $this->assertSame(['opened', 'round_appended'], TableSessionEvent::query()->orderBy('id')->pluck('event_type')->all());
        $this->assertSame(2, (int) DB::table('pos_temp_reference_sequences')->sole()->next_number);
        $this->assertDatabaseCount('pos_order_sequences', 0);
    }

    #[DataProvider('collisionKinds')]
    public function test_round_before_its_open_on_an_occupied_table_uses_the_normal_alias_resolver(bool $offline, string $outcome, string $reason): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $primaryPayload = $this->payload($table);
        $this->push($device, 'round', $this->roundPayload($primaryPayload), 'seating_created', true);
        $primary = TableSession::query()->sole();
        $order = Order::query()->sole();
        $next = $this->roundPayload($this->payload($table, $offline));
        $ack = $this->push($device, 'round', $next, $outcome, true);
        $alias = TableSession::query()->where('client_request_id', $next['seating_key'])->sole();
        $round = QrOrderRound::query()->findOrFail($ack['round_id']);
        $this->assertSame($reason, $alias->close_reason);
        $this->assertSame((int) $primary->id, (int) $alias->merged_into_id);
        $this->assertSame((int) $primary->id, (int) $round->table_session_id);
        $this->assertSame((int) $order->id, (int) $round->order_id);
        $this->assertSame($offline, (bool) $round->needs_review);
        $this->assertSame($offline ? 'pending_confirmation' : 'accepted', $round->status);
        $this->assertSame($offline ? '1.000' : '2.000', $order->fresh()->grand_total);
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseCount('pos_table_sessions', 2);
        $this->assertSame(2, (int) DB::table('pos_temp_reference_sequences')->sole()->next_number);
        $before = TableSession::query()->orderBy('id')->get()->toArray();
        $this->push($device, 'open', $next + ['opened_at' => now()->subHour()->toIso8601String()], 'replayed', false);
        $this->assertSame($before, TableSession::query()->orderBy('id')->get()->toArray());
    }

    public static function collisionKinds(): array
    {
        return [[false, 'appended', 'attached'], [true, 'merged', 'merged']];
    }

    #[DataProvider('frozenBills')]
    public function test_rounds_against_held_or_awaiting_bills_never_mutate_money_or_create_a_missing_alias(string $status): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $primary = $this->payload($table);
        $attached = $this->payload($table);
        $merged = $this->payload($table, true);
        $this->push($device, 'round', $this->roundPayload($primary), 'seating_created', true);
        $this->push($device, 'open', $attached + ['opened_at' => now()->toIso8601String()], 'attached', true);
        $this->push($device, 'open', $merged + ['opened_at' => now()->toIso8601String()], 'merged', true);
        Order::query()->sole()->update(['status' => $status]);
        TableSession::query()->where('client_request_id', $primary['seating_key'])->update(['status' => 'billing', 'billing_at' => now()]);
        $before = $this->snapshot();
        foreach ([$primary, $attached, $merged, $this->payload($table)] as $payload) {
            $ack = $this->push($device, 'round', $this->roundPayload($payload), 'bill_unpaid', false);
            $this->assertSame('T-0905-001', $ack['temp_reference']);
            $this->assertSame($before, $this->snapshot());
        }
        $this->push($device, 'close', $primary + ['closed_at' => now()->toIso8601String(), 'reason' => 'staff_close'], 'bill_unpaid', false);
        $this->assertSame($before, $this->snapshot());
    }

    public static function frozenBills(): array
    {
        return [['held'], ['awaiting_payment']];
    }

    public function test_branch_local_foreign_key_is_absent_for_move_join_close_and_open_without_foreign_mutation(): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $target = $this->seatingTable('Target');
        $key = (string) Str::uuid();
        $foreign = $this->seatingRow($this->seatingTable('Foreign party', 20), ['client_request_id' => $key])->refresh();
        $before = $foreign->getRawOriginal();
        $payload = ['seating_key' => $key, 'table_id' => (int) $table->id, 'queued_offline' => false];
        $this->push($device, 'move', $payload + ['from_table_id' => $table->id, 'to_table_id' => $target->id, 'moved_at' => now()->toIso8601String()], 'unknown_seating', false);
        $this->push($device, 'join', $payload + ['join_table_ids' => [$target->id], 'joined_at' => now()->toIso8601String()], 'unknown_seating', false);
        $this->assertDatabaseCount('pos_table_sessions', 1);
        $this->push($device, 'close', $payload + ['closed_at' => now()->toIso8601String(), 'reason' => 'staff_close'], 'tombstoned', false);
        $this->push($device, 'open', $payload + ['opened_at' => now()->toIso8601String()], 'already_closed', false);
        $this->assertSame($before, $foreign->fresh()->getRawOriginal());
        $this->assertSame(1, TableSession::query()->where('branch_id', 10)->count());
        $this->assertDatabaseCount('pos_table_session_events', 0);
        $this->assertDatabaseCount('pos_temp_reference_sequences', 0);
        $otherLocal = $this->payload($target);
        $otherForeign = $this->seatingRow($this->seatingTable('Other foreign party', 20), ['client_request_id' => $otherLocal['seating_key']])->refresh();
        $otherBefore = $otherForeign->getRawOriginal();
        $this->push($device, 'open', $otherLocal + ['opened_at' => now()->toIso8601String()], 'opened', true);
        $this->assertSame($otherBefore, $otherForeign->fresh()->getRawOriginal());
    }

    public function test_first_round_through_joined_table_uses_only_primary_bill_reference_and_links_every_member(): void
    {
        $till = $this->seatingDevice();
        $handheld = $this->seatingDevice('handheld');
        $primaryTable = $this->seatingTable('Primary');
        $joinedTable = $this->seatingTable('Joined');
        $secondJoinedTable = $this->seatingTable('Joined 2');
        $payload = $this->payload($primaryTable) + [
            'opened_at' => now()->toIso8601String(), 'joined_table_ids' => [$joinedTable->id, $secondJoinedTable->id],
        ];
        $this->push($till, 'open', $payload, 'opened', true);
        $primary = TableSession::query()->where('client_request_id', $payload['seating_key'])->sole();
        $joined = TableSession::query()->where('table_id', $joinedTable->id)->sole();
        $this->assertSame($payload['seating_key'].'#'.$joinedTable->id, $joined->client_request_id);
        $this->assertNull($joined->temp_reference);
        $this->assertNull($joined->order_id);
        $attachedPayload = $this->payload($joinedTable);
        $this->push($handheld, 'open', $attachedPayload + ['opened_at' => now()->toIso8601String()], 'attached', true);
        $ack = $this->push($handheld, 'round', $this->roundPayload($attachedPayload), 'appended', true);
        $bill = Order::query()->sole();
        $round = QrOrderRound::query()->sole();
        $this->assertSame((int) $primary->id, (int) $bill->table_session_id);
        $this->assertSame((int) $primaryTable->id, (int) $bill->table_id);
        $this->assertSame((int) $primary->id, (int) $round->table_session_id);
        $this->assertSame('T-0905-001', $bill->temp_reference);
        $this->assertSame($bill->temp_reference, $ack['temp_reference']);
        $this->assertSame('handheld', $bill->source);
        $this->assertSame(3, TableSession::query()->where('order_id', $bill->id)->whereIn('status', TableSession::LIVE_STATUSES)->count());
        $this->assertSame([(int) $joinedTable->id, (int) $secondJoinedTable->id], DB::table('pos_order_tables')->where('order_id', $bill->id)->orderBy('table_id')->pluck('table_id')->map(fn ($id): int => (int) $id)->all());
        $this->assertSame(2, (int) DB::table('pos_temp_reference_sequences')->sole()->next_number);
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseCount('pos_order_sequences', 0);
    }

    public function test_join_partial_refusal_move_and_staff_close_preserve_the_shared_bill_and_alias(): void
    {
        $device = $this->seatingDevice();
        $tables = array_map(fn ($i): Table => $this->seatingTable('MOVE-'.$i), range(1, 5));
        $payload = $this->payload($tables[0]);
        $this->push($device, 'round', $this->roundPayload($payload), 'seating_created', true);
        $primary = TableSession::query()->sole();
        $order = Order::query()->sole();
        $this->push($device, 'open', $this->payload($tables[3]) + ['opened_at' => now()->toIso8601String()], 'opened', true);
        $joined = $this->push($device, 'join', $payload + ['join_table_ids' => [$tables[1]->id, $tables[2]->id, $tables[3]->id], 'joined_at' => now()->toIso8601String()], 'joined', true);
        $this->assertSame([(int) $tables[1]->id, (int) $tables[2]->id], $joined['joined']);
        $this->assertSame([(int) $tables[3]->id], $joined['refused']);
        $this->push($device, 'join', $payload + ['join_table_ids' => [$tables[1]->id, $tables[2]->id], 'joined_at' => now()->toIso8601String()], 'replayed', false);
        $aliasPayload = $this->payload($tables[0]);
        $this->push($device, 'open', $aliasPayload + ['opened_at' => now()->toIso8601String()], 'attached', true);
        $alias = TableSession::query()->where('client_request_id', $aliasPayload['seating_key'])->sole();
        $aliasBefore = $alias->getRawOriginal();
        $children = OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get()->toArray();
        $moved = $this->push($device, 'move', $aliasPayload + ['from_table_id' => $tables[0]->id, 'to_table_id' => $tables[4]->id, 'moved_at' => now()->toIso8601String()], 'moved', true);
        $this->assertCount(3, $moved['event_ids']);
        $this->assertSame((int) $tables[4]->id, (int) $primary->fresh()->table_id);
        $this->assertSame((int) $tables[4]->id, (int) $order->fresh()->table_id);
        $this->assertSame($children, OrderItem::query()->where('order_id', $order->id)->orderBy('id')->get()->toArray());
        $this->assertSame($aliasBefore, $alias->fresh()->getRawOriginal());
        $order->update(['status' => 'paid']);
        $closed = $this->push($device, 'close', $aliasPayload + ['closed_at' => now()->toIso8601String(), 'reason' => 'staff_close'], 'closed', true);
        $this->assertSame(3, $closed['closed_count']);
        $this->assertCount(3, $closed['event_ids']);
        $this->assertSame(3, TableSession::query()->where('order_id', $order->id)->where('status', 'closed')->where('close_reason', 'staff_close')->count());
        $this->assertSame($aliasBefore, $alias->fresh()->getRawOriginal());
        $this->assertSame(1, TableSession::query()->where('table_id', $tables[3]->id)->whereIn('status', TableSession::LIVE_STATUSES)->count());
    }

    public function test_online_uuid_routes_refuse_foreign_or_numeric_seating_identity_without_mutation(): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $target = $this->seatingTable('Target');
        $foreign = $this->seatingRow($this->seatingTable('Foreign', 20))->refresh();
        $before = $foreign->getRawOriginal();
        $base = $this->payload($table);
        foreach ([
            'round' => $this->roundPayload($base),
            'move' => $base + ['from_table_id' => $table->id, 'to_table_id' => $target->id, 'moved_at' => now()->toIso8601String()],
            'join' => $base + ['join_table_ids' => [$target->id], 'joined_at' => now()->toIso8601String()],
            'close' => $base + ['closed_at' => now()->toIso8601String(), 'reason' => 'staff_close'],
        ] as $operation => $payload) {
            foreach ([$foreign->uuid, (string) $foreign->id] as $identity) {
                app('auth')->forgetGuards();
                $this->withToken($device->device_token)->postJson('/api/v1/device/tables/'.$identity.'/'.$operation, $payload)
                    ->assertNotFound()->assertJsonPath('errors.0.code', 'table_session_not_found');
            }
        }
        $this->assertSame($before, $foreign->fresh()->getRawOriginal());
        $this->assertDatabaseCount('pos_table_session_events', 0);
        $this->assertDatabaseCount('pos_orders', 0);
    }

    private function payload(Table $table, bool $offline = false): array
    {
        return ['seating_key' => (string) Str::uuid(), 'table_id' => (int) $table->id, 'queued_offline' => $offline];
    }

    private function roundPayload(array $payload): array
    {
        return $payload + [
            'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(),
            'lines' => [['product_id' => $this->productId, 'qty' => 1, 'addon_ids' => [], 'notes' => null, 'unit_price' => '999.000']],
        ];
    }

    private function push(Device $device, string $operation, array $payload, string $outcome, bool $changed, ?string $eventId = null): array
    {
        app('auth')->forgetGuards();
        $response = $this->withToken($device->device_token)->postJson('/api/v1/device/sync/push', [
            'events' => [[
                'client_event_id' => $eventId ?? (string) Str::uuid(), 'event_type' => 'table.session.'.$operation,
                'client_timestamp' => now()->toIso8601String(), 'payload' => $payload,
            ]],
        ])->assertOk();
        $this->assertSame('processed', $response->json('data.results.0.status'), $response->getContent());
        $result = $response->json('data.results.0.result');
        $this->assertSame($outcome, $result['outcome'], $response->getContent());
        $this->assertSame($payload['seating_key'], $result['seating_key']);
        $this->assertSame((int) $payload['table_id'], $result['table_id']);
        if ($changed) {
            $this->assertIsInt($result['event_id']);
            $this->assertGreaterThan(0, $result['event_id']);
        } else {
            $this->assertNull($result['event_id']);
        }

        return $result;
    }

    private function snapshot(): array
    {
        return [
            TableSession::query()->orderBy('id')->get()->toArray(),
            Order::query()->orderBy('id')->get()->toArray(),
            QrOrderRound::query()->orderBy('id')->get()->toArray(),
            OrderItem::query()->orderBy('id')->get()->toArray(),
            TableSessionEvent::query()->orderBy('id')->get()->toArray(),
            DB::table('pos_temp_reference_sequences')->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all(),
        ];
    }
}
