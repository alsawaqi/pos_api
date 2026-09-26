<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Models\Order;
use App\Models\SyncEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class MergedCustomerWriteTest extends TestCase
{
    use RefreshDatabase { refreshDatabase as private refreshMirror; }
    use TableSessionFixtures;

    public function refreshDatabase(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->beginDatabaseTransaction();
        } else {
            $this->refreshMirror();
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() === 'pgsql') {
            DB::table('pos_companies')->insertOrIgnore(['id' => 100, 'uuid' => (string) Str::uuid(), 'name' => 'FIX4 synthetic', 'status' => 'active']);
        }
        $this->seatingBranch();
        $this->seedPosStaff([7]);
    }

    private function pair(): array
    {
        $a = Customer::create(['uuid' => Str::uuid(), 'company_id' => 100, 'name' => 'Survivor', 'phone' => '+968 9000 0001']);
        $middle = Customer::create(['uuid' => Str::uuid(), 'company_id' => 100, 'name' => 'Middle', 'phone' => '96890000001', 'merged_into_customer_id' => $a->id]);
        $b = Customer::create(['uuid' => Str::uuid(), 'company_id' => 100, 'name' => 'Source', 'phone' => '90000001', 'merged_into_customer_id' => $middle->id]);
        $middle->delete();
        $b->delete();

        return [$a, $b];
    }

    public function test_queued_create_and_hold_follow_the_chain_and_pay_debits_survivor_once(): void
    {
        [$a, $b] = $this->pair();
        $d = $this->seatingDevice();
        $p = $this->seatingProduct();
        $rule = LoyaltyRule::create(['uuid' => Str::uuid(), 'company_id' => 100, 'name' => 'Folded', 'type' => 'spend_based', 'status' => 'active', 'config_json' => ['points_per_omr' => 0, 'redemption_points' => 100, 'redemption_value' => '0.500']]);
        $account = LoyaltyAccount::create(['uuid' => Str::uuid(), 'company_id' => 100, 'customer_id' => $a->id, 'loyalty_rule_id' => $rule->id, 'point_balance' => 310, 'stamp_count' => 16]);
        $uuid = (string) Str::uuid();
        $order = ['uuid' => $uuid, 'order_type' => 'quick', 'source' => 'main_pos', 'staff_id' => 7, 'opened_at' => now()->toIso8601String(), 'customer_id' => $b->id,
            'subtotal_baisas' => 1000, 'discount_total_baisas' => 500, 'tax_total_baisas' => 0, 'grand_total_baisas' => 500,
            'lines' => [['product_id' => $p->id, 'qty' => 1, 'unit_price_baisas' => 1000, 'line_total_baisas' => 1000]],
            'discounts' => [['name' => 'Loyalty redemption', 'amount_baisas' => 500]]];
        foreach (['order.hold', 'order.create'] as $type) {
            $event = ['client_event_id' => (string) Str::uuid(), 'event_type' => $type, 'client_timestamp' => now()->toIso8601String(), 'payload' => ['order' => $order]];
            $this->withToken($d->device_token)->postJson('/api/v1/device/sync/push', ['events' => [$event]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
            $this->assertSame($a->id, Order::where('uuid', $uuid)->sole()->customer_id);
            $this->assertSame($b->id, SyncEvent::where('client_event_id', $event['client_event_id'])->sole()->payload_json['order']['customer_id']);
        }
        $pay = ['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => now()->toIso8601String(), 'payload' => ['order_uuid' => $uuid, 'paid_at' => now()->toIso8601String(), 'payments' => [['method' => 'cash', 'amount_baisas' => 500]], 'loyalty_redeem' => ['rule_id' => $rule->id, 'points' => 100, 'stamps' => 0]]];
        for ($i = 0; $i < 2; $i++) {
            $this->withToken($d->device_token)->postJson('/api/v1/device/sync/push', ['events' => [$pay]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        }
        $this->assertSame(210, $account->fresh()->point_balance);
        $this->assertSame(16, $account->fresh()->stamp_count);
        $this->assertSame(1, DB::table('pos_loyalty_transactions')->where('loyalty_account_id', $account->id)->where('type', 'redeem')->count());
        $this->withToken($d->device_token)->getJson('/api/v1/device/customers/'.$b->id)->assertNotFound()->assertJsonPath('errors.0.code', 'customer_not_found');
        fwrite(STDOUT, "\nMERGED_REPLAY ".json_encode(['customer' => $a->id, 'source_payload' => $b->id, 'points_before' => 310, 'points_after' => 210, 'debits' => 1])."\n");
    }

    public function test_table_attach_resolves_merged_id_and_delta_contains_survivor_balances_and_plates(): void
    {
        [$a, $b] = $this->pair();
        $d = $this->seatingDevice();
        $s = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $d->id]);
        $o = $this->seatingOrder($s);
        $this->seatingRound($s, $o);
        $payload = ['table_id' => $s->table_id, 'seating_key' => $s->client_request_id, 'queued_offline' => false, 'client_request_id' => (string) Str::uuid(), 'adjustment' => ['kind' => 'customer', 'mode' => 'attach', 'customer_id' => $b->id]];
        $this->withToken($d->device_token)->postJson('/api/v1/device/tables/'.$s->uuid.'/adjust', $payload)->assertOk();
        $this->assertSame($a->id, $o->fresh()->customer_id);
        DB::table('pos_customer_vehicle_plates')->insert(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'customer_id' => $a->id, 'plate_number' => 'MERGED']);
        $rule = LoyaltyRule::create(['uuid' => Str::uuid(), 'company_id' => 100, 'name' => 'Folded', 'type' => 'spend_based', 'status' => 'active', 'config_json' => []]);
        LoyaltyAccount::create(['uuid' => Str::uuid(), 'company_id' => 100, 'customer_id' => $a->id, 'loyalty_rule_id' => $rule->id, 'point_balance' => 300, 'stamp_count' => 15]);
        $since = now()->subSecond()->toIso8601String();
        $a->touch();
        $response = $this->withToken($d->device_token)->getJson('/api/v1/device/config/delta?since='.urlencode($since))->assertOk();
        $customer = collect($response->json('data.customers'))->firstWhere('id', $a->id);
        $this->assertSame(['MERGED'], $customer['plates']);
        $this->assertSame(300, $customer['loyalty'][0]['points']);
        $this->assertSame(15, $customer['loyalty'][0]['stamps']);
    }
}
