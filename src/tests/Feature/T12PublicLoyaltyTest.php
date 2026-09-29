<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\TableSession;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class T12PublicLoyaltyTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function flow(int $company = 100, int $branch = 10): array
    {
        $station = $this->seatingDevice('payment_station', $branch, $company);
        $till = $this->seatingDevice('fixed_pos', $branch, $company);
        $table = $this->seatingTable('T12 synthetic', $branch, $company);
        $product = $this->seatingProduct($company);
        $this->app['auth']->forgetGuards();
        $open = $this->withToken($station->plainTextToken)->postJson('/api/v1/device/qr/open-table', ['table_id' => $table->id])->assertCreated();
        $bind = $this->postJson('/api/v1/public/qr/table-bind', ['table_token' => $open->json('data.table_token'), 'client_secret' => 't12-synthetic-browser'])->assertOk();
        $session = QrSession::where('uuid', $bind->json('data.session_uuid'))->sole();
        $this->headersFor($session);

        return [$session, $product, $till];
    }

    private function headersFor($session): void
    {
        $this->withHeaders(['X-QR-Session' => $session->uuid, 'X-QR-Client-Secret' => 't12-synthetic-browser']);
    }

    private function round($product, array $identity = [])
    {
        return $this->postJson('/api/v1/public/qr/table-round', $identity + ['client_request_id' => (string) Str::uuid(),
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]]]);
    }

    private function pay($till, $order): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($till->plainTextToken)->postJson('/api/v1/device/qr/claim-settlement', ['order_uuid' => $order->uuid])->assertOk();
        $this->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $order->uuid, 'paid_at' => now()->toIso8601String(),
                'loyalty_redeem' => ['rule_id' => 9999, 'points' => 99999, 'stamps' => 10],
                'payments' => [['method' => 'cash', 'amount_baisas' => Money::toBaisas($order->fresh()->grand_total)]]],
        ]]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
    }

    public function test_optional_phone_plate_only_pays_without_earn_or_device_redemption(): void
    {
        [$session, $product, $till] = $this->flow();
        $this->round($product, ['plate_number' => 'om 12'])->assertCreated();
        $order = Order::sole();
        $this->assertNull($order->customer_id);
        $this->assertSame('OM 12', $order->plate_number);
        $this->getJson('/api/v1/public/qr/loyalty')->assertOk()->assertExactJson(['data' => ['accounts' => []], 'meta' => [], 'errors' => []]);
        $this->pay($till, $order);
        $this->assertDatabaseCount('pos_loyalty_transactions', 0);
        $this->getJson('/api/v1/public/qr/status')->assertOk()->assertJsonPath('data.loyalty_earned', ['points' => 0, 'stamps' => 0]);
    }

    public function test_second_round_can_attach_phone_once_and_existing_identity_guard_stays(): void
    {
        [$session, $product] = $this->flow();
        $this->round($product)->assertCreated();
        $order = Order::sole();
        $this->getJson('/api/v1/public/qr/status')->assertOk()->assertJsonPath('data.dine_in.credential.identity_required', false)
            ->assertJsonPath('data.dine_in.credential.identity_allowed', true)->assertJsonPath('data.dine_in.loyalty_available', false);
        $this->round($product, ['phone' => '92001234'])->assertCreated();
        $this->assertNotNull($order->fresh()->customer_id);
        $this->assertSame(1, Order::count());
        $this->getJson('/api/v1/public/qr/status')->assertOk()->assertJsonPath('data.dine_in.credential.identity_allowed', false)
            ->assertJsonPath('data.dine_in.loyalty_available', true);
        $this->round($product, ['phone' => '92001235'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'qr_round_identity_already_set');
        $this->assertSame('92001234', Customer::findOrFail($order->fresh()->customer_id)->phone);
        $this->round($product)->assertCreated();
    }

    public function test_staff_detachment_cannot_reset_an_accepted_phone_identity(): void
    {
        [$session, $product, $till] = $this->flow();
        $this->round($product)->assertCreated();
        $this->round($product, ['phone' => '92001234'])->assertCreated();
        $order = Order::sole();
        $seating = TableSession::findOrFail($order->table_session_id);
        $this->app['auth']->forgetGuards();
        $this->withToken($till->plainTextToken)->postJson('/api/v1/device/tables/'.$seating->uuid.'/adjust', [
            'table_id' => (int) $seating->table_id, 'seating_key' => $seating->uuid,
            'queued_offline' => false, 'client_request_id' => (string) Str::uuid(),
            'adjustment' => ['kind' => 'customer', 'mode' => 'detach'],
        ])->assertOk();
        $this->assertNull($order->fresh()->customer_id);
        $this->getJson('/api/v1/public/qr/status')->assertOk()->assertJsonPath('data.dine_in.credential.identity_allowed', false);
        $this->round($product, ['phone' => '92001235'])->assertUnprocessable()->assertJsonPath('errors.0.code', 'qr_round_identity_already_set');
        $this->round($product)->assertCreated();
        $this->assertNull($order->fresh()->customer_id);
    }

    public function test_public_accounts_are_merchant_scoped_private_and_earned_matches_own_bill(): void
    {
        [$session, $product, $till] = $this->flow();
        $this->round($product, ['phone' => '92001234'])->assertCreated();
        $order = Order::sole();
        $rule = LoyaltyRule::create(['uuid' => Str::uuid(), 'company_id' => 100, 'name' => 'Own stamps', 'type' => 'visit_based', 'status' => 'active',
            'config_json' => ['min_order_value' => '0.001', 'stamps_required' => 5]]);
        LoyaltyAccount::create(['uuid' => Str::uuid(), 'company_id' => 100, 'customer_id' => $order->customer_id, 'loyalty_rule_id' => $rule->id,
            'point_balance' => 30, 'stamp_count' => 4]);
        [$otherSession, $otherProduct] = $this->flow(200, 20);
        $this->round($otherProduct, ['phone' => '92001234'])->assertCreated();
        $other = Order::where('company_id', 200)->sole();
        $foreignRule = LoyaltyRule::create(['uuid' => Str::uuid(), 'company_id' => 200, 'name' => 'Other points', 'type' => 'spend_based', 'status' => 'active', 'config_json' => []]);
        LoyaltyAccount::create(['uuid' => Str::uuid(), 'company_id' => 200, 'customer_id' => $other->customer_id, 'loyalty_rule_id' => $foreignRule->id, 'point_balance' => 900, 'stamp_count' => 0]);
        foreach ([[$session, $rule, 30, 4, 'stamps'], [$otherSession, $foreignRule, 900, 0, 'points']] as [$qs, $r, $p, $st, $kind]) {
            $this->headersFor($qs);
            $response = $this->getJson('/api/v1/public/qr/loyalty?customer_id='.$other->customer_id.'&company_id=200')->assertOk()
                ->assertJsonPath('data.accounts', [['rule_id' => $r->id, 'rule_name' => $r->name, 'kind' => $kind, 'points' => $p, 'stamps' => $st]]);
            foreach (['92001234', 'phone', 'plate', 'customer_id', 'transactions', 'client_secret'] as $private) {
                $this->assertStringNotContainsString($private, $response->getContent());
            }
        }
        $this->headersFor($session);
        $this->pay($till, $order);
        $this->getJson('/api/v1/public/qr/status')->assertOk()->assertJsonPath('data.loyalty_earned', ['points' => 0, 'stamps' => 1]);
        $this->assertSame(1, (int) LoyaltyTransaction::where('order_id', $order->id)->where('type', 'earn')->sum('stamps_delta'));
        $this->withHeaders(['X-QR-Client-Secret' => 'wrong'])->getJson('/api/v1/public/qr/loyalty')->assertNotFound()->assertJsonPath('errors.0.code', 'qr_session_not_found');
    }
}
