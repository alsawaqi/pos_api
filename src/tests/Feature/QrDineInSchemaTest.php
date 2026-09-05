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
        foreach (['table_id', 'secret_rotated_at'] as $column) {
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
