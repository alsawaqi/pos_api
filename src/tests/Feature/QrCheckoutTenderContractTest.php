<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Order;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\QrPendingTestCase;

/** Pin the existing handler; checkout integration must not change its rules. */
final class QrCheckoutTenderContractTest extends QrPendingTestCase
{
    public static function tenders(): iterable
    {
        foreach (['fixed_pos', 'handheld'] as $type) {
            foreach (['cash', 'card', 'bank_pos', 'gift'] as $method) {
                yield "$type/$method" => [$type, [['method' => $method, 'amount_baisas' => 4750, 'status' => 'success']]];
            }
            yield "$type/cash-card" => [$type, [
                ['method' => 'cash', 'amount_baisas' => 1001, 'status' => 'success'],
                ['method' => 'card', 'amount_baisas' => 3749, 'status' => 'success'],
            ]];
            yield "$type/cash-bank" => [$type, [
                ['method' => 'cash', 'amount_baisas' => 2001, 'status' => 'success'],
                ['method' => 'bank_pos', 'amount_baisas' => 2749, 'status' => 'success'],
            ]];
        }
    }

    private function event(Order $order, array $payments): array
    {
        return ['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => now()->toIso8601String(), 'payload' => [
            'order_uuid' => $order->uuid, 'paid_at' => now()->toIso8601String(), 'payments' => $payments,
        ]];
    }

    private function push(Device $device, array $event): TestResponse
    {
        return $this->postAs($device, '/api/v1/device/sync/push', ['events' => [$event]]);
    }

    #[DataProvider('tenders')]
    public function test_normal_tenders_use_one_claim_one_bill_and_one_idempotent_payment(string $type, array $payments): void
    {
        $device = $type === 'fixed_pos' ? $this->till : $this->device($type);
        $order = $this->order([], 'timestamp_expired');
        $original = $this->raw($order);
        $this->claim($order, $device)->assertOk()->assertJsonPath('data.charge_amount_baisas', 4750);
        $this->claim($order, $device)->assertOk()->assertJsonPath('data.already_claimed_by_this_device', true);
        $this->claim($order, $this->device('fixed_pos'))->assertConflict()->assertJsonPath('errors.0.code', 'charge_already_claimed');
        $event = $this->event($order, $payments);
        $this->push($device, $event)->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame($original['grand_total'], $this->raw($order)['grand_total']);
        $this->assertSame($original['customer_id'], $this->raw($order)['customer_id']);
        $this->assertSame($original['temp_reference'], $this->raw($order)['temp_reference']);
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseCount('pos_payments', count($payments));
        $this->assertSame(4750, DB::table('pos_payments')->get()->sum(fn ($row) => Money::toBaisas($row->amount)));
        $rows = DB::table('pos_payments')->orderBy('id')->get()->toArray();
        $this->push($device, $event)->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertEquals($rows, DB::table('pos_payments')->orderBy('id')->get()->toArray());
        $second = $this->event($order, $payments);
        $this->push($device, $second)->assertOk()->assertJsonPath('data.results.0.status', 'failed');
        $this->assertEquals($rows, DB::table('pos_payments')->orderBy('id')->get()->toArray());
    }

    public static function refusals(): iterable
    {
        yield 'short-one-baisa' => ['cash', 4749, 'success', 'amount'];
        yield 'over-one-baisa' => ['bank_pos', 4751, 'success', 'amount'];
        yield 'ambiguous-card' => ['card', 4750, 'pending_reconciliation', 'amount'];
        yield 'another-device' => ['bank_pos', 4750, 'success', 'other'];
        yield 'expired-claim' => ['cash', 4750, 'success', 'expired'];
        yield 'uncertain-outcome' => ['card', 4750, 'success', 'uncertain'];
    }

    #[DataProvider('refusals')]
    public function test_invalid_or_unsafe_tender_cannot_change_the_bill(string $method, int $amount, string $status, string $case): void
    {
        $order = $this->order([], 'timestamp_expired');
        $this->claim($order)->assertOk();
        if ($case === 'expired') {
            $order->update(['charge_deadline_at' => now()->subSecond()]);
        } elseif ($case === 'uncertain') {
            $order->update(['charge_outcome' => 'uncertain']);
        }
        $before = $this->snapshot();
        $this->push($case === 'other' ? $this->device('handheld') : $this->till, $this->event($order, [
            ['method' => $method, 'amount_baisas' => $amount, 'status' => $status],
        ]))->assertOk()->assertJsonPath('data.results.0.status', 'failed');
        $this->assertSame($before, $this->snapshot());
        $this->assertDatabaseCount('pos_payments', 0);
    }
}
