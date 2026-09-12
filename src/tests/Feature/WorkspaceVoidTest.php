<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\TableSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\QrPendingTestCase;
use Tests\Support\TableSessionFixtures;

final class WorkspaceVoidTest extends QrPendingTestCase
{
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('pos_staff')->insert([
            'id' => 700, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'name' => 'Synthetic manager', 'pin_hash' => Hash::make('4321'), 'position' => 'manager',
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function preview(Order $order, ?Device $device = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken(($device ?? $this->till)->device_token)
            ->getJson('/api/v1/device/qr/orders/'.$order->uuid.'/void-preview');
    }

    private function input(Order $order, ?Device $device = null): array
    {
        return ['preview_token' => $this->preview($order, $device)->assertOk()->json('data.preview_token'),
            'pin' => '4321', 'reason' => 'Customer cancelled'];
    }

    private function cancel(Order $order, array $input, ?Device $device = null): TestResponse
    {
        return $this->postAs($device ?? $this->till, '/api/v1/device/qr/orders/'.$order->uuid.'/void', $input);
    }

    private function rows(): array
    {
        $rows = $this->snapshot();
        foreach (['pos_table_sessions', 'pos_table_session_events', 'pos_qr_order_rounds', 'pos_sync_events',
            'pos_payments', 'pos_stock_movements', 'pos_product_stock_movements', 'pos_loyalty_transactions',
            'pos_sale_commissions', 'pos_roundup_donations'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $rows;
    }

    public static function unpaid(): iterable
    {
        foreach (['fixed_pos', 'handheld'] as $type) {
            foreach (['open', 'held', 'awaiting_payment'] as $status) {
                yield "$type/$status" => [$type, $status];
            }
        }
    }

    #[DataProvider('unpaid')]
    public function test_only_selected_unpaid_bill_is_voided_and_retry_writes_nothing(string $type, string $status): void
    {
        $device = $type === 'fixed_pos' ? $this->till : $this->device($type);
        $order = $this->order(['status' => $status]);
        $other = $this->order();
        $beforeOther = $this->raw($other);
        $before = $this->rows();
        $input = $this->input($order, $device);
        $this->assertSame($before, $this->rows());
        $this->cancel($order, $input, $device)->assertOk()->assertJsonPath('data.order_uuid', $order->uuid)
            ->assertJsonPath('data.status', 'void')->assertJsonPath('data.already_void', false);
        $this->assertSame($beforeOther, $this->raw($other));
        $this->assertSame('4.750', $order->fresh()->grand_total);
        $this->assertSame('T-0908-001', $order->fresh()->temp_reference);
        $this->assertNull($order->fresh()->receipt_number);
        $this->assertStringContainsString('Workspace manager #700: Customer cancelled', $order->fresh()->note);
        $this->assertDatabaseCount('pos_payments', 0);
        $this->assertDatabaseCount('pos_sync_events', 0);
        $this->assertDatabaseCount('pos_stock_movements', 0);
        $this->assertDatabaseCount('pos_product_stock_movements', 0);
        $after = $this->rows();
        $this->travel(10)->minutes();
        $this->cancel($order, $input, $device)->assertOk()->assertJsonPath('data.already_void', true);
        $this->assertSame($after, $this->rows());
        $this->assertStringNotContainsString('"4321"', json_encode($this->rows()));
        $this->assertStringNotContainsString('4321', $order->fresh()->note);
        $this->assertStringNotContainsString('"pin":', json_encode($this->rows()));
    }

    public static function chargeCases(): iterable
    {
        foreach (['open', 'held', 'awaiting_payment'] as $status) {
            foreach (['live_claim', 'expired_claim', 'uncertain', 'declined', 'cancelled', 'residue'] as $charge) {
                yield "$status/$charge" => [$status, $charge];
            }
        }
    }

    #[DataProvider('chargeCases')]
    public function test_charge_evidence_blocks_preview_and_a_racing_post_without_any_write(string $status, string $charge): void
    {
        $order = $this->order(['status' => $status]);
        $input = $this->input($order);
        $order->update($this->charge($charge));
        $before = $this->rows();
        $this->preview($order)->assertConflict()->assertJsonPath('errors.0.code', 'qr_charge_recovery_required');
        $this->cancel($order, $input)->assertConflict()->assertJsonPath('errors.0.code', 'qr_charge_recovery_required');
        $this->assertSame($before, $this->rows());
    }

    public static function changes(): iterable
    {
        foreach (['paid', 'pending_verification', 'combined', 'item', 'total', 'expired_preview', 'bad_pin',
            'inactive_manager', 'policy', 'tampered', 'other_bill', 'other_device', 'foreign_branch', 'station', 'reason'] as $case) {
            yield $case => [$case];
        }
    }

    #[DataProvider('changes')]
    public function test_stale_review_scope_and_authorization_refusals_write_nothing(string $case): void
    {
        $order = $this->order();
        $input = $this->input($order);
        $device = $this->till;
        switch ($case) {
            case 'paid': case 'pending_verification': case 'combined': $order->update(['status' => $case]);
                break;
            case 'item': DB::table('pos_order_items')->where('order_id', $order->id)->update(['notes' => 'changed']);
                break;
            case 'total': $order->update(['grand_total' => '5.000']);
                break;
            case 'expired_preview': $this->travel(6)->minutes();
                break;
            case 'bad_pin': $input['pin'] = '9999';
                break;
            case 'inactive_manager': DB::table('pos_staff')->where('id', 700)->update(['status' => 'inactive']);
                break;
            case 'policy': DB::table('pos_company_settings')->insert(['company_id' => 100, 'key' => 'manager_approval_positions', 'value' => '["owner"]']);
                break;
            case 'tampered': $input['preview_token'] .= 'bad';
                break;
            case 'other_bill': $order = $this->order();
                break;
            case 'other_device': $device = $this->device('handheld');
                break;
            case 'foreign_branch': $order->update(['branch_id' => 20]);
                break;
            case 'station': $device = $this->station;
                break;
            case 'reason': $input['reason'] = ' ';
                break;
        }
        $before = $this->rows();
        $response = $this->cancel($order, $input, $device);
        $this->assertContains($response->status(), [401, 404, 409, 422]);
        $this->assertSame($before, $this->rows());
    }

    public function test_joined_table_closure_rejects_pending_rounds_once_without_changing_unrelated_seating(): void
    {
        $order = $this->order(['status' => 'open']);
        $table = $this->seatingTable('T1');
        $second = $this->seatingTable('T2');
        $third = $this->seatingTable('T3');
        $seat = TableSession::create(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'table_id' => $table->id, 'order_id' => $order->id, 'origin' => 'qr', 'status' => 'open', 'opened_at' => now(), 'expires_at' => now()->addHours(4), 'temp_reference' => 'T-001']);
        $join = TableSession::create(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'table_id' => $second->id, 'order_id' => $order->id, 'merged_into_id' => $seat->id,
            'origin' => 'staff', 'status' => 'open', 'opened_at' => now(), 'expires_at' => now()->addHours(4), 'temp_reference' => 'T-001']);
        $other = TableSession::create(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'table_id' => $third->id, 'origin' => 'staff', 'status' => 'open', 'opened_at' => now(), 'expires_at' => now()->addHours(4), 'temp_reference' => 'T-003']);
        $order->update(['order_type' => 'dine_in', 'table_id' => $table->id, 'table_session_id' => $seat->id]);
        QrSession::whereKey($order->qr_session_id)->update(['table_id' => $table->id, 'table_session_id' => $seat->id]);
        $round = $this->seatingRound($seat, $order, ['status' => 'pending_confirmation']);
        $preview = $this->preview($order)->assertOk()->assertJsonPath('data.pending_rounds', 1);
        $input = ['preview_token' => $preview->json('data.preview_token'), 'pin' => '4321', 'reason' => 'Customer cancelled'];
        $otherBefore = $other->fresh()->getRawOriginal();
        $this->cancel($order, $input)->assertOk();
        $this->assertSame('closed', $seat->fresh()->status);
        $this->assertSame('voided', $seat->fresh()->close_reason);
        $this->assertSame('closed', $join->fresh()->status);
        $this->assertSame($otherBefore, $other->fresh()->getRawOriginal());
        $this->assertSame('closed', QrSession::findOrFail($order->qr_session_id)->status);
        $this->assertSame('rejected', $round->fresh()->status);
        $this->assertDatabaseCount('pos_table_session_events', 3);
        $after = $this->rows();
        $this->cancel($order, $input)->assertOk()->assertJsonPath('data.already_void', true);
        $this->assertSame($after, $this->rows());
    }

    public function test_preview_has_no_customer_or_secret_and_post_requires_pin_and_existing_throttle(): void
    {
        $order = $this->order();
        $response = $this->preview($order)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        foreach (['phone', 'customer_id', 'pin_hash', 'client_secret', 'recipe_snapshot'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $before = $this->rows();
        $this->cancel($order, ['preview_token' => $response->json('data.preview_token'), 'reason' => 'cancel'])
            ->assertUnprocessable();
        $this->assertSame($before, $this->rows());
        $this->assertContains('throttle:pos-login', app('router')->getRoutes()->getByName('device.qr.workspace-void')->gatherMiddleware());
    }
}
