<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\Order;
use App\Models\SyncEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

// Run after the merchant's MergeEarnRace on the same disposable admin schema.
final class MergedReplayTest extends TestCase
{
    use TableSessionFixtures;

    public function test_real_merge_then_queued_create_redeem_attach_and_delta(): void
    {
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertStringStartsWith('qr_fix4', DB::connection()->getDatabaseName());
        $company = DB::table('pos_companies')->where('name', 'FIX4 MERGE RACE')->orderByDesc('id')->first();
        $this->assertNotNull($company);
        $source = Customer::withTrashed()->where('company_id', $company->id)->whereNotNull('merged_into_customer_id')->sole();
        $survivor = Customer::findOrFail($source->merged_into_customer_id);
        $account = LoyaltyAccount::where('customer_id', $survivor->id)->sole();
        $this->assertSame(310, $account->point_balance);
        $this->assertSame(16, $account->stamp_count);
        $branch = DB::table('pos_branches')->where('company_id', $company->id)->first();
        $staff = ((int) DB::table('pos_staff')->max('id')) + 1;
        $this->seedPosStaff([$staff], $company->id, $branch->id);
        $device = $this->seatingDevice('fixed_pos', $branch->id, $company->id);
        $product = $this->seatingProduct($company->id);
        $uuid = (string) Str::uuid();
        $event = ['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.create', 'client_timestamp' => now()->toIso8601String(), 'payload' => ['order' => [
            'uuid' => $uuid, 'order_type' => 'quick', 'source' => 'main_pos', 'staff_id' => $staff, 'opened_at' => now()->toIso8601String(), 'customer_id' => $source->id,
            'subtotal_baisas' => 1000, 'discount_total_baisas' => 500, 'tax_total_baisas' => 0, 'grand_total_baisas' => 500,
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price_baisas' => 1000, 'line_total_baisas' => 1000]],
            'discounts' => [['name' => 'Loyalty redemption', 'amount_baisas' => 500]],
        ]]];
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [$event]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame($survivor->id, Order::where('uuid', $uuid)->sole()->customer_id);
        $this->assertSame($source->id, SyncEvent::where('client_event_id', $event['client_event_id'])->sole()->payload_json['order']['customer_id']);
        $pay = ['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => now()->toIso8601String(), 'payload' => [
            'order_uuid' => $uuid, 'paid_at' => now()->toIso8601String(), 'payments' => [['method' => 'cash', 'amount_baisas' => 500]],
            'loyalty_redeem' => ['rule_id' => $account->loyalty_rule_id, 'points' => 100, 'stamps' => 0],
        ]];
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/v1/device/sync/push', ['events' => [$pay]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        }
        $this->assertSame(210, $account->fresh()->point_balance);
        $this->assertSame(1, DB::table('pos_loyalty_transactions')->where('loyalty_account_id', $account->id)->where('type', 'redeem')->count());
        $table = $this->seatingTable('FIX4 replay', $branch->id, $company->id);
        $seat = $this->seatingRow($table, ['opened_by_device_id' => $device->id]);
        $order = $this->seatingOrder($seat);
        $this->seatingRound($seat, $order);
        $this->postJson('/api/v1/device/tables/'.$seat->uuid.'/adjust', [
            'table_id' => $table->id, 'seating_key' => $seat->client_request_id, 'queued_offline' => false, 'client_request_id' => (string) Str::uuid(),
            'adjustment' => ['kind' => 'customer', 'mode' => 'attach', 'customer_id' => $source->id],
        ])->assertOk();
        $this->assertSame($survivor->id, $order->fresh()->customer_id);
        $delta = $this->getJson('/api/v1/device/config/delta?since='.urlencode($survivor->created_at->subSecond()->toIso8601String()))->assertOk();
        $shown = collect($delta->json('data.customers'))->firstWhere('id', $survivor->id);
        $this->assertNotNull($shown);
        $this->assertSame(210, $shown['loyalty'][0]['points']);
        $this->assertSame(16, $shown['loyalty'][0]['stamps']);
        $this->assertSame(['MERGED-RACE'], $shown['plates']);
        $this->getJson('/api/v1/device/customers/'.$source->id)->assertNotFound()->assertJsonPath('errors.0.code', 'customer_not_found');
        fwrite(STDOUT, "\nJ_END_TO_END ".json_encode(['company' => $company->id, 'source' => $source->id, 'survivor' => $survivor->id, 'before' => 310, 'after' => 210, 'debits' => 1, 'delta' => $shown])."\n");
    }
}
