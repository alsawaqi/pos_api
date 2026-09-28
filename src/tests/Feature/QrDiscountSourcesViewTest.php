<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OrderDiscount;
use Tests\Support\QrPendingTestCase;

final class QrDiscountSourcesViewTest extends QrPendingTestCase
{
    public function test_real_device_route_preserves_each_discount_source_and_negative_reversal(): void
    {
        $order = $this->order(['discount_total' => '.750', 'grand_total' => '4.000']);
        foreach ([['order', 11, null, 'National day', 'percent', '.250'],
            ['line', 12, null, 'Product rule', 'percent', '.300'],
            ['offer', null, 13, 'Offer', 'fixed', '.150'],
            ['order', null, null, 'Manual', 'table_manual_fixed', '.100'],
            ['order', null, null, 'Manual', 'table_manual_reversal', '-.050']] as [$source, $discount, $offer, $name, $type, $amount]) {
            OrderDiscount::create(['company_id' => 100, 'branch_id' => 10, 'order_id' => $order->id, 'order_item_id' => $source === 'line' ? $order->items()->sole()->id : null, 'discount_id' => $discount, 'offer_id' => $offer,
                'name_snapshot' => $name, 'amount_type_snapshot' => $type, 'amount' => $amount, 'applied_at' => now()]);
        }
        $row = $this->getPending()->assertOk()->assertJsonPath('data.orders.0.grand_total_baisas', 4000)
            ->assertJsonPath('data.orders.0.discount_total_baisas', 750)
            ->assertJsonPath('data.orders.0.manual_discount_baisas', 50)->json('data.orders.0');
        $this->assertArrayHasKey('discount_sources', $row);
        $this->assertSame(['National day', 'Product rule', 'Offer', 'Manual', 'Manual'], array_column($row['discount_sources'], 'name'));
        $this->assertSame([250, 300, 150, 100, -50], array_column($row['discount_sources'], 'amount_baisas'));
        $this->assertSame(750, array_sum(array_column($row['discount_sources'], 'amount_baisas')));
        $this->assertSame([11, 12, null, null, null], array_column($row['discount_sources'], 'discount_id'));
        $this->assertSame([null, null, 13, null, null], array_column($row['discount_sources'], 'offer_id'));
        $this->assertSame([100, -50], array_column($row['discounts'], 'amount_baisas'));
        $this->assertSame('4.000', $order->fresh()->grand_total);

        // The same read-only breakdown is available to checkout, confirmed
        // history, and therefore the device receipt/reprint projections.
        $order->update(['status' => 'awaiting_payment', 'charge_outcome' => null,
            'charge_device_id' => $this->till->id, 'charge_claimed_at' => now(),
            'charge_deadline_at' => now()->addMinute(), 'charge_amount_baisas' => 4000]);
        $this->app['auth']->forgetGuards();
        $checkout = $this->withToken($this->till->device_token)->getJson('/api/v1/device/qr/orders/'.$order->uuid.'/checkout')
            ->assertOk()->assertJsonPath('data.order.discount_total_baisas', 750)->json('data.order');
        $this->assertSame($row['discount_sources'], $checkout['discount_sources']);
        $order->update(['status' => 'paid']);
        $this->app['auth']->forgetGuards();
        $history = $this->withToken($this->till->device_token)->getJson('/api/v1/device/orders/history')
            ->assertOk()->assertJsonPath('data.orders.0.status', 'paid')->assertJsonPath('data.orders.0.discount_total_baisas', 750)->json('data.orders.0');
        $this->assertSame($row['discount_sources'], $history['discount_sources']);
        $this->assertSame($row['discounts'], $history['discounts']);
    }
}
