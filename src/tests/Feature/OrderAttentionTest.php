<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\ListOrderAttentionAction;
use App\Actions\Qr\QrChargeException;
use App\Models\Device;
use App\Models\QrSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\QrPendingTestCase;
use Tests\Support\TableSessionFixtures;

final class OrderAttentionTest extends QrPendingTestCase
{
    use TableSessionFixtures;

    public function test_quick_attention_is_counter_only_scoped_and_read_only(): void
    {
        $order = $this->order([], 'explicit_expired');
        foreach ([['status' => 'awaiting_payment'], ['status' => 'paid'], ['status' => 'void'],
            ['source' => 'main_pos'], ['order_type' => 'dine_in'], ['branch_id' => 20], ['company_id' => 200],
        ] as $attributes) {
            $this->order($attributes);
        }
        foreach (['live_claim', 'uncertain', 'declined', 'cancelled', 'residue', 'expired_claim'] as $charge) {
            $this->order($this->charge($charge));
        }
        $before = $this->snapshot();
        $this->attention()->assertOk()->assertExactJson([
            'data' => ['version' => 1, 'quick_order_keys' => ['quick:'.$order->uuid], 'table_round_keys' => []],
            'meta' => [], 'errors' => [],
        ]);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_quick_snapshot_is_not_truncated_at_the_inbox_display_limit(): void
    {
        for ($i = 0; $i < 201; $i++) {
            $this->order();
        }
        $this->attention()->assertOk()->assertJsonCount(201, 'data.quick_order_keys');
    }

    public function test_gate_precedes_all_reads_and_reuses_the_existing_throttle(): void
    {
        $this->attention($this->station)->assertConflict()->assertJsonPath('errors.0.code', 'device_not_attended');
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            app(ListOrderAttentionAction::class)->handle($this->station);
            $this->fail('Station must not read the staff attention feed.');
        } catch (QrChargeException $exception) {
            $this->assertSame('device_not_attended', $exception->codeName);
            $this->assertSame([], DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
        $this->assertContains('throttle:qr-table-device-read', Route::getRoutes()->getByName('device.order-attention')->gatherMiddleware());
        $this->attention($this->device('handheld'))->assertOk();
    }

    public function test_customer_pending_round_is_stable_across_join_and_handover_but_staff_and_accepted_are_silent(): void
    {
        $table = $this->seatingTable();
        $seating = $this->seatingRow($table);
        $order = $this->seatingOrder($seating);
        $session = QrSession::create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => null, 'origin' => 'table_card', 'table_id' => $table->id,
            'table_session_id' => $seating->id, 'token' => hash('sha256', (string) Str::uuid()),
            'token_expires_at' => now()->subMinute(), 'status' => 'active', 'expires_at' => now()->subMinute(),
        ]);
        $round = $this->seatingRound($seating, $order, ['status' => 'pending_confirmation', 'qr_session_id' => $session->id]);
        $this->seatingRound($seating, $order, ['status' => 'pending_confirmation']);
        $this->seatingRound($seating, $order, ['qr_session_id' => $session->id]);
        $key = 'round:'.$round->id.':'.$round->client_request_id;
        $this->attention()->assertOk()->assertJsonPath('data.table_round_keys', [$key]);
        $this->seatingRow($this->seatingTable('joined'), ['merged_into_id' => $seating->id, 'order_id' => $order->id]);
        $session->update(['released_at' => now()]);
        $before = $session->fresh()->getRawOriginal();
        $this->attention()->assertOk()->assertJsonPath('data.table_round_keys', [$key]);
        $this->assertSame($before, $session->fresh()->getRawOriginal());
        $this->attention($this->device('fixed_pos', 20))->assertOk()->assertJsonPath('data.table_round_keys', []);
        $round->update(['status' => 'rejected']);
        $this->attention()->assertOk()->assertJsonPath('data.table_round_keys', []);
    }

    public function test_bill_less_first_customer_round_is_visible_without_any_write(): void
    {
        $table = $this->seatingTable();
        $seating = $this->seatingRow($table);
        $session = QrSession::create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $this->station->id, 'table_id' => $table->id, 'table_session_id' => $seating->id,
            'token' => hash('sha256', (string) Str::uuid()), 'token_expires_at' => now()->subMinute(),
            'status' => 'active', 'expires_at' => now()->subMinute(),
        ]);
        $round = $this->seatingRound($seating, null, ['qr_session_id' => $session->id, 'status' => 'pending_confirmation']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $result = app(ListOrderAttentionAction::class)->handle($this->till);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertSame(['round:'.$round->id.':'.$round->client_request_id], $result['table_round_keys']);
        foreach ($queries as $query) {
            $this->assertStringStartsWith('select', strtolower(ltrim($query['query'])));
        }
        $seating->update(['status' => 'closed', 'closed_at' => now()]);
        $this->attention()->assertOk()->assertJsonPath('data.table_round_keys', []);
    }

    private function attention(?Device $device = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken((string) ($device ?? $this->till)->plainTextToken)->getJson('/api/v1/device/order-attention');
    }
}
