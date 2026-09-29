<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Support\QrPendingTestCase;

// Fix 11 (F-61): a till may leave out orders whose LOCAL payment evidence needs
// review. Left-out orders are not in the proof and are never cancelled.
final class QrExpiredOrdersExclusionTest extends QrPendingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('pos_staff')->insert(['uuid' => Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'name' => 'F61 synthetic manager', 'pin_hash' => Hash::make('4321'), 'position' => 'manager', 'status' => 'active']);
    }

    private function preview(array $exclude)
    {
        $this->app['auth']->forgetGuards();
        $query = http_build_query(['exclude_order_uuids' => $exclude]);

        return $this->withToken($this->till->plainTextToken)->getJson('/api/v1/device/qr/pending-orders/cancel-preview'.($exclude ? '?'.$query : ''));
    }

    public function test_bulk_preview_leaves_out_excluded_orders_and_cancel_never_touches_them(): void
    {
        $keep = $this->order([], 'closed');
        $a = $this->order([], 'closed');
        $b = $this->order([], 'timestamp_expired');
        $all = $this->preview([])->assertOk()->assertJsonPath('data.count', 3)->json('data');
        $this->assertSame(3, $all['count']);

        $preview = $this->preview([$keep->uuid])->assertOk()->assertJsonPath('data.count', 2)
            ->assertJsonPath('data.total_baisas', 9500)->json('data');
        $this->assertEqualsCanonicalizing([$a->uuid, $b->uuid], array_column($preview['orders'], 'uuid'));

        $this->postAs($this->till, '/api/v1/device/qr/pending-orders/cancel', [
            'client_request_id' => (string) Str::uuid(), 'preview_token' => $preview['preview_token'], 'pin' => '4321',
            'reason' => 'F61 synthetic cleanup', 'prepared_order_uuids' => [],
        ])->assertOk()->assertJsonPath('data.count', 2);
        $this->assertSame('void', $a->fresh()->status);
        $this->assertSame('void', $b->fresh()->status);
        $this->assertSame('held', $keep->fresh()->status, 'the left-out order is never cancelled');
    }

    public function test_exclusion_only_accepts_distinct_uuids(): void
    {
        $this->order([], 'closed');
        $this->preview(['not-a-uuid'])->assertUnprocessable();
        $id = (string) Str::uuid();
        $this->preview([$id, $id])->assertUnprocessable();
    }
}
