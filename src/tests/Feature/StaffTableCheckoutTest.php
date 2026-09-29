<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class StaffTableCheckoutTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-12 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    private function postAs(Device $device, string $path, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($device->plainTextToken)->postJson('/api/v1/device/'.$path, $data);
    }

    private function readAs(Device $device, string $path): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($device->plainTextToken)->getJson('/api/v1/device/'.$path);
    }

    private function fixture(string $type = 'fixed_pos'): array
    {
        $device = $this->seatingDevice($type);
        $table = $this->seatingTable('Staff T1');
        $seat = $this->seatingRow($table, ['opened_by_device_id' => $device->id]);
        $product = $this->seatingProduct();
        $eventId = (string) Str::uuid();
        $event = ['client_event_id' => $eventId, 'event_type' => 'table.session.round', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['seating_key' => $seat->client_request_id, 'table_id' => $table->id,
                'queued_offline' => false, 'client_request_id' => $eventId, 'submitted_at' => now()->toIso8601String(),
                'lines' => [['product_id' => $product->id, 'qty' => 2, 'addon_ids' => [], 'notes' => 'Staff original']]]];
        $this->postAs($device, 'sync/push', ['events' => [$event]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        return [$device, $table, $seat->refresh(), Order::sole(), $eventId];
    }

    private function claim(Device $device, Order $order): TestResponse
    {
        return $this->postAs($device, 'qr/claim-settlement', ['order_uuid' => $order->uuid]);
    }

    private function rows(): array
    {
        $rows = [];
        foreach (['pos_orders', 'pos_order_items', 'pos_order_item_addons', 'pos_order_comps', 'pos_qr_sessions',
            'pos_qr_order_rounds', 'pos_table_sessions', 'pos_table_session_events', 'pos_payments'] as $table) {
            $values = DB::table($table)->get()->map(fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($values);
            $rows[$table] = $values;
        }

        return $rows;
    }

    public static function tenders(): iterable
    {
        foreach (['fixed_pos', 'handheld'] as $type) {
            foreach (['cash', 'card', 'bank_pos', 'gift', 'split'] as $method) {
                yield "$type/$method" => [$type, $method];
            }
        }
    }

    #[DataProvider('tenders')]
    public function test_staff_bill_uses_frozen_claim_normal_tenders_same_bill_and_exact_replay(string $type, string $method): void
    {
        [$device, $table, $seat, $order] = $this->fixture($type);
        $identity = $order->only(['uuid', 'source', 'device_id', 'table_id', 'table_session_id', 'temp_reference', 'grand_total']);
        $items = DB::table('pos_order_items')->get()->toJson();
        $this->readAs($device, 'tables/'.$table->id.'/detail')->assertOk()->assertJsonPath('data.bill.checkout_policy', 'staff_table_claim_v1');
        $this->claim($device, $order)->assertOk()->assertJsonPath('data.charge_amount_baisas', 2000);
        $this->assertSame('billing', $seat->refresh()->status);
        $this->assertNull($order->refresh()->qr_session_id);
        $before = $this->rows();
        $this->claim($device, $order)->assertOk()->assertJsonPath('data.already_claimed_by_this_device', true);
        $this->assertSame($before, $this->rows());
        $this->readAs($device, 'qr/orders/'.$order->uuid.'/checkout')->assertOk()
            ->assertJsonPath('data.order.checkout_policy', 'staff_table_claim_v1')
            ->assertJsonPath('data.order.source', $type === 'handheld' ? 'handheld' : 'main_pos')
            ->assertJsonPath('data.claim.charge_amount_baisas', 2000);
        $this->assertSame($before, $this->rows());
        $payments = $method === 'split'
            ? [['method' => 'cash', 'amount_baisas' => 999], ['method' => 'bank_pos', 'amount_baisas' => 1001]]
            : [['method' => $method, 'amount_baisas' => 2000]];
        $event = ['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $order->uuid, 'paid_at' => now()->toIso8601String(), 'payments' => $payments]];
        $this->postAs($device, 'sync/push', ['events' => [$event]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame('paid', $order->refresh()->status);
        $this->assertSame($identity, $order->only(array_keys($identity)));
        $this->assertSame('closed', $seat->refresh()->status);
        $this->assertSame('paid', $seat->close_reason);
        $this->assertSame($items, DB::table('pos_order_items')->get()->toJson());
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseCount('pos_payments', count($payments));
        $paid = $this->rows();
        $this->postAs($device, 'sync/push', ['events' => [$event]])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame($paid, $this->rows());
        $event['client_event_id'] = (string) Str::uuid();
        $this->postAs($device, 'sync/push', ['events' => [$event]])->assertOk()->assertJsonPath('data.results.0.status', 'failed');
        $this->assertSame($paid, $this->rows());
    }

    public static function refusalCases(): array
    {
        return array_map(fn ($case) => [$case], ['other_owner', 'station', 'wrong_branch', 'pending_round', 'bill_less_pending', 'money_drift',
            'closed_seating', 'wrong_link', 'paid', 'residue', 'transferred', 'archived_table', 'unclaimed_read']);
    }

    #[DataProvider('refusalCases')]
    public function test_new_claim_and_read_refusals_preserve_every_domain_row(string $case): void
    {
        [$device, $table, $seat, $order] = $this->fixture();
        $caller = match ($case) {
            'other_owner' => $this->seatingDevice('handheld'),
            'station' => $this->seatingDevice('payment_station'),
            'wrong_branch' => $this->seatingDevice('handheld', 20),
            default => $device,
        };
        match ($case) {
            'pending_round' => $this->seatingRound($seat, $order, ['round_no' => 2, 'status' => 'pending_confirmation']),
            'bill_less_pending' => $this->seatingRound($seat, null, ['round_no' => 2, 'status' => 'pending_confirmation']),
            'money_drift' => $order->update(['grand_total' => '2.001']),
            'closed_seating' => $seat->update(['status' => 'closed', 'closed_at' => now()]),
            'wrong_link' => $seat->update(['order_id' => null]),
            'paid' => $order->update(['status' => 'paid']),
            'residue' => $order->update(['charge_amount_baisas' => 2000]),
            'transferred' => $order->update(['transferred_at' => now()]),
            'archived_table' => $table->delete(),
            default => null,
        };
        $before = $this->rows();
        $response = $case === 'unclaimed_read' ? $this->readAs($caller, 'qr/orders/'.$order->uuid.'/checkout') : $this->claim($caller, $order);
        $this->assertContains($response->status(), [403, 404, 409]);
        $this->assertSame($before, $this->rows());
    }

    public static function chargeCases(): array
    {
        return array_map(fn ($case) => [$case], ['other_device', 'expired', 'uncertain', 'approved', 'amount_changed', 'claim_missing']);
    }

    #[DataProvider('chargeCases')]
    public function test_unsafe_replay_cannot_change_or_collect_again(string $case): void
    {
        [$device, , , $order] = $this->fixture();
        $this->claim($device, $order)->assertOk();
        $caller = $case === 'other_device' ? $this->seatingDevice('handheld') : $device;
        $order->refresh();
        match ($case) {
            'expired' => $order->update(['charge_deadline_at' => now()->subSecond()]),
            'uncertain', 'approved' => $order->update(['charge_outcome' => $case]),
            'amount_changed' => $order->update(['grand_total' => '2.001']),
            'claim_missing' => $order->update(['charge_amount_baisas' => null]),
            default => null,
        };
        $before = $this->rows();
        $this->claim($caller, $order)->assertConflict();
        $this->assertSame($before, $this->rows());
    }

    public static function outcomes(): array
    {
        return [['cancelled'], ['declined'], ['uncertain']];
    }

    #[DataProvider('outcomes')]
    public function test_release_reopen_and_ambiguous_fallback_keep_the_existing_safety_contract(string $outcome): void
    {
        [$device, , $seat, $order] = $this->fixture();
        $this->claim($device, $order)->assertOk();
        $this->postAs($device, 'qr/release-charge', ['order_uuid' => $order->uuid, 'outcome' => $outcome])->assertOk();
        $before = $this->rows();
        $response = $this->postAs($device, 'qr/reopen-payment', ['order_uuid' => $order->uuid]);
        if ($outcome === 'uncertain') {
            $response->assertConflict();
            $this->assertSame($before, $this->rows());
            $charge = $order->refresh()->only(['charge_device_id', 'charge_amount_baisas', 'charge_claimed_at', 'charge_deadline_at', 'charge_outcome']);
            $this->postAs($device, 'qr/fallback-to-counter', ['order_uuid' => $order->uuid])->assertOk();
            $this->assertSame('held', $order->refresh()->status);
            $this->assertEquals($charge, $order->only(array_keys($charge)));
            $held = $this->rows();
            $this->postAs($device, 'qr/fallback-to-counter', ['order_uuid' => $order->uuid])->assertOk();
            $this->assertSame($held, $this->rows());
            $this->claim($device, $order)->assertConflict();
        } else {
            $response->assertOk();
            $this->assertSame('open', $order->refresh()->status);
            $this->assertNull($order->charge_claimed_at);
            $this->assertSame('open', $seat->refresh()->status);
            $this->assertNull($seat->billing_at);
            $this->claim($device, $order)->assertOk()->assertJsonPath('data.already_claimed_by_this_device', false);
        }
        $this->assertDatabaseCount('pos_payments', 0);
        $this->assertDatabaseCount('pos_qr_sessions', 0);
    }

    public function test_owner_recovery_proof_advertises_checkout_and_allows_another_attended_device_after_recovery(): void
    {
        [$device, $table, $seat, $order, $eventId] = $this->fixture();
        $input = ['order_uuid' => $order->uuid, 'kind' => 'staff_rounds', 'event_ids' => [$eventId]];
        $preview = $this->readAs($device, 'tables/'.$table->id.'/draft-recovery?'.http_build_query($input))->assertOk()
            ->assertJsonPath('data.proof.bill.checkout_policy', 'staff_table_claim_v1')->json('data');
        $this->postAs($device, 'tables/'.$table->id.'/draft-recovery', $input + ['preview_token' => $preview['preview_token'],
            'client_request_id' => (string) Str::uuid(), 'local_snapshot_hash' => hash('sha256', 'original-local-copy')])
            ->assertOk()->assertJsonPath('data.result.archive_authorized', true);
        $this->claim($this->seatingDevice('handheld'), $order)->assertOk();
        $this->assertSame('billing', $seat->refresh()->status);
        $this->assertDatabaseCount('pos_orders', 1);
    }

    public function test_fence_refusals_write_nothing_then_inside_fix_can_reserve(): void
    {
        [$device, , , $order] = $this->fixture();
        DB::table('pos_branches')->where('id', $device->branch_id)->update(['latitude' => 23.6, 'longitude' => 58.4, 'geofence_radius_m' => 100]);
        $before = $this->rows();
        $this->claim($device, $order)->assertConflict()->assertJsonPath('errors.0.code', 'geofence_fix_required');
        $this->assertSame($before, $this->rows());
        $this->postAs($device, 'qr/claim-settlement', ['order_uuid' => $order->uuid, 'gps' => ['lat' => 0, 'lng' => 0]])
            ->assertConflict()->assertJsonPath('errors.0.code', 'geofence_outside');
        $this->assertSame($before, $this->rows());
        $this->postAs($device, 'qr/claim-settlement', ['order_uuid' => $order->uuid, 'gps' => ['lat' => 23.6, 'lng' => 58.4]])->assertOk();
    }

    public function test_lapsed_reservation_is_retained_for_attended_recovery_not_reclaimed(): void
    {
        [$device, , , $order] = $this->fixture();
        $this->claim($device, $order)->assertOk();
        $charge = $order->refresh()->only(['charge_device_id', 'charge_amount_baisas', 'charge_roundup_amount_baisas', 'charge_claimed_at', 'charge_deadline_at', 'charge_outcome']);
        $this->travel(6)->minutes();
        $before = $this->rows();
        $this->claim($device, $order)->assertConflict()->assertJsonPath('errors.0.code', 'qr_charge_recovery_required');
        $this->postAs($device, 'qr/reopen-payment', ['order_uuid' => $order->uuid])->assertConflict();
        $this->assertSame($before, $this->rows());
        $this->postAs($device, 'qr/fallback-to-counter', ['order_uuid' => $order->uuid])->assertOk();
        $this->assertSame('held', $order->refresh()->status);
        $this->assertEquals($charge, $order->only(array_keys($charge)));
        $held = $this->rows();
        $this->claim($device, $order)->assertConflict();
        $this->assertSame($held, $this->rows());
    }

    public static function invalidPayments(): array
    {
        return [['short'], ['over'], ['other_device'], ['expired'], ['uncertain']];
    }

    #[DataProvider('invalidPayments')]
    public function test_unchanged_pay_handler_refuses_unsafe_staff_tender_without_domain_writes(string $case): void
    {
        [$device, , , $order] = $this->fixture();
        $this->claim($device, $order)->assertOk();
        $caller = $case === 'other_device' ? $this->seatingDevice('handheld') : $device;
        if ($case === 'expired') {
            $this->travel(6)->minutes();
        }
        if ($case === 'uncertain') {
            $this->postAs($device, 'qr/release-charge', ['order_uuid' => $order->uuid, 'outcome' => 'uncertain'])->assertOk();
        }
        $before = $this->rows();
        $amount = match ($case) {
            'short' => 1999, 'over' => 2001, default => 2000
        };
        $event = ['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order_uuid' => $order->uuid, 'paid_at' => now()->toIso8601String(), 'payments' => [['method' => 'cash', 'amount_baisas' => $amount]]]];
        $this->postAs($caller, 'sync/push', ['events' => [$event]])->assertOk()->assertJsonPath('data.results.0.status', 'failed');
        $this->assertSame($before, $this->rows());
    }
}
