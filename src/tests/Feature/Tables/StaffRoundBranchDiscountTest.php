<?php

declare(strict_types=1);

namespace Tests\Feature\Tables;

use App\Actions\Tables\AppendStaffRoundAction;
use App\Models\Order;
use App\Models\OrderDiscount;
use App\Models\QrOrderRound;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class StaffRoundBranchDiscountTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    public function test_merchant_automatic_product_discount_applies_only_at_its_target_branch_on_live_staff_rounds(): void
    {
        $this->travelTo(Carbon::parse('2026-09-06 12:00:00', 'UTC'));
        $product = $this->seatingProduct();
        $discountId = DB::table('pos_discounts')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Target branch rule',
            'scope' => 'product', 'amount_type' => 'fixed', 'amount' => '0.250',
            'stackable' => true, 'requires_manager_approval' => false, 'auto_apply' => true,
            'status' => 'active', 'branch_scope_json' => json_encode([10], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_discount_targets')->insert([
            'discount_id' => $discountId, 'target_type' => 'product', 'target_id' => $product->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([10 => 250, 11 => 0] as $branchId => $discount) {
            $device = $this->seatingDevice(branchId: $branchId);
            $seating = $this->seatingRow($this->seatingTable(branchId: $branchId), ['opened_by_device_id' => $device->id]);
            DB::table('pos_branch_settings')->insert([
                'company_id' => 100, 'branch_id' => $branchId, 'key' => 'table_sessions_mode',
                'value' => json_encode('live'), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $ack = app(AppendStaffRoundAction::class)->handle($device, [
                'seating_key' => $seating->client_request_id, 'table_id' => (int) $seating->table_id,
                'queued_offline' => false, 'client_request_id' => 'branch-'.$branchId, 'submitted_at' => now()->toIso8601String(),
                'lines' => [['product_id' => (int) $product->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
            ], now(), now());
            $this->assertSame('appended', $ack['outcome']);
            $round = QrOrderRound::findOrFail($ack['round_id']);
            $this->assertSame($discount, $round->priced_lines[0]['line_discount_baisas']);
            $this->assertSame(1000 - $discount, (int) $round->total_baisas);
            $bill = Order::query()->where('uuid', $ack['order_uuid'])->sole();
            $this->assertSame($discount === 0 ? '0.000' : '0.250', $bill->discount_total);
            $this->assertSame($discount === 0 ? '1.000' : '0.750', $bill->grand_total);
            $this->assertSame($discount === 0 ? [] : [$discountId],
                OrderDiscount::query()->where('order_id', $bill->id)->pluck('discount_id')->all());
        }
    }
}
