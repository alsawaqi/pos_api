<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\ResolveQrCustomerAction;
use App\Models\Customer;
use App\Models\Device;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\TableSession;
use App\Support\CanonicalPhone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class CanonicalCustomerTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    private function customer(string $phone, string $name = 'Original'): Customer
    {
        return Customer::create(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => $name, 'phone' => $phone]);
    }

    private function device(): void
    {
        Device::factory()->paired('canonical-test')->create(['company_id' => 100, 'branch_id' => 10]);
        $this->withToken('canonical-test');
    }

    public function test_canonical_golden_vectors(): void
    {
        foreach (json_decode(file_get_contents(base_path('tests/Fixtures/canonical-phone-vectors.json')), true, flags: JSON_THROW_ON_ERROR) as [$raw, $expected]) {
            $this->assertSame($expected, CanonicalPhone::of($raw), $raw);
        }
    }

    public function test_device_and_qr_writers_use_one_customer_in_every_format(): void
    {
        $this->device();
        $customer = $this->customer('+968 9000 0001');
        foreach (['90000001', '0096890000001', '96890000001', '٩٠٠٠٠٠٠١', '+968 9000 0001'] as $phone) {
            $this->postJson('/api/v1/device/customers', ['name' => 'Typed', 'phone' => $phone])->assertOk()
                ->assertJsonPath('data.customer.id', $customer->id);
            $this->assertSame($customer->id, app(ResolveQrCustomerAction::class)->handle(100, $phone, null)->customerId);
            $this->getJson('/api/v1/device/customers/search?q='.urlencode($phone))->assertOk()
                ->assertJsonCount(1, 'data.customers')->assertJsonPath('data.customers.0.id', $customer->id);
        }
        $this->assertSame(1, Customer::withTrashed()->count());
        $this->assertSame('Original', $customer->fresh()->name);
    }

    public function test_lowest_live_duplicate_wins_and_exact_null_backfill_does_not_insert(): void
    {
        $this->device();
        $first = $this->customer('+968 9000 0001', 'Z first');
        $this->customer('96890000001', 'A duplicate');
        $this->getJson('/api/v1/device/customers/search?q=90000001')->assertOk()
            ->assertJsonCount(1, 'data.customers')->assertJsonPath('data.customers.0.id', $first->id);
        $this->postJson('/api/v1/device/customers', ['name' => 'Typed', 'phone' => '96890000001'])->assertOk()
            ->assertJsonPath('data.customer.id', $first->id);
        DB::table('pos_customers')->where('id', $first->id)->update(['phone_canonical' => null]);
        $this->postJson('/api/v1/device/customers', ['name' => 'Typed', 'phone' => '+968 9000 0001'])->assertOk()
            ->assertJsonPath('data.customer.id', $first->id);
        $this->assertSame(2, Customer::withTrashed()->count());
    }

    public function test_merged_exact_phone_returns_survivor_without_reviving_source(): void
    {
        $this->device();
        $survivor = $this->customer('+968 9000 0001');
        $source = $this->customer('96890000001');
        $source->update(['merged_into_customer_id' => $survivor->id]);
        $source->delete();
        $survivor->delete();
        $this->postJson('/api/v1/device/customers', ['name' => 'Typed', 'phone' => '96890000001'])->assertOk()
            ->assertJsonPath('data.customer.id', $survivor->id);
        $this->assertSame($survivor->id, app(ResolveQrCustomerAction::class)->handle(100, '96890000001', null)->customerId);
        $this->assertTrue($source->fresh()->trashed());
        $this->assertFalse($survivor->fresh()->trashed());
        $this->getJson('/api/v1/device/customers/'.$source->id)->assertNotFound()->assertJsonPath('errors.0.code', 'customer_not_found');
    }

    public function test_null_canonical_uses_only_exact_string_and_short_search_is_unchanged(): void
    {
        $this->device();
        $a = $this->customer('N/A');
        $b = $this->customer('-');
        $this->postJson('/api/v1/device/customers', ['name' => 'Typed', 'phone' => 'N/A'])->assertOk()->assertJsonPath('data.customer.id', $a->id);
        $this->postJson('/api/v1/device/customers', ['name' => 'Typed', 'phone' => '-'])->assertOk()->assertJsonPath('data.customer.id', $b->id);
        $c = $this->customer('+968 9000 0001');
        $this->getJson('/api/v1/device/customers/search?q=9000')->assertOk()->assertJsonPath('data.customers.0.id', $c->id);
        $this->assertNull($a->fresh()->phone_canonical);
        $this->assertNull($b->fresh()->phone_canonical);
    }

    public function test_real_qr_checkout_and_dine_in_reformat_preserve_identity_and_redemption(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedPosStaff([7]);
        $a = $this->customer('+968 9000 0001');
        $station = $this->seatingDevice('payment_station');
        $till = $this->seatingDevice();
        $product = $this->seatingProduct();
        $this->withToken($station->plainTextToken)->postJson('/api/v1/device/qr/rotate')->assertOk();
        $quick = QrSession::whereNull('table_id')->sole();
        $this->postJson('/api/v1/public/qr/bind', ['token' => $quick->token, 'client_secret' => 'fix4-quick'])->assertOk();
        $lines = [['product_id' => $product->id, 'qty' => 2, 'addon_ids' => [], 'notes' => null]];
        $this->withHeaders(['X-QR-Session' => $quick->uuid, 'X-QR-Client-Secret' => 'fix4-quick'])->postJson('/api/v1/public/qr/checkout', [
            'client_request_id' => (string) Str::uuid(), 'checkout_choice' => 'counter', 'phone' => '90000001', 'lines' => $lines,
        ])->assertCreated();
        $this->assertSame($a->id, Order::sole()->customer_id);
        $table = $this->seatingTable();
        $seat = $this->seatingRow($table, ['opened_by_device_id' => $till->id]);
        $order = $this->seatingOrder($seat, ['customer_id' => $a->id]);
        $this->seatingRound($seat, $order);
        $this->app['auth']->forgetGuards();
        $open = $this->withToken($station->plainTextToken)->postJson('/api/v1/device/qr/open-table', ['table_id' => $table->id])->assertCreated();
        $bind = $this->postJson('/api/v1/public/qr/table-bind', ['table_token' => $open->json('data.table_token'), 'client_secret' => 'fix4-table'])->assertOk();
        $this->withHeaders(['X-QR-Session' => $bind->json('data.session_uuid'), 'X-QR-Client-Secret' => 'fix4-table']);
        $order = Order::whereNotNull('table_session_id')->sole();
        $this->assertSame($a->id, $order->customer_id);
        $seat = TableSession::findOrFail($order->table_session_id);
        $rule = LoyaltyRule::create(['uuid' => Str::uuid(), 'company_id' => 100, 'name' => 'Fix4 points', 'type' => 'spend_based', 'status' => 'active', 'config_json' => ['redemption_points' => 100, 'redemption_value' => '0.500']]);
        LoyaltyAccount::create(['uuid' => Str::uuid(), 'company_id' => 100, 'customer_id' => $a->id, 'loyalty_rule_id' => $rule->id, 'point_balance' => 200, 'stamp_count' => 0]);
        $this->app['auth']->forgetGuards();
        $this->withToken($till->plainTextToken)->postJson('/api/v1/device/tables/'.$seat->uuid.'/adjust', [
            'table_id' => $seat->table_id, 'seating_key' => $seat->uuid, 'queued_offline' => false, 'client_request_id' => (string) Str::uuid(),
            'adjustment' => ['kind' => 'loyalty', 'mode' => 'redeem', 'rule_id' => $rule->id, 'blocks' => 1, 'approved_by_staff_id' => 7, 'authorized_by' => 'Fix4 staff'],
        ])->assertOk();
        $before = DB::table('pos_order_discounts')->where('order_id', $order->id)->orderBy('id')->get()->toJson();
        $this->postJson('/api/v1/public/qr/table-round', ['client_request_id' => (string) Str::uuid(), 'phone' => '90000001', 'lines' => $lines])->assertCreated();
        $this->assertSame($before, DB::table('pos_order_discounts')->where('order_id', $order->id)->orderBy('id')->get()->toJson());
        $this->assertSame($a->id, $order->fresh()->customer_id);
        $this->assertSame(1, Customer::withTrashed()->count());
    }

    public function test_noncanonical_search_responses_are_unchanged_preservation(): void
    {
        $this->device();
        $a = $this->customer('+968 9000 0001', 'Name 1234');
        foreach (['A123', '1234 A', 'B1234'] as $plate) {
            DB::table('pos_customer_vehicle_plates')->insert(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'customer_id' => $a->id, 'plate_number' => $plate]);
        }
        foreach (['A123', '1234 A', 'B1234', 'Name 1234', '9000'] as $q) {
            $response = $this->getJson('/api/v1/device/customers/search?q='.urlencode($q))->assertOk()->assertJsonCount(1, 'data.customers');
            $this->assertSame($a->id, $response->json('data.customers.0.id'));
            fwrite(STDOUT, "\nSEARCH_PRESERVATION ".$q.' '.$response->getContent()."\n");
        }
    }
}
