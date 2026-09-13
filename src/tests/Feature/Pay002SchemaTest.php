<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\SoftPos\SoftPosProvider;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class Pay002SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_profiles_and_reversal_schema_mirror_admin_columns_and_indexes(): void
    {
        $this->assertTrue(Schema::hasColumns('pos_bank_softpos_profiles', [
            'bank_id', 'softpos_provider', 'softpos_package', 'currency_code',
            'refund_needs_transaction_id', 'void_needs_session_id', 'min_app_version',
            'is_active', 'notes', 'created_by_user_id', 'updated_by_user_id', 'provider_changed_at',
        ]));
        $this->assertTrue(Schema::hasColumns('pos_payments', [
            'softpos_provider', 'softpos_package', 'softpos_reported_provider', 'softpos_mismatch',
            'softpos_mismatch_note', 'softpos_transaction_id', 'softpos_rrn', 'softpos_batch_number',
            'softpos_card_masked', 'softpos_card_type', 'softpos_receipt_at', 'refunded_total',
            'voided_at', 'reversal_id', 'direction',
        ]));
        $this->assertTrue(Schema::hasColumns('pos_devices', [
            'card_tenders_blocked_reason', 'card_tenders_blocked_at', 'card_tenders_unblocked_at',
        ]));
        $this->assertTrue(Schema::hasColumn('pos_orders', 'refunded_total'));
        $this->assertTrue(Schema::hasColumns('pos_payment_reversals', [
            'uuid', 'company_id', 'branch_id', 'order_id', 'payment_id', 'kind', 'amount',
            'amount_baisas', 'currency_code', 'status', 'softpos_provider', 'softpos_package',
            'bank_id', 'terminal_id', 'original_softpos_transaction_id',
            'reversal_softpos_transaction_id', 'reversal_rrn', 'reversal_auth_code',
            'response_code', 'response_description', 'receipt_json', 'reason_code',
            'reason_note', 'void_reason_id', 'requested_by_staff_id', 'approved_by_staff_id',
            'device_id', 'client_request_id', 'request_fingerprint', 'attempted_at',
            'completed_at', 'resolved_by_user_id', 'resolved_note', 'ledger_payment_id',
        ]));
        $this->assertTrue(Schema::hasColumns('pos_payment_reversal_lines', [
            'reversal_id', 'order_item_id', 'qty', 'amount', 'amount_baisas',
            'stock_mode_at_refund', 'returned_to_stock', 'product_stock_movement_id',
        ]));
        foreach ([
            'pos_bank_softpos_profiles' => ['bank_id'],
            'pos_payment_reversals' => ['device_id', 'client_request_id'],
            'pos_payment_reversal_lines' => ['reversal_id', 'order_item_id'],
        ] as $table => $columns) {
            $this->assertNotNull(collect(Schema::getIndexes($table))->first(
                fn (array $index): bool => $index['unique'] && $index['columns'] === $columns
            ));
        }
        foreach (['softpos_mismatch', 'softpos_transaction_id', 'softpos_rrn'] as $column) {
            $this->assertTrue(Schema::hasIndex('pos_payments', [$column]));
        }
    }

    public function test_profile_defaults_and_bank_delete_restriction(): void
    {
        DB::table('banks')->insert(['id' => 1, 'name' => 'Fixture bank']);
        DB::table('pos_bank_softpos_profiles')->insert(['bank_id' => 1, 'softpos_provider' => 'none']);
        $profile = DB::table('pos_bank_softpos_profiles')->first();
        $this->assertSame('0512', $profile->currency_code);
        $this->assertNull($profile->softpos_package);
        $this->assertSame(1, (int) $profile->is_active);
        $this->assertSame(0, (int) $profile->refund_needs_transaction_id);
        $this->assertSame(0, (int) $profile->void_needs_session_id);
        $this->expectException(QueryException::class);
        DB::table('banks')->where('id', 1)->delete();
    }

    public function test_provider_defaults_are_fixed_codes_not_bank_ids(): void
    {
        $dhofar = SoftPosProvider::from('mosambee_dhofar');
        $muscat = SoftPosProvider::from('mosambee_muscat');
        $none = SoftPosProvider::from('none');
        $this->assertSame('com.mosambee.dhofar.softpos', $dhofar->package());
        $this->assertTrue($dhofar->refundNeedsTransactionId());
        $this->assertFalse($dhofar->voidNeedsSessionId());
        $this->assertSame('com.mosambee.muscat.softpos', $muscat->package());
        $this->assertFalse($muscat->refundNeedsTransactionId());
        $this->assertTrue($muscat->voidNeedsSessionId());
        $this->assertNull($none->package());
    }
}
