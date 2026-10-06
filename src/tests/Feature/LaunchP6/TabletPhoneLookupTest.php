<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP6;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LaunchP6Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P6 tester call 8 — the phone lookup shows points only, never a name;
 * Omani E.164; 10 per minute per tablet; this merchant's balances only; an
 * unknown phone is "new" and the customer is created only at submit.
 * (Throttling stays ON in this class.)
 */
final class TabletPhoneLookupTest extends TestCase
{
    use LaunchP6Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p6Setup();
    }

    public function test_the_lookup_returns_points_and_redeemable_blocks_never_a_name_and_is_rate_limited_per_tablet(): void
    {
        $rule = $this->p6Rule();
        $stamps = (int) \DB::table('pos_loyalty_rules')->insertGetId(['uuid' => (string) \Str::uuid(), 'company_id' => 100,
            'name' => 'Free cake', 'type' => 'visit_based', 'config_json' => json_encode(['stamps_required' => 5, 'reward_type' => 'free_item']),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $customer = $this->p6Customer('+96891234567', 'Secret Name');
        $this->p6Account($customer, $rule, 250);
        \DB::table('pos_loyalty_accounts')->insert(['uuid' => (string) \Str::uuid(), 'company_id' => 100, 'customer_id' => $customer,
            'loyalty_rule_id' => $stamps, 'point_balance' => 0, 'stamp_count' => 7, 'created_at' => now(), 'updated_at' => now()]);
        // The same phone at another merchant has its own, invisible, balance.
        $foreign = $this->p6Customer('+96891234567', 'Other Name', 200);
        $this->p6Account($foreign, $this->p6Rule(200), 9999, 200);

        $response = $this->p6As($this->tablet, 'POST', '/api/v1/device/tablet/loyalty/lookup', ['phone' => '٩١٢٣ ٤٥٦٧'])->assertOk();
        $data = $response->json('data');

        $this->assertSame(['phone' => '+96891234567', 'customer' => 'existing'], ['phone' => $data['phone'], 'customer' => $data['customer']]);
        $this->assertSame([
            ['rule_id' => $rule, 'rule_name' => 'Coffee points', 'kind' => 'points', 'balance' => 250, 'redeemable' => true,
                'block_units' => 100, 'block_value_baisas' => 500, 'redeemable_blocks' => 2],
            ['rule_id' => $stamps, 'rule_name' => null, 'kind' => 'stamps', 'balance' => 7, 'redeemable' => false,
                'block_units' => null, 'block_value_baisas' => null, 'redeemable_blocks' => 0],
        ], $data['accounts']);
        $this->assertStringNotContainsString('Secret Name', $response->getContent());
        $this->assertStringNotContainsString('Other Name', $response->getContent());
        $this->assertStringNotContainsString('"name"', $response->getContent());

        $this->p6As($this->tablet, 'POST', '/api/v1/device/tablet/loyalty/lookup', ['phone' => '12345'])->assertStatus(422)
            ->assertJsonPath('errors.0.code', 'phone_invalid');
        // 10 per minute per tablet (3 used): the 11th call is refused.
        for ($i = 0; $i < 8; $i++) {
            $this->p6As($this->tablet, 'POST', '/api/v1/device/tablet/loyalty/lookup', ['phone' => '91234567'])->assertOk();
        }
        $this->p6As($this->tablet, 'POST', '/api/v1/device/tablet/loyalty/lookup', ['phone' => '91234567'])->assertStatus(429);
        // Another tablet has its own budget.
        $this->p6As($this->p6Device('mdev_p6_tab_b', 'customer_tablet'), 'POST', '/api/v1/device/tablet/loyalty/lookup', ['phone' => '91234567'])
            ->assertOk();
    }

    public function test_an_unknown_phone_is_new_and_the_customer_is_created_only_at_submit(): void
    {
        $this->p6As($this->tablet, 'POST', '/api/v1/device/tablet/loyalty/lookup', ['phone' => '99887766'])->assertOk()
            ->assertJsonPath('data.customer', 'new')->assertJsonPath('data.accounts', []);
        $this->assertSame(0, Customer::query()->count());

        $order = $this->p6Submit(['phone' => '+968 9988 7766'])->assertCreated()->json('data');

        $customer = Customer::query()->sole();
        $this->assertSame([100, '+96899887766'], [(int) $customer->company_id, $customer->phone]);
        $this->assertSame((int) $customer->id, (int) $this->p6Order($order['order_uuid'])->customer_id);
        $this->assertSame((int) $customer->id, (int) $this->p6Row($order['tablet_order_uuid'])->customer_id);
        // The same phone again links the same customer.
        $this->p6Submit(['phone' => '99887766'])->assertCreated();
        $this->assertSame(1, Customer::query()->count());
        $this->p6Submit(['phone' => '0123'])->assertStatus(422)->assertJsonPath('errors.0.code', 'phone_invalid');
    }
}
