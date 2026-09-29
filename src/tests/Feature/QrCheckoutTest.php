<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\QrChargeException;
use App\Actions\Qr\ReadQrCheckoutAction;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\QrPendingTestCase;

final class QrCheckoutTest extends QrPendingTestCase
{
    private function checkout(Order $order, ?Device $device = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken((string) ($device ?? $this->till)->plainTextToken)
            ->getJson('/api/v1/device/qr/orders/'.$order->uuid.'/checkout');
    }

    public static function devicesAndSessions(): iterable
    {
        foreach (['fixed_pos', 'handheld'] as $device) {
            foreach (['live', 'timestamp_expired', 'explicit_expired', 'closed', 'missing'] as $session) {
                yield "$device/$session" => [$device, $session];
            }
        }
    }

    #[DataProvider('devicesAndSessions')]
    public function test_read_only_snapshot_uses_same_bill_frozen_money_and_private_customer(string $type, string $session): void
    {
        $device = $type === 'fixed_pos' ? $this->till : $this->device($type);
        $order = $this->order([], $session);
        $claim = $this->claim($order, $device)->assertOk()->json('data');
        $before = $this->snapshot();
        $customer = Customer::findOrFail($order->customer_id);
        $response = $this->checkout($order, $device)->assertOk()
            ->assertJsonPath('data.order.uuid', $order->uuid)
            ->assertJsonPath('data.order.grand_total_baisas', 4750)
            ->assertJsonPath('data.order.temp_reference', 'T-0908-001')
            ->assertJsonPath('data.order.items.0.product_name', 'Synthetic tea')
            ->assertJsonPath('data.customer', ['id' => $customer->id, 'name' => $customer->name, 'phone' => $customer->phone])
            ->assertJsonPath('data.claim', array_intersect_key($claim, array_flip([
                'order_uuid', 'charge_amount_baisas', 'charge_claimed_at', 'charge_deadline_at',
            ])))
            ->assertJsonMissingPath('data.order.charge_device_id')
            ->assertJsonMissingPath('data.order.qr_session_id')
            ->assertJsonMissingPath('data.customer.wallet_balance');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertSame($before, $this->snapshot());
        $this->assertSame($response->json(), $this->checkout($order, $device)->assertOk()->json());
        $this->assertSame($before, $this->snapshot());
        $this->getPending($device)->assertOk()->assertJsonPath('data.orders.0.phone_tail', '5555')
            ->assertJsonMissingPath('data.orders.0.customer')->assertJsonMissingPath('data.orders.0.phone');
    }

    public function test_customer_read_is_company_scoped_and_null_safe(): void
    {
        $other = Customer::create(['uuid' => (string) Str::uuid(), 'company_id' => 999, 'name' => 'Other tenant', 'phone' => 'synthetic-other', 'wallet_balance' => 0]);
        $order = $this->order(['customer_id' => $other->id]);
        $this->claim($order)->assertOk();
        $this->checkout($order)->assertOk()->assertJsonPath('data.customer', null);
        $order->update(['customer_id' => null]);
        $this->checkout($order)->assertOk()->assertJsonPath('data.customer', null);
    }

    public function test_deleted_customer_preserves_existing_bill_attribution(): void
    {
        $order = $this->order();
        $customer = Customer::findOrFail($order->customer_id);
        $customer->delete();
        $this->claim($order)->assertOk();
        $this->checkout($order)->assertOk()->assertJsonPath('data.customer.id', $customer->id);
        $this->assertTrue(Customer::withTrashed()->findOrFail($customer->id)->trashed());
    }

    public static function invalidClaims(): iterable
    {
        yield 'unclaimed' => [['status' => 'held', 'charge_claimed_at' => null]];
        yield 'expired' => [['charge_deadline_at' => '2026-09-08 11:59:59']];
        yield 'deadline-boundary' => [['charge_deadline_at' => '2026-09-08 12:00:00']];
        yield 'no-deadline' => [['charge_deadline_at' => null]];
        yield 'no-holder' => [['charge_device_id' => null]];
        yield 'no-amount' => [['charge_amount_baisas' => null]];
        yield 'amount-changed' => [['grand_total' => '5.000']];
        yield 'roundup' => [['charge_roundup_amount_baisas' => 1]];
        foreach (['uncertain', 'declined', 'cancelled', 'lapsed', 'approved'] as $outcome) {
            yield $outcome => [['charge_outcome' => $outcome]];
        }
        foreach (['paid', 'void', 'open'] as $status) {
            yield $status => [['status' => $status]];
        }
    }

    #[DataProvider('invalidClaims')]
    public function test_checkout_cannot_acquire_repair_or_extend_a_claim(array $changes): void
    {
        $order = $this->order();
        $this->claim($order)->assertOk();
        $order->update($changes);
        $before = $this->snapshot();
        $this->checkout($order)->assertConflict()->assertJsonPath('errors.0.code', 'qr_checkout_claim_required');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_other_holder_and_station_cannot_read_private_checkout(): void
    {
        $order = $this->order();
        $this->claim($order)->assertOk();
        $before = $this->snapshot();
        $this->checkout($order, $this->device('handheld'))->assertConflict()->assertJsonPath('errors.0.code', 'qr_checkout_claim_required');
        $this->checkout($order, $this->station)->assertConflict()->assertJsonPath('errors.0.code', 'device_not_attended');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_admission_precedes_lookup_and_requires_authentication(): void
    {
        $order = $this->order();
        $order->uuid = (string) Str::uuid();
        $this->checkout($order, $this->station)->assertConflict()->assertJsonPath('errors.0.code', 'device_not_attended');
        foreach ([['status' => 'inactive'], ['branch_id' => null], ['company_id' => null]] as $attributes) {
            $device = $this->device('fixed_pos');
            $device->forceFill($attributes);
            try {
                app(ReadQrCheckoutAction::class)->handle($device, $order->uuid);
                $this->fail('Device admission must precede order lookup.');
            } catch (QrChargeException $error) {
                $this->assertSame('device_not_attended', $error->codeName);
            }
        }
        $this->app['auth']->forgetGuards();
        $this->withToken('invalid-synthetic-token')->getJson('/api/v1/device/qr/orders/'.$order->uuid.'/checkout')->assertUnauthorized();
        $this->assertContains('throttle:qr-table-device-read', Route::getRoutes()->getByName('device.qr.checkout')->gatherMiddleware());
    }

    public function test_foreign_and_non_qr_orders_are_not_found(): void
    {
        $order = $this->order();
        $this->claim($order)->assertOk();
        $otherCompany = $this->device('fixed_pos');
        $otherCompany->forceFill(['company_id' => 999])->save();
        DB::table('pos_companies')->insertOrIgnore(['id' => 999, 'name' => 'Other', 'status' => 'active']);
        $otherCompany->issueCredential();
        foreach ([$this->device('fixed_pos', 20), $otherCompany] as $device) {
            $this->checkout($order, $device)->assertNotFound();
        }
        $order->update(['source' => 'main_pos']);
        $this->checkout($order)->assertNotFound();
    }

    public function test_expiry_between_read_and_tender_remains_a_replay_refusal(): void
    {
        $order = $this->order([], 'timestamp_expired');
        $this->claim($order)->assertOk();
        $this->checkout($order)->assertOk();
        $this->travel(301)->seconds();
        $before = $this->snapshot();
        $this->claim($order)->assertConflict()->assertJsonPath('errors.0.code', 'qr_charge_recovery_required');
        $this->checkout($order)->assertConflict();
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('pos_payments', 0);
    }

    public function test_table_bill_uses_the_same_checkout_snapshot_without_session_write(): void
    {
        // This test pins presentation under an existing claim. Admission and
        // pending-round rejection remain in the unchanged claim action tests.
        DB::table('pos_floors')->insert(['id' => 900, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'name' => 'Synthetic floor', 'status' => 'active', 'display_order' => 0]);
        DB::table('pos_tables')->insert(['id' => 900, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'floor_id' => 900, 'label' => 'T1', 'seats' => 4, 'shape' => 'square', 'status' => 'active', 'display_order' => 0]);
        $order = $this->order(['order_type' => 'dine_in', 'table_id' => 900, 'status' => 'awaiting_payment'] + $this->charge('live_claim'));
        $order->update(['charge_device_id' => $this->till->id]);
        QrSession::whereKey($order->qr_session_id)->update(['table_id' => 900]);
        $before = $this->snapshot();
        $this->checkout($order)->assertOk()->assertJsonPath('data.order.order_type', 'dine_in')->assertJsonPath('data.order.table_id', 900);
        $this->assertSame($before, $this->snapshot());
    }
}
