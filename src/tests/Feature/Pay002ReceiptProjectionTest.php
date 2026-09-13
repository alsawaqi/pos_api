<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PosStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class Pay002ReceiptProjectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_payment_options_and_recovery_expose_masked_original_receipt_and_server_approver(): void
    {
        $device = Device::factory()->withSoftPos()->paired('p2-receipt')->create([
            'company_id' => 100, 'branch_id' => 10, 'device_type' => 'fixed_pos', 'terminal_id' => 'T1',
        ]);
        PosStaff::create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'name' => 'Approving manager', 'position' => 'manager', 'status' => 'active',
            'pin_hash' => Hash::make('123456'),
        ]);
        $order = Order::create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $device->id, 'order_type' => 'quick', 'source' => 'main_pos',
            'status' => 'paid', 'subtotal' => 5, 'discount_total' => 0, 'tax_total' => 0,
            'grand_total' => 5, 'opened_at' => now(), 'receipt_number' => 'R-0042',
        ]);
        $payment = Payment::create([
            'uuid' => (string) Str::uuid(), 'order_id' => $order->id, 'device_id' => $device->id,
            'method' => 'card', 'status' => 'success', 'direction' => 'sale', 'amount' => 5,
            'bank_id' => $device->bank_id, 'terminal_id' => 'T1', 'softpos_provider' => 'mosambee_dhofar',
            'softpos_package' => 'com.mosambee.dhofar.softpos', 'softpos_transaction_id' => 'ORIGINAL',
            'softpos_auth_code' => 'AUTH', 'captured_at' => now(),
            'bank_response' => ['receiptResponse' => json_encode(['cardNumber' => '433662XXXXXX5819'])],
        ]);
        $this->withToken('p2-receipt')->getJson('/api/v1/device/orders/'.$order->uuid.'/payments')
            ->assertOk()->assertJsonPath('data.payments.0.original_receipt_number', 'R-0042')
            ->assertJsonPath('data.payments.0.original_masked_card', '433662XXXXXX5819')
            ->assertJsonPath('data.payments.0.original_auth_code', 'AUTH');

        app('auth')->forgetGuards();
        $this->postJson('/api/v1/device/payments/'.$payment->uuid.'/reversals', [
            'kind' => 'refund', 'manager_pin' => '123456', 'client_request_id' => (string) Str::uuid(),
            'reason_code' => 'RETURN', 'custom_amount_baisas' => 1000,
        ])->assertOk()->assertJsonPath('data.approver_name', 'Approving manager');

        app('auth')->forgetGuards();
        $this->getJson('/api/v1/device/payments/reversals')->assertOk()
            ->assertJsonPath('data.reversals.0.order_uuid', $order->uuid)
            ->assertJsonPath('data.reversals.0.original_receipt_number', 'R-0042')
            ->assertJsonPath('data.reversals.0.original_masked_card', '433662XXXXXX5819')
            ->assertJsonPath('data.reversals.0.original_auth_code', 'AUTH')
            ->assertJsonPath('data.reversals.0.approver_name', 'Approving manager');

        $payment->update(['bank_response' => ['cardNumber' => '4336621234565819']]);
        app('auth')->forgetGuards();
        $this->getJson('/api/v1/device/payments/reversals')->assertOk()
            ->assertJsonPath('data.reversals.0.original_masked_card', null)
            ->assertDontSee('4336621234565819');
        $this->assertDatabaseCount('pos_payments', 1);
        $this->assertDatabaseCount('pos_payment_reversals', 1);
    }
}
