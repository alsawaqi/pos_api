<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Floor;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class TableBoardAndSearchTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-05 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public function test_board_includes_empty_tables_live_seatings_and_unpaid_orphans_but_no_alias_or_deleted_table(): void
    {
        $device = $this->seatingDevice();
        $empty = $this->seatingTable('Empty');
        $table = $this->seatingTable('Occupied');
        $seating = $this->seatingRow($table);
        $this->seatingOrder($seating);
        $this->seatingRow($table, [
            'status' => 'merged', 'merged_into_id' => $seating->id, 'temp_reference' => null,
            'closed_at' => now(), 'close_reason' => 'attached',
        ]);
        $orphanTable = $this->seatingTable('Orphan');
        $orphanSeating = $this->seatingRow($orphanTable, ['status' => 'expired', 'closed_at' => now()]);
        $orphan = $this->seatingOrder($orphanSeating, ['status' => Order::STATUS_HELD, 'table_session_id' => null]);
        $this->seatingTable('Deleted')->delete();
        $deletedFloorTable = $this->seatingTable('Deleted floor');
        Floor::findOrFail($deletedFloorTable->floor_id)->delete();
        $this->seatingTable('Foreign branch', 20);
        $this->seatingTable('Foreign company', 30, 200);
        $rows = $this->withToken($device->device_token)->getJson('/api/v1/device/tables/board')
            ->assertOk()->assertJsonCount(3, 'data.tables')->json('data.tables');
        $this->assertSame([$empty->id, $table->id, $orphanTable->id], array_column($rows, 'table_id'));
        $this->assertNull($rows[0]['seating']);
        $this->assertNull($rows[0]['bill']);
        $this->assertSame($seating->uuid, $rows[1]['seating']['uuid']);
        $this->assertNull($rows[2]['seating']);
        $this->assertSame($orphan->uuid, $rows[2]['bill']['order_uuid']);
    }

    public function test_joined_tables_share_primary_reference_bill_and_unresolved_review_count(): void
    {
        $device = $this->seatingDevice();
        $primary = $this->seatingRow($this->seatingTable('Primary'), ['temp_reference' => 'T-0905-007']);
        $order = $this->seatingOrder($primary, [
            'status' => Order::STATUS_AWAITING_PAYMENT, 'charge_claimed_at' => now(),
            'charge_deadline_at' => now()->addMinute(), 'grand_total' => '7.125',
        ]);
        $joined = $this->seatingRow($this->seatingTable('Joined'), [
            'merged_into_id' => $primary->id, 'temp_reference' => null, 'order_id' => $order->id,
        ]);
        $this->seatingRound($primary, $order, ['status' => QrOrderRound::STATUS_PENDING_CONFIRMATION, 'needs_review' => true]);
        $this->seatingRound($joined, $order, ['status' => QrOrderRound::STATUS_PENDING_CONFIRMATION, 'needs_review' => true]);
        $this->seatingRound($primary, $order, ['needs_review' => true]);
        $this->seatingRound($primary, $order, ['status' => QrOrderRound::STATUS_REJECTED, 'needs_review' => true]);
        $rows = $this->withToken($device->device_token)->getJson('/api/v1/device/tables/board')->assertOk()->json('data.tables');
        foreach ($rows as $row) {
            $this->assertSame('T-0905-007', $row['seating']['temp_reference']);
            $this->assertSame([$joined->table_id], $row['seating']['joined_table_ids']);
            $this->assertTrue($row['seating']['needs_review']);
            $this->assertSame(2, $row['seating']['needs_review_count']);
            $this->assertSame($order->uuid, $row['bill']['order_uuid']);
            $this->assertSame(7125, $row['bill']['grand_total_baisas']);
            $this->assertSame(2, $row['bill']['pending_rounds']);
            $this->assertTrue($row['bill']['awaiting_payment']);
            $this->assertTrue($row['bill']['charge_claim_live']);
        }
        $this->assertSame([$primary->uuid, $joined->uuid], array_column(array_column($rows, 'seating'), 'uuid'));
    }

    public function test_search_matches_reference_label_and_numeric_phone_tail_without_history_or_cross_tenant_leak(): void
    {
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable('Terrace Five'), ['temp_reference' => 'T-0905-007']);
        $customerId = DB::table('pos_customers')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Customer', 'phone' => '96892001234',
        ]);
        $this->seatingOrder($seating, ['customer_id' => $customerId, 'status' => Order::STATUS_HELD]);
        $this->seatingTable('Terrace empty');
        $terminal = $this->seatingRow($this->seatingTable('Terrace old'), ['status' => 'closed', 'closed_at' => now()]);
        $this->seatingOrder($terminal, ['status' => Order::STATUS_PAID, 'temp_reference' => 'T-0905-007', 'customer_id' => $customerId]);
        $foreign = $this->seatingRow($this->seatingTable('Terrace foreign', 20));
        $this->seatingOrder($foreign, ['customer_id' => $customerId, 'temp_reference' => 'T-0905-007']);
        $this->withToken($device->device_token);
        foreach (['t-0905-00', 'terra', '1234'] as $query) {
            $this->getJson('/api/v1/device/tables/search?q='.$query)->assertOk()
                ->assertJsonCount(1, 'data.tables')->assertJsonPath('data.tables.0.seating.uuid', $seating->uuid);
        }
        foreach (['234', 'a1234', '1234%', 'errace', '0905'] as $query) {
            $this->getJson('/api/v1/device/tables/search?q='.urlencode($query))->assertOk()->assertJsonPath('data.tables', []);
        }
    }

    public function test_joined_table_keeps_canonical_bill_when_primary_table_or_floor_was_soft_deleted(): void
    {
        $device = $this->seatingDevice();
        foreach (['table', 'floor'] as $retired) {
            $primaryTable = $this->seatingTable('Retired '.$retired);
            $primary = $this->seatingRow($primaryTable, ['temp_reference' => 'T-0905-009']);
            $order = $this->seatingOrder($primary, ['grand_total' => '9.125']);
            $joined = $this->seatingRow($this->seatingTable('Joined '.$retired), [
                'merged_into_id' => $primary->id, 'temp_reference' => null, 'order_id' => $order->id,
            ]);
            $this->seatingRound($primary, $order, [
                'status' => QrOrderRound::STATUS_PENDING_CONFIRMATION, 'needs_review' => true,
            ]);
            if ($retired === 'table') {
                $primaryTable->delete();
            } else {
                Floor::findOrFail($primaryTable->floor_id)->delete();
            }
            $rows = $this->withToken($device->device_token)->getJson('/api/v1/device/tables/board')->assertOk()->json('data.tables');
            $this->assertNotContains((int) $primaryTable->id, array_column($rows, 'table_id'));
            $row = collect($rows)->firstWhere('table_id', (int) $joined->table_id);
            $this->assertNotNull($row);
            $this->assertSame($joined->uuid, $row['seating']['uuid']);
            $this->assertSame('T-0905-009', $row['seating']['temp_reference']);
            $this->assertSame([(int) $joined->table_id], $row['seating']['joined_table_ids']);
            $this->assertSame(1, $row['seating']['needs_review_count']);
            $this->assertSame($order->uuid, $row['bill']['order_uuid']);
            $this->assertSame(9125, $row['bill']['grand_total_baisas']);
            $this->assertSame(1, $row['bill']['pending_rounds']);
        }
    }

    public function test_search_includes_unpaid_bill_without_seating_and_is_capped_at_twenty(): void
    {
        $device = $this->seatingDevice();
        for ($i = 0; $i < 23; $i++) {
            $seating = $this->seatingRow($this->seatingTable('Seat '.$i));
            $this->seatingOrder($seating);
        }
        $orphan = $this->seatingRow($this->seatingTable('Orphan'), ['status' => 'expired', 'closed_at' => now()]);
        $order = $this->seatingOrder($orphan, ['status' => Order::STATUS_KITCHEN, 'temp_reference' => 'T-0905-999', 'table_session_id' => null]);
        $this->withToken($device->device_token)->getJson('/api/v1/device/tables/search?q=Seat')
            ->assertOk()->assertJsonCount(20, 'data.tables');
        $this->getJson('/api/v1/device/tables/search?q=t-0905-999')->assertOk()
            ->assertJsonCount(1, 'data.tables')->assertJsonPath('data.tables.0.seating', null)
            ->assertJsonPath('data.tables.0.bill.order_uuid', $order->uuid);
        foreach (['', 'a', str_repeat('a', 33)] as $query) {
            $this->getJson('/api/v1/device/tables/search?q='.$query)->assertStatus(422)
                ->assertJsonPath('errors.0.code', 'validation_failed');
        }
    }

    public function test_all_three_reads_require_active_assigned_attended_or_station_device(): void
    {
        foreach (['fixed_pos', 'handheld', 'payment_station'] as $type) {
            $device = $this->seatingDevice($type);
            foreach (['board', 'feed', 'search?q=ab'] as $path) {
                $this->app['auth']->forgetGuards();
                $this->withToken($device->device_token)->getJson('/api/v1/device/tables/'.$path)->assertOk();
            }
        }
        $unsupported = $this->seatingDevice('customer_tablet');
        $inactive = $this->seatingDevice(attributes: ['status' => 'inactive']);
        $unassigned = $this->seatingDevice(attributes: ['branch_id' => null]);
        foreach ([[$unsupported, 409], [$inactive, 401], [$unassigned, 409]] as [$device, $status]) {
            foreach (['board', 'feed', 'search?q=ab'] as $path) {
                $this->app['auth']->forgetGuards();
                $this->withToken($device->device_token)->getJson('/api/v1/device/tables/'.$path)->assertStatus($status);
            }
        }
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', '')->getJson('/api/v1/device/tables/board')->assertUnauthorized();
    }

    public function test_new_reads_execute_no_sql_writes_and_do_not_lazy_expire_or_reattach(): void
    {
        $device = $this->seatingDevice();
        $station = $this->seatingDevice('payment_station');
        $seating = $this->seatingRow($this->seatingTable(), ['expires_at' => now()->subHour()]);
        $order = $this->seatingOrder($seating);
        $session = QrSession::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $station->id, 'table_id' => $seating->table_id, 'table_session_id' => null,
            'token' => hash('sha256', (string) Str::uuid()), 'token_expires_at' => now()->subHour(),
            'status' => QrSession::STATUS_ACTIVE, 'expires_at' => now()->subHour(),
        ]);
        $before = [$seating->fresh()->getRawOriginal(), $session->fresh()->getRawOriginal(), $order->fresh()->getRawOriginal()];
        $writes = [];
        DB::listen(static function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });
        foreach (['board', 'feed', 'search?q=T-0905'] as $path) {
            $this->withToken($device->device_token)->getJson('/api/v1/device/tables/'.$path)->assertOk();
        }
        $this->assertSame([], $writes);
        $this->assertSame($before, [$seating->fresh()->getRawOriginal(), $session->fresh()->getRawOriginal(), $order->fresh()->getRawOriginal()]);
        $this->assertDatabaseCount('pos_table_sessions', 1);
        $this->assertDatabaseCount('pos_table_session_events', 0);
        $this->assertDatabaseCount('pos_temp_reference_sequences', 0);
    }

    public function test_board_and_search_literal_json_contract(): void
    {
        $device = $this->seatingDevice();
        $table = $this->seatingTable();
        $seating = $this->seatingRow($table, ['uuid' => '11111111-1111-4111-8111-111111111111']);
        $order = $this->seatingOrder($seating, ['uuid' => '22222222-2222-4222-8222-222222222222']);
        $row = [
            'table_id' => (int) $table->id, 'table_label' => 'Table 5', 'table_status' => 'active', 'floor_id' => (int) $table->floor_id,
            'seating' => [
                'uuid' => $seating->uuid, 'status' => 'open', 'origin' => 'staff_till', 'temp_reference' => 'T-0905-001',
                'opened_at' => '2026-09-05T12:00:00+00:00', 'expires_at' => '2026-09-05T18:00:00+00:00',
                'needs_review' => false, 'needs_review_count' => 0, 'joined_table_ids' => [],
            ],
            'bill' => [
                'order_uuid' => $order->uuid, 'status' => 'open', 'grand_total_baisas' => 1000,
                'receipt_number' => null, 'temp_reference' => 'T-0905-001', 'pending_rounds' => 0,
                'awaiting_payment' => false, 'charge_claim_live' => false,
            ],
        ];
        $board = $this->withToken($device->device_token)->getJson('/api/v1/device/tables/board')->assertOk();
        $this->assertSame(['data' => ['tables' => [$row]], 'meta' => ['generated_at' => '2026-09-05T12:00:00+00:00', 'money_unit' => 'baisas'], 'errors' => []], $board->json());
        $search = $this->getJson('/api/v1/device/tables/search?q=t-0905')->assertOk();
        $this->assertSame(['data' => ['tables' => [$row]], 'meta' => ['money_unit' => 'baisas'], 'errors' => []], $search->json());
        fwrite(STDOUT, "\nT4_BOARD_JSON=".$board->getContent()."\nT4_SEARCH_JSON=".$search->getContent()."\n");
    }
}
