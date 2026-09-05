<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class QrDineInSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_sqlite_mirror_has_all_qr2_columns_indexes_and_foreign_keys(): void
    {
        foreach (['table_id', 'secret_rotated_at', 'table_session_id', 'origin', 'scan_fingerprint_hash', 'scan_ip_hash', 'scan_geofence_verdict'] as $column) {
            $this->assertTrue(Schema::hasColumn('pos_qr_sessions', $column), $column);
        }

        $this->assertEqualsCanonicalizing([
            'id',
            'qr_session_id',
            'order_id',
            'round_no',
            'status',
            'client_request_id',
            'priced_lines',
            'confirm_payload',
            'accepted_seq',
            'subtotal_baisas',
            'tax_baisas',
            'total_baisas',
            'submitted_at',
            'resolved_at',
            'resolved_by_device_id',
            'created_at',
            'updated_at',
            'table_session_id',
            'origin_table_session_id',
            'kitchen_printed_at',
            'needs_review',
        ], Schema::getColumnListing('pos_qr_order_rounds'));

        $sessionIndexes = collect(DB::select("PRAGMA index_list('pos_qr_sessions')"))->keyBy('name');
        $roundIndexes = collect(DB::select("PRAGMA index_list('pos_qr_order_rounds')"))->keyBy('name');

        $this->assertTrue($sessionIndexes->has('pos_qr_sessions_table_live_unique'));
        $this->assertSame(1, (int) $sessionIndexes['pos_qr_sessions_table_live_unique']->unique);
        $this->assertSame(1, (int) $sessionIndexes['pos_qr_sessions_table_live_unique']->partial);
        $this->assertTrue($roundIndexes->has('pos_qr_rounds_session_request_unique'));
        $this->assertSame(1, (int) $roundIndexes['pos_qr_rounds_session_request_unique']->unique);
        $this->assertTrue($roundIndexes->has('pos_qr_rounds_order_status_idx'));
        $this->assertSame(0, (int) $roundIndexes['pos_qr_rounds_order_status_idx']->unique);
        $this->assertTrue($roundIndexes->has('pos_qr_order_rounds_accepted_seq_unique'));
        $this->assertSame(1, (int) $roundIndexes['pos_qr_order_rounds_accepted_seq_unique']->unique);

        $sessionForeignKeys = collect(DB::select("PRAGMA foreign_key_list('pos_qr_sessions')"))->keyBy('from');
        $roundForeignKeys = collect(DB::select("PRAGMA foreign_key_list('pos_qr_order_rounds')"))->keyBy('from');

        $this->assertForeignKey($sessionForeignKeys, 'table_id', 'pos_tables', 'SET NULL');
        $this->assertForeignKey($roundForeignKeys, 'qr_session_id', 'pos_qr_sessions', 'CASCADE');
        $this->assertForeignKey($roundForeignKeys, 'order_id', 'pos_orders', 'SET NULL');
        $this->assertForeignKey($roundForeignKeys, 'resolved_by_device_id', 'pos_devices', 'SET NULL');
        $this->assertForeignKey($sessionForeignKeys, 'table_session_id', 'pos_table_sessions', 'SET NULL');
        $this->assertForeignKey($roundForeignKeys, 'table_session_id', 'pos_table_sessions', 'SET NULL');
        $this->assertForeignKey($roundForeignKeys, 'origin_table_session_id', 'pos_table_sessions', 'SET NULL');
        $orderForeignKeys = collect(DB::select("PRAGMA foreign_key_list('pos_orders')"))->keyBy('from');
        $this->assertForeignKey($orderForeignKeys, 'table_session_id', 'pos_table_sessions', 'SET NULL');

        foreach ([
            'pos_table_sessions' => [
                'id',
                'uuid',
                'company_id',
                'branch_id',
                'table_id',
                'status',
                'origin',
                'opened_by_device_id',
                'closed_by_device_id',
                'order_id',
                'merged_into_id',
                'temp_reference',
                'client_request_id',
                'opened_at',
                'expires_at',
                'billing_at',
                'closed_at',
                'close_reason',
                'created_at',
                'updated_at',
            ],
            'pos_table_session_events' => [
                'id',
                'company_id',
                'branch_id',
                'table_session_id',
                'table_id',
                'event_type',
                'device_id',
                'payload',
                'created_at',
            ],
            'pos_qr_session_scans' => [
                'id',
                'company_id',
                'branch_id',
                'table_id',
                'qr_session_id',
                'table_session_id',
                'role',
                'device_fingerprint_hash',
                'ip_hash',
                'latitude',
                'longitude',
                'geofence_verdict',
                'scanned_at',
                'created_at',
            ],
            'pos_kitchen_tickets' => [
                'id',
                'company_id',
                'branch_id',
                'ticket_key',
                'round_id',
                'order_id',
                'claimed_by_device_id',
                'claimed_at',
                'printed_at',
                'print_result',
                'created_at',
                'updated_at',
            ],
        ] as $table => $columns) {
            $this->assertTrue(Schema::hasTable($table), $table);
            $this->assertSame($columns, Schema::getColumnListing($table), $table);
            $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('{$table}')"))->keyBy('from');
            $this->assertFalse($foreignKeys->has('company_id'), $table.' omits the absent company parent.');
        }

        foreach ([
            'pos_table_sessions' => [
                'pos_table_sessions_branch_status_idx' => [['branch_id', 'status'], 0, 0],
                'pos_table_sessions_branch_temp_reference_idx' => [['branch_id', 'temp_reference'], 0, 0],
                'pos_table_sessions_order_idx' => [['order_id'], 0, 0],
                'pos_table_sessions_status_expires_idx' => [['status', 'expires_at'], 0, 0],
                'pos_table_sessions_table_live_unique' => [['table_id'], 1, 1],
                'pos_table_sessions_branch_request_unique' => [['branch_id', 'client_request_id'], 1, 1],
            ],
            'pos_table_session_events' => [
                'pos_table_session_events_branch_cursor_idx' => [['branch_id', 'id'], 0, 0],
                'pos_table_session_events_session_idx' => [['table_session_id', 'id'], 0, 0],
            ],
            'pos_qr_session_scans' => [
                'pos_qr_session_scans_branch_scanned_idx' => [['branch_id', 'scanned_at'], 0, 0],
                'pos_qr_session_scans_table_scanned_idx' => [['table_id', 'scanned_at'], 0, 0],
            ],
            'pos_kitchen_tickets' => [
                'pos_kitchen_tickets_branch_key_unique' => [['branch_id', 'ticket_key'], 1, 0],
            ],
            'pos_qr_sessions' => [
                'pos_qr_sessions_table_session_idx' => [['table_session_id'], 0, 0],
            ],
            'pos_qr_order_rounds' => [
                'pos_qr_rounds_table_session_request_unique' => [['table_session_id', 'client_request_id'], 1, 0],
            ],
            'pos_orders' => [
                'pos_orders_table_session_idx' => [['table_session_id'], 0, 0],
            ],
        ] as $table => $expectedIndexes) {
            $indexes = collect(DB::select("PRAGMA index_list('{$table}')"))->keyBy('name');
            foreach ($expectedIndexes as $name => [$columns, $unique, $partial]) {
                $this->assertTrue($indexes->has($name), $name);
                $this->assertSame($unique, (int) $indexes[$name]->unique, $name);
                $this->assertSame($partial, (int) $indexes[$name]->partial, $name);
                $this->assertSame(
                    $columns,
                    collect(DB::select("PRAGMA index_info('{$name}')"))->pluck('name')->all(),
                    $name,
                );
            }
        }

        foreach ([
            'pos_table_sessions' => [
                'branch_id' => ['pos_branches', 'CASCADE'],
                'table_id' => ['pos_tables', 'CASCADE'],
                'opened_by_device_id' => ['pos_devices', 'SET NULL'],
                'closed_by_device_id' => ['pos_devices', 'SET NULL'],
                'order_id' => ['pos_orders', 'SET NULL'],
                'merged_into_id' => ['pos_table_sessions', 'SET NULL'],
            ],
            'pos_table_session_events' => [
                'branch_id' => ['pos_branches', 'CASCADE'],
                'table_session_id' => ['pos_table_sessions', 'CASCADE'],
                'table_id' => ['pos_tables', 'CASCADE'],
                'device_id' => ['pos_devices', 'SET NULL'],
            ],
            'pos_qr_session_scans' => [
                'branch_id' => ['pos_branches', 'CASCADE'],
                'table_id' => ['pos_tables', 'SET NULL'],
                'qr_session_id' => ['pos_qr_sessions', 'SET NULL'],
                'table_session_id' => ['pos_table_sessions', 'SET NULL'],
            ],
            'pos_kitchen_tickets' => [
                'branch_id' => ['pos_branches', 'CASCADE'],
                'round_id' => ['pos_qr_order_rounds', 'SET NULL'],
                'order_id' => ['pos_orders', 'SET NULL'],
                'claimed_by_device_id' => ['pos_devices', 'SET NULL'],
            ],
        ] as $table => $expectedForeignKeys) {
            $foreignKeys = collect(DB::select("PRAGMA foreign_key_list('{$table}')"))->keyBy('from');
            foreach ($expectedForeignKeys as $column => [$referencedTable, $onDelete]) {
                $this->assertForeignKey($foreignKeys, $column, $referencedTable, $onDelete);
            }
        }

        $sessionColumns = collect(DB::select("PRAGMA table_info('pos_qr_sessions')"))->keyBy('name');
        $roundColumns = collect(DB::select("PRAGMA table_info('pos_qr_order_rounds')"))->keyBy('name');
        $this->assertSame(0, (int) $sessionColumns['device_id']->notnull);
        $this->assertSame(1, (int) $roundColumns['needs_review']->notnull);
        $this->assertSame('0', trim((string) $roundColumns['needs_review']->dflt_value, "'\""));
        foreach (['cancel_disposition', 'cancelled_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('pos_order_items', $column), $column);
        }

        $this->assertSame([
            'id',
            'company_id',
            'branch_id',
            'key',
            'value',
            'created_at',
            'updated_at',
        ], Schema::getColumnListing('pos_branch_settings'));
        $branchSettingIndexes = collect(DB::select("PRAGMA index_list('pos_branch_settings')"))->keyBy('name');
        $this->assertTrue($branchSettingIndexes->has('pos_branch_settings_branch_key_unique'));
        $this->assertSame(1, (int) $branchSettingIndexes['pos_branch_settings_branch_key_unique']->unique);
        $this->assertTrue($branchSettingIndexes->has('pos_branch_settings_company_key_idx'));
        $this->assertSame(0, (int) $branchSettingIndexes['pos_branch_settings_company_key_idx']->unique);
        $this->assertSame(
            ['branch_id', 'key'],
            collect(DB::select("PRAGMA index_info('pos_branch_settings_branch_key_unique')"))->pluck('name')->all(),
        );
        $branchSettingForeignKeys = collect(DB::select("PRAGMA foreign_key_list('pos_branch_settings')"))->keyBy('from');
        $this->assertForeignKey($branchSettingForeignKeys, 'branch_id', 'pos_branches', 'CASCADE');
        $this->assertFalse($branchSettingForeignKeys->has('company_id'));
    }

    public function test_live_table_partial_unique_applies_only_to_live_dine_in_sessions(): void
    {
        $this->insertDevice(100);
        $this->insertTable(10);
        $this->insertSession(20, 10, 'active');

        foreach (['pending', 'active', 'ordered'] as $offset => $status) {
            try {
                $this->insertSession(30 + $offset, 10, $status);
                $this->fail("The live-table index accepted a second {$status} session.");
            } catch (QueryException) {
                // Expected: all three statuses satisfy the partial predicate.
            }
        }

        $this->insertSession(40, 10, 'closed');
        $this->insertSession(41, 10, 'expired');
        $this->insertSession(42, null, 'pending');
        $this->insertSession(43, null, 'active');

        DB::table('pos_qr_sessions')->where('id', 20)->update(['status' => 'closed']);
        $this->insertSession(44, 10, 'ordered');

        $this->assertSame(4, DB::table('pos_qr_sessions')->where('table_id', 10)->count());
        $this->assertSame(2, DB::table('pos_qr_sessions')->whereNull('table_id')->count());
    }

    public function test_all_four_qr2_foreign_key_delete_actions_execute(): void
    {
        $this->insertDevice(100);
        $this->insertDevice(101);
        $this->insertTable(200);
        $this->insertSession(300, 200, 'active', 100);
        $this->insertOrder(400, 300);
        $this->insertRound(500, 300, 400, 101);

        DB::table('pos_tables')->where('id', 200)->delete();
        $this->assertNull(DB::table('pos_qr_sessions')->where('id', 300)->value('table_id'));

        DB::table('pos_orders')->where('id', 400)->delete();
        $this->assertNull(DB::table('pos_qr_order_rounds')->where('id', 500)->value('order_id'));

        DB::table('pos_devices')->where('id', 101)->delete();
        $this->assertNull(DB::table('pos_qr_order_rounds')->where('id', 500)->value('resolved_by_device_id'));

        DB::table('pos_qr_sessions')->where('id', 300)->delete();
        $this->assertDatabaseMissing('pos_qr_order_rounds', ['id' => 500]);
    }

    /**
     * @param  Collection<string, object>  $foreignKeys
     */
    private function assertForeignKey(
        Collection $foreignKeys,
        string $column,
        string $table,
        string $onDelete,
    ): void {
        $this->assertTrue($foreignKeys->has($column), "Missing foreign key for {$column}.");
        $this->assertSame($table, $foreignKeys[$column]->table);
        $this->assertSame($onDelete, $foreignKeys[$column]->on_delete);
    }

    private function insertDevice(int $id): void
    {
        DB::table('pos_devices')->insert([
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'serial_number' => "qr2-schema-device-{$id}",
        ]);
    }

    private function insertTable(int $id): void
    {
        DB::table('pos_tables')->insert([
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'floor_id' => 10,
            'label' => "T{$id}",
        ]);
    }

    private function insertSession(
        int $id,
        ?int $tableId,
        string $status,
        int $deviceId = 100,
    ): void {
        DB::table('pos_qr_sessions')->insert([
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => 10,
            'device_id' => $deviceId,
            'table_id' => $tableId,
            'token' => hash('sha256', "qr2-schema-token-{$id}"),
            'token_expires_at' => now()->addHour(),
            'status' => $status,
            'expires_at' => now()->addHours(6),
        ]);
    }

    private function insertOrder(int $id, int $sessionId): void
    {
        DB::table('pos_orders')->insert([
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => 10,
            'qr_session_id' => $sessionId,
            'order_type' => 'dine_in',
            'status' => 'open',
            'source' => 'qr_web',
        ]);
    }

    private function insertRound(
        int $id,
        int $sessionId,
        int $orderId,
        int $resolvedByDeviceId,
    ): void {
        DB::table('pos_qr_order_rounds')->insert([
            'id' => $id,
            'qr_session_id' => $sessionId,
            'order_id' => $orderId,
            'round_no' => 1,
            'status' => 'accepted',
            'client_request_id' => 'qr2-schema-request',
            'priced_lines' => '[]',
            'subtotal_baisas' => 1000,
            'tax_baisas' => 50,
            'total_baisas' => 1050,
            'submitted_at' => now(),
            'resolved_at' => now(),
            'resolved_by_device_id' => $resolvedByDeviceId,
        ]);
    }
}
