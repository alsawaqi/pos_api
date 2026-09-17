<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\ListStationQrAwaitingOrdersAction;
use App\Actions\Qr\PresentQrPendingOrderAction;
use Tests\Support\QrPendingTestCase;

final class QrQuickNavigationTest extends QrPendingTestCase
{
    public function test_back_releases_to_editable_order_without_station_dispatch_and_replays(): void
    {
        $order = $this->order();
        $original = $this->raw($order);
        $claim = $this->claim($order)->assertOk()->json('data');
        $payload = array_intersect_key($claim, array_flip(['order_uuid', 'charge_claimed_at', 'charge_deadline_at']));
        $this->postAs($this->till, '/api/v1/device/qr/cancel-settlement', $payload)->assertOk()->assertJsonPath('data.status', 'held');
        $order->refresh();
        $this->assertSame($this->withoutCharge($original), $this->withoutCharge($this->raw($order)));
        $this->assertTrue(app(PresentQrPendingOrderAction::class)->hasNoChargeProvenance($order));
        $this->assertSame([], app(ListStationQrAwaitingOrdersAction::class)->handle($this->station));
        $this->getPending()->assertOk()->assertJsonPath('data.orders.0.actions.settle', true);
        $before = $this->snapshot();
        $this->postAs($this->till, '/api/v1/device/qr/cancel-settlement', $payload)->assertOk();
        $this->assertSame($before, $this->snapshot());
        $this->claim($order)->assertOk();
        $this->assertDatabaseCount('pos_payments', 0);
    }

    public function test_back_cannot_clear_another_holder_or_uncertain_result(): void
    {
        $order = $this->order();
        $claim = $this->claim($order)->assertOk()->json('data');
        $payload = array_intersect_key($claim, array_flip(['order_uuid', 'charge_claimed_at', 'charge_deadline_at']));
        $before = $this->snapshot();
        $this->postAs($this->device('handheld'), '/api/v1/device/qr/cancel-settlement', $payload)->assertConflict();
        $this->assertSame($before, $this->snapshot());
        $order->update(['charge_outcome' => 'uncertain']);
        $before = $this->snapshot();
        $this->postAs($this->till, '/api/v1/device/qr/cancel-settlement', $payload)->assertConflict();
        $this->assertSame($before, $this->snapshot());
    }

    public function test_back_cannot_cancel_a_later_reservation(): void
    {
        $order = $this->order();
        $claim = $this->claim($order)->assertOk()->json('data');
        $payload = array_intersect_key($claim, array_flip(['order_uuid', 'charge_claimed_at', 'charge_deadline_at']));
        $payload['charge_deadline_at'] = now()->addDay()->toIso8601String();
        $before = $this->snapshot();
        $this->postAs($this->till, '/api/v1/device/qr/cancel-settlement', $payload)->assertConflict();
        $this->assertSame($before, $this->snapshot());
    }
}
