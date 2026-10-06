<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP6;

use App\Models\Customer;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\TabletOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP6Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P6 Part A item 10 — tenancy: a tablet of merchant X can never read
 * or write merchant Y's tables, customers, products or orders, and Y's staff
 * devices (or X's other branch) never see or touch X's tablet orders.
 */
final class TabletTenancyTest extends TestCase
{
    use LaunchP6Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p6Setup();
    }

    public function test_a_tablet_of_merchant_x_never_reads_or_writes_merchant_y_tables_customers_products_or_orders(): void
    {
        $yTablet = $this->p6Device('mdev_p6_y_tablet', 'customer_tablet', 200, 20);
        $yTill = $this->p6Device('mdev_p6_y_till', 'fixed_pos', 200, 20);
        $xOtherBranchTill = $this->p6Device('mdev_p6_x11_till', 'fixed_pos', 100, 11);
        $this->p5Staff(20, 'manager', '200020', overrides: ['company_id' => 200, 'branch_id' => 20]);
        $this->p5Staff(11, 'manager', '110011', overrides: ['branch_id' => 11]);
        $yProduct = $this->p4Product('Y tea', '0.700', ['company_id' => 200]);
        $yTable = $this->seatingTable('Y table', 20, 200);
        $yRule = $this->p6Rule(200);
        $yCustomer = $this->p6Customer('+96891234567', 'Y customer', 200);
        $this->p6Account($yCustomer, $yRule, 900, 200);

        // Menu, quote and submit never reach Y's catalogue.
        $menu = $this->p6As($this->tablet, 'GET', '/api/v1/device/tablet/menu?order_type=quick')->assertOk()->json('data.products');
        $this->assertNotContains($yProduct, array_column($menu, 'id'));
        $this->p6Submit(['lines' => [$this->p6Line($yProduct)]])->assertStatus(409)->assertJsonPath('errors.0.code', 'tablet_lines_unavailable')
            ->assertJsonPath('data.lines.0.product_id', $yProduct);
        $this->p6As($this->tablet, 'POST', '/api/v1/device/tablet/quote', ['order_type' => 'quick', 'lines' => [$this->p6Line($yProduct)]])
            ->assertStatus(422);
        // Tables, customers and rewards of Y.
        $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => $yTable->uuid])->assertNotFound();
        $this->assertStringNotContainsString($yTable->uuid, $this->p6As($this->tablet, 'GET', '/api/v1/device/tablet/bootstrap')->getContent());
        $this->p6As($this->tablet, 'POST', '/api/v1/device/tablet/loyalty/lookup', ['phone' => '91234567'])->assertOk()
            ->assertJsonPath('data.customer', 'new')->assertJsonPath('data.accounts', []);
        $this->p6Submit(['phone' => '91234567', 'payment' => 'points', 'redeem_request' => ['rule_id' => $yRule, 'blocks' => 1]])
            ->assertStatus(409)->assertJsonPath('errors.0.code', 'redeem_not_available');

        // X's order; the same phone becomes X's own customer, never Y's.
        $key = (string) Str::uuid();
        $x = $this->p6Submit(['client_uuid' => $key, 'phone' => '91234567'])->assertCreated()->json('data');
        $xOrder = $this->p6Order($x['order_uuid']);
        $this->assertSame([100, 10], [(int) $xOrder->company_id, (int) $xOrder->branch_id]);
        $this->assertSame(100, (int) Customer::query()->whereKey($xOrder->customer_id)->value('company_id'));
        $this->assertSame('Y customer', Customer::query()->whereKey($yCustomer)->value('name'));
        // Y's tablet re-using X's key gets its own order in Y.
        $this->p6Submit(['client_uuid' => $key, 'lines' => []], $yTablet)->assertStatus(422);
        $yOrder = $this->p6Submit(['client_uuid' => $key, 'lines' => [$this->p6Line($yProduct)]], $yTablet)->assertCreated()->json('data');
        $this->assertNotSame($x['order_uuid'], $yOrder['order_uuid']);
        $this->assertSame(200, (int) $this->p6Order($yOrder['order_uuid'])->company_id);

        // Y's staff (and X's other branch) never see or touch X's tablet order.
        foreach ([[$yTill, 20], [$xOtherBranchTill, 11]] as [$device, $staff]) {
            $listed = $this->p6Staff($device, $staff, 'GET', '/api/v1/device/tablet-orders')->assertOk()->json('data.orders');
            $this->assertNotContains($x['tablet_order_uuid'], array_column($listed, 'tablet_order_uuid'));
            foreach (['take', 'send-to-kitchen', 'redeem/reject'] as $action) {
                $this->p6Staff($device, $staff, 'POST', "/api/v1/device/tablet-orders/{$x['tablet_order_uuid']}/{$action}")->assertNotFound();
            }
            $this->p6Staff($device, $staff, 'POST', "/api/v1/device/tablet-orders/{$x['tablet_order_uuid']}/redeem/approve",
                ['client_request_id' => (string) Str::uuid()])->assertNotFound();
            $this->assertNotContains('tablet:'.$x['tablet_order_uuid'], $this->p6As($device, 'GET', '/api/v1/device/order-attention', [],
                ['X-Pos-Capabilities' => 'tablet-orders'])->assertOk()->json('data.tablet_order_keys'));
        }
        // X's staff token is X's device's only.
        $this->p6As($yTill, 'GET', '/api/v1/device/tablet-orders', [], ['X-Staff-Token' => $this->p5StaffToken($this->till, 7)])
            ->assertForbidden()->assertJsonPath('errors.0.code', 'staff_unverified');
        // A tablet may not use the staff routes at all.
        $this->p6Staff($this->tablet, 7, 'GET', '/api/v1/device/tablet-orders')->assertForbidden()
            ->assertJsonPath('errors.0.code', 'device_not_allowed_for_tablet');

        // The kitchen round of X's sent order is X's branch's only.
        $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tablet-orders/{$x['tablet_order_uuid']}/send-to-kitchen")->assertOk();
        $round = QrOrderRound::query()->where('order_id', $xOrder->id)->sole();
        $this->p6As($yTill, 'POST', '/api/v1/device/kitchen/claim-print', ['ticket_key' => 'round:'.$round->id])->assertNotFound();
        $this->assertSame([], $this->p6As($yTill, 'GET', '/api/v1/device/qr/accepted-rounds', [], ['X-Pos-Capabilities' => 'tablet-orders'])
            ->json('data.rounds'));
        // Y's till cannot pay X's order.
        $this->p6PayCash($yTill, $x['order_uuid'], 2000, 20)->assertOk()->assertJsonPath('data.results.0.status', 'failed');
        $this->assertSame(Order::STATUS_HELD, $xOrder->fresh()->status);
        $this->assertSame(2, TabletOrder::query()->count());
        $this->assertSame(0, DB::table('pos_tablet_order_events')->where('company_id', 200)->whereIn('event_type', ['taken', 'sent_to_kitchen'])->count());
    }
}
