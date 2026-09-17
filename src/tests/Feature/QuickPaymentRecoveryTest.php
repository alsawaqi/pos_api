<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\PresentQrPendingOrderAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class QuickPaymentRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(?string $outcome = null, bool $claimed = true): array
    {
        $device = Device::factory()->paired('recovery-station')->create([
            'company_id' => 100, 'branch_id' => 10, 'device_type' => 'payment_station', 'status' => 'active',
        ]);
        $session = QrSession::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $device->id, 'token' => Str::random(64),
            'token_expires_at' => now()->addMinute(), 'client_secret_hash' => QrSession::hashClientSecret('customer-secret'),
            'status' => QrSession::STATUS_ORDERED, 'bound_at' => now(), 'expires_at' => now()->addHour(),
        ]);
        $order = Order::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'device_id' => $device->id,
            'qr_session_id' => $session->id, 'source' => Order::SOURCE_QR_WEB, 'order_type' => 'quick',
            'status' => Order::STATUS_AWAITING_PAYMENT, 'temp_reference' => 'T-TEST-001',
            'subtotal' => '0.300', 'discount_total' => '0.000', 'tax_total' => '0.015', 'grand_total' => '0.315',
            'opened_at' => now(), 'charge_outcome' => $outcome,
            'charge_claimed_at' => $claimed ? now() : null, 'charge_deadline_at' => $claimed ? now()->addMinutes(3) : null,
            'charge_device_id' => $claimed ? $device->id : null, 'charge_amount_baisas' => $claimed ? 315 : null,
            'charge_roundup_amount_baisas' => $claimed ? 0 : null,
        ]);
        $this->withHeaders(['X-QR-Session' => $session->uuid, 'X-QR-Client-Secret' => 'customer-secret']);

        return [$device, $session, $order->refresh()];
    }

    public function test_uncertain_result_is_visible_and_cannot_be_retried_or_sent_for_another_payment(): void
    {
        [, , $order] = $this->fixture('uncertain');
        $before = $order->getAttributes();
        $this->getJson('/api/v1/public/qr/payment-recovery')->assertOk()
            ->assertJsonPath('data.state', 'review_required')->assertJsonPath('data.can_retry', false)
            ->assertJsonPath('data.can_counter', false);
        foreach (['retry', 'counter'] as $action) {
            $this->postJson('/api/v1/public/qr/payment-recovery', ['action' => $action])->assertStatus(409);
            $this->assertSame($before, $order->fresh()->getAttributes());
        }
    }

    public function test_customer_retry_is_a_request_and_never_creates_a_charge_or_changes_charge_evidence(): void
    {
        [, , $order] = $this->fixture('declined');
        $before = $order->only(['charge_outcome', 'charge_device_id', 'charge_amount_baisas', 'charge_claimed_at', 'charge_deadline_at']);
        $this->postJson('/api/v1/public/qr/payment-recovery', ['action' => 'retry'])->assertOk()
            ->assertJsonPath('data.request.action', 'retry')->assertJsonPath('data.state', 'declined');
        $this->assertEquals($before, $order->fresh()->only(array_keys($before)));
        $this->assertDatabaseCount('pos_payments', 0);
    }

    public function test_confirmed_pre_payment_failure_can_be_sent_to_counter_idempotently(): void
    {
        [, , $order] = $this->fixture('cancelled');
        $this->postJson('/api/v1/public/qr/payment-recovery', ['action' => 'counter'])->assertOk()
            ->assertJsonPath('data.state', 'counter');
        $order->refresh();
        $this->assertSame(Order::STATUS_HELD, $order->status);
        $this->assertNull($order->charge_outcome);
        $this->assertNull($order->charge_roundup_amount_baisas);
        $presentation = app(PresentQrPendingOrderAction::class)->handle($order, QrSession::find($order->qr_session_id), null, now());
        $this->assertTrue($presentation['actions']['settle']);
        $this->assertSame('T-TEST-001', $order->temp_reference);
        $before = $order->getAttributes();
        $this->postJson('/api/v1/public/qr/payment-recovery', ['action' => 'counter'])->assertOk();
        $this->assertSame($before, $order->fresh()->getAttributes());
    }

    public function test_live_and_expired_unresolved_claims_refuse_both_money_recovery_actions(): void
    {
        [, , $order] = $this->fixture();
        foreach ([now()->addMinute(), now()->subMinute()] as $deadline) {
            $order->update(['charge_deadline_at' => $deadline]);
            $before = $order->fresh()->getAttributes();
            foreach (['retry', 'counter'] as $action) {
                $this->postJson('/api/v1/public/qr/payment-recovery', ['action' => $action])->assertStatus(409);
                $this->assertSame($before, $order->fresh()->getAttributes());
            }
        }
    }

    public function test_review_request_is_durable_without_clearing_uncertain_evidence(): void
    {
        [, , $order] = $this->fixture('uncertain');
        $this->postJson('/api/v1/public/qr/payment-recovery', ['action' => 'review'])->assertOk()
            ->assertJsonPath('data.request.action', 'review')->assertJsonPath('data.can_retry', false);
        $order->refresh();
        $this->assertSame('uncertain', $order->charge_outcome);
        $this->assertSame('awaiting_payment', $order->status);
        $this->assertNotNull($order->charge_claimed_at);
        $this->assertSame('review', $order->qr_recovery_request['action']);
    }

    public function test_station_can_read_only_its_bound_order_and_receive_customer_retry_request(): void
    {
        [$device, , $order] = $this->fixture('declined');
        $this->postJson('/api/v1/public/qr/payment-recovery', ['action' => 'retry'])->assertOk();
        $this->withToken('recovery-station')->getJson('/api/v1/device/qr/orders/'.$order->uuid.'/payment-recovery')
            ->assertOk()->assertJsonPath('data.request.action', 'retry');
        $other = Device::factory()->paired('other-station')->create([
            'company_id' => 100, 'branch_id' => 10, 'device_type' => 'payment_station', 'status' => 'active',
        ]);
        $this->app['auth']->forgetGuards();
        $this->withToken('other-station')->getJson('/api/v1/device/qr/orders/'.$order->uuid.'/payment-recovery')->assertNotFound();
        $device->update(['status' => 'inactive']);
        $this->app['auth']->forgetGuards();
        $this->withToken('recovery-station')->getJson('/api/v1/device/qr/orders/'.$order->uuid.'/payment-recovery')->assertUnauthorized();
        $this->assertSame('declined', $order->fresh()->charge_outcome);
    }

    public function test_requested_review_alerts_attended_staff_without_unlocking_payment(): void
    {
        [, , $order] = $this->fixture('uncertain');
        $till = Device::factory()->paired('recovery-till')->create([
            'company_id' => 100, 'branch_id' => 10, 'device_type' => 'fixed_pos', 'status' => 'active',
        ]);
        $this->postJson('/api/v1/public/qr/payment-recovery', ['action' => 'review'])->assertOk();
        $this->withToken('recovery-till')->getJson('/api/v1/device/order-attention')->assertOk()
            ->assertJsonPath('data.quick_order_keys', ['quick:'.$order->uuid]);
        $this->assertSame('uncertain', $order->fresh()->charge_outcome);
        $this->assertSame('awaiting_payment', $order->fresh()->status);
    }

    public function test_paid_and_incomplete_claims_never_offer_retry_or_counter(): void
    {
        [, , $order] = $this->fixture('declined');
        foreach ([['charge_device_id' => null], ['status' => 'paid']] as $changes) {
            $order->update($changes);
            $before = $order->fresh()->getAttributes();
            $this->getJson('/api/v1/public/qr/payment-recovery')->assertOk()
                ->assertJsonPath('data.can_retry', false)->assertJsonPath('data.can_counter', false);
            foreach (['retry', 'counter'] as $action) {
                $this->postJson('/api/v1/public/qr/payment-recovery', ['action' => $action])->assertConflict();
                $this->assertSame($before, $order->fresh()->getAttributes());
            }
        }
    }

    public function test_forged_session_secret_and_arbitrary_customer_payload_cannot_change_orders(): void
    {
        [, , $order] = $this->fixture('declined');
        $before = $order->getAttributes();
        $this->withHeader('X-QR-Client-Secret', 'wrong')->postJson('/api/v1/public/qr/payment-recovery', ['action' => 'counter'])
            ->assertNotFound()->assertExactJson([
                'data' => null,
                'errors' => [['code' => 'qr_session_not_found', 'message' => 'QR session was not found.']],
            ]);
        $this->assertSame($before, $order->fresh()->getAttributes());
        $this->withHeader('X-QR-Client-Secret', 'customer-secret')->postJson('/api/v1/public/qr/payment-recovery', ['action' => 'paid'])
            ->assertUnprocessable();
        $this->assertSame($before, $order->fresh()->getAttributes());
    }
}
