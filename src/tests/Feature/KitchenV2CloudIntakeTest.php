<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\OpenDineInTableAction;
use App\Actions\Qr\QuickOrderCancellationWasteAction;
use App\Actions\Qr\SubmitDineInQrRoundAction;
use App\Kitchen\Configuration;
use App\Kitchen\PreparationEvidence;
use App\Kitchen\Wire;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\TableSession;
use App\Models\TabletOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP6Fixtures;
use Tests\TestCase;

final class KitchenV2CloudIntakeTest extends TestCase
{
    use LaunchP6Fixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p6Setup();
        config(['kitchen.enabled' => true]);
        $this->till->forceFill(['assignment_activated_at' => now()])->save();
        $c = app(Configuration::class);
        $area = (string) Str::uuid();
        $printer = (string) Str::uuid();
        $c->setRouting(100, [10], 10, ['areas' => [['id' => $area, 'name' => 'Kitchen']],
            'destinations' => [['id' => $printer, 'type' => 'printer', 'name' => 'Test printer', 'address' => '192.0.2.10', 'port' => 9100, 'profile' => 'escpos-unverified', 'paused' => false]],
            'rules' => [], 'all_items' => [], 'fallback' => ['areas' => [$area], 'destinations' => [$printer]]], 'test');
        $c->enroll(100, [10], $this->till->id, str_repeat('a', 64), [$area], 'test');
        $this->handheld->forceFill(['assignment_activated_at' => now()])->save();
        $c->enroll(100, [10], $this->handheld->id, str_repeat('b', 64), [$area], 'test');
        $c->assign(100, [10], $this->till->id, 'test', 'Synthetic old-host isolation');
        $this->activate();
    }

    private function activate(): void
    {
        $b = DB::table('pos_kv2_branches')->where('branch_id', 10)->first();
        DB::table('pos_kv2_branches')->where('id', $b->id)->update(['mode' => 'active', 'activation_state' => 'idle', 'applied_version' => $b->desired_version]);
    }

    private function immediate(): void
    {
        app(Configuration::class)->setPolicy(100, [10], 10, 'customer_tablet', 'immediate', 'test');
        $this->activate();
    }

    public function test_manual_tablet_intake_is_durable_frozen_and_replay_safe(): void
    {
        $request = ['client_uuid' => (string) Str::uuid()];
        $data = $this->p6Submit($request)->assertCreated()->json('data');
        $this->p6Submit($request)->assertOk();
        $this->assertDatabaseCount('pos_kv2_submissions', 1);
        $this->assertDatabaseCount('pos_kv2_deliveries', 0);
        $s = DB::table('pos_kv2_submissions')->sole();
        $this->assertSame('waiting_approval', $s->state);
        $this->immediate();
        $this->assertDatabaseHas('pos_kv2_submissions', ['uuid' => $s->uuid, 'state' => 'waiting_approval', 'policy_version' => $s->policy_version]);
        $feed = $this->p6As($this->till, 'GET', '/api/v1/device/kitchen-v2/intake')->assertOk()->json('data');
        $entry = collect($feed['entries'])->firstWhere('event.action', 'submit');
        $this->assertSame('customer_tablet', $entry['event']['source']);
        $this->assertSame($data['order_uuid'], $entry['event']['order_uuid']);
        $this->assertSame('manual', $entry['configuration']['policies']['customer_tablet']);
        $this->assertSame(Wire::hash(['input' => $entry['event'], ...$entry['origin']]), $entry['payload_hash']);
        $this->assertDatabaseCount('pos_payments', 0);
        $this->assertDatabaseCount('pos_stock_movements', 0);
        $this->assertNull(TabletOrder::query()->sole()->sent_to_kitchen_at);
    }

    public function test_immediate_tablet_dine_in_accepts_the_domain_round_once(): void
    {
        $this->immediate();
        $table = $this->seatingTable();
        $request = ['client_uuid' => (string) Str::uuid(), 'order_type' => 'dine_in', 'table_uuid' => $table->uuid];
        $data = $this->p6Submit($request)->assertCreated()->json('data');
        $this->p6Submit($request)->assertOk();
        $round = QrOrderRound::query()->sole();
        $this->assertSame('accepted', $round->status);
        $this->assertNotEmpty($round->priced_lines[0]['order_item_id']);
        $this->assertNull($round->kitchen_printed_at);
        $this->assertDatabaseHas('pos_kv2_submissions', ['order_uuid' => $data['order_uuid'], 'state' => 'released']);
        $this->assertDatabaseCount('pos_kv2_deliveries', 1);
        $row = TabletOrder::query()->sole();
        $this->assertNotNull($row->sent_to_kitchen_at);
        $this->assertNull($row->sent_by_staff_id);
        $this->assertNull($row->taken_by_staff_id);
        $this->assertDatabaseCount('pos_payments', 0);
        $this->assertDatabaseCount('pos_stock_movements', 0);
    }

    public function test_immediate_to_go_does_not_wait_for_payment(): void
    {
        $this->immediate();
        $data = $this->p6Submit(['order_type' => 'to_go'])->assertCreated()->json('data');
        $this->assertSame('held', $this->p6Order($data['order_uuid'])->status);
        $this->assertDatabaseHas('pos_kv2_submissions', ['order_uuid' => $data['order_uuid'], 'state' => 'released']);
        $this->assertDatabaseCount('pos_kv2_deliveries', 1);
        $this->assertDatabaseCount('pos_payments', 0);
    }

    public function test_non_coordinator_cannot_download_full_kitchen_intake(): void
    {
        $this->p6Submit()->assertCreated();
        $this->p6As($this->handheld, 'GET', '/api/v1/device/kitchen-v2/intake')->assertForbidden();
        $this->p6As($this->tablet, 'GET', '/api/v1/device/kitchen-v2/intake')->assertForbidden();
    }

    public function test_immediate_kitchen_release_leaves_pending_loyalty_untouched(): void
    {
        $this->immediate();
        $rule = $this->p6Rule();
        $customer = $this->p6Customer('+96891234567');
        $this->p6Account($customer, $rule, 250);
        $data = $this->p6Submit(['phone' => '91234567', 'payment' => 'points',
            'redeem_request' => ['rule_id' => $rule, 'blocks' => 2]])->assertCreated()->json('data');
        $this->assertDatabaseHas('pos_kv2_submissions', ['order_uuid' => $data['order_uuid'], 'state' => 'released']);
        $this->assertSame('requested', TabletOrder::query()->sole()->redeem_status);
        $this->assertSame(250, (int) DB::table('pos_loyalty_accounts')->where('customer_id', $customer)->value('point_balance'));
        $this->assertDatabaseCount('pos_payments', 0);
    }

    public function test_manual_kitchen_approval_preserves_taken_by_and_replays_one_round(): void
    {
        $data = $this->p6Submit()->assertCreated()->json('data');
        $uuid = $data['tablet_order_uuid'];
        $this->p6Staff($this->till, 7, 'POST', "/api/v1/device/tablet-orders/{$uuid}/take")->assertOk();
        $submission = DB::table('pos_kv2_submissions')->sole();
        $event = ['protocol_version' => 1, 'event_id' => (string) Str::uuid(), 'epoch' => 1,
            'occurred_at' => now()->toIso8601String(), 'action' => 'approve',
            'submission_uuid' => $submission->uuid, 'expected_revision' => 1];
        $this->p6Staff($this->handheld, 9, 'POST', '/api/v1/device/kitchen-v2/events', $event)
            ->assertConflict()->assertJsonPath('errors.0.code', 'tablet_order_taken');
        $this->assertDatabaseCount('pos_kv2_deliveries', 0);
        $this->p6Staff($this->till, 7, 'POST', '/api/v1/device/kitchen-v2/events', $event)->assertOk()->assertJsonPath('data.state', 'released');
        $this->p6Staff($this->till, 7, 'POST', '/api/v1/device/kitchen-v2/events', $event)->assertOk()->assertJsonPath('data.replayed', true);
        $this->assertDatabaseCount('pos_qr_order_rounds', 1);
        $this->assertDatabaseCount('pos_kv2_deliveries', 1);
        $this->assertNull(QrOrderRound::query()->sole()->kitchen_printed_at);
        $this->assertDatabaseCount('pos_payments', 0);
        $this->assertDatabaseCount('pos_stock_movements', 0);
    }

    public function test_existing_tablet_send_button_releases_the_same_kitchen_submission_once(): void
    {
        $data = $this->p6Submit()->assertCreated()->json('data');
        $url = '/api/v1/device/tablet-orders/'.$data['tablet_order_uuid'].'/send-to-kitchen';
        $this->p6Staff($this->handheld, 7, 'POST', $url)->assertOk();
        $this->p6Staff($this->handheld, 7, 'POST', $url)->assertOk();
        $this->assertDatabaseCount('pos_kv2_submissions', 1);
        $this->assertDatabaseHas('pos_kv2_submissions', ['state' => 'released']);
        $this->assertDatabaseCount('pos_kv2_deliveries', 1);
        $this->assertDatabaseCount('pos_qr_order_rounds', 1);
        $this->assertDatabaseCount('pos_payments', 0);
        $this->assertDatabaseCount('pos_stock_movements', 0);
    }

    public function test_intake_amendment_without_explicit_policy_uses_its_immutable_revision(): void
    {
        $this->immediate();
        $this->p6Submit()->assertCreated();
        $submission = DB::table('pos_kv2_submissions')->sole();
        $event = ['protocol_version' => 1, 'event_id' => (string) Str::uuid(), 'epoch' => 1, 'occurred_at' => now()->toIso8601String(),
            'action' => 'amend', 'submission_uuid' => $submission->uuid, 'expected_revision' => 1,
            'replacement' => ['context' => ['reference' => 'Kept original order', 'order_type' => 'quick']]];
        $this->p6Staff($this->till, 7, 'POST', '/api/v1/device/kitchen-v2/events', $event)->assertOk();
        $feed = $this->p6As($this->till, 'GET', '/api/v1/device/kitchen-v2/intake')->assertOk()->json('data.entries');
        $entry = collect($feed)->firstWhere('event.action', 'amend');
        $this->assertSame((int) $submission->policy_version, $entry['policy_version']);
        $this->assertSame('immediate', $entry['configuration']['policies']['customer_tablet']);
        $this->assertArrayNotHasKey('policy_version', $entry['event']['replacement']);
    }

    private function cancellationFixture(): array
    {
        $this->immediate();
        $table = $this->seatingTable();
        $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => $table->uuid])->assertCreated();
        $round = QrOrderRound::query()->sole();
        $seat = TableSession::query()->findOrFail($round->table_session_id);

        return [$round, $seat, ['seating_key' => $seat->client_request_id, 'table_id' => (int) $seat->table_id,
            'queued_offline' => false, 'client_request_id' => (string) Str::uuid(), 'product_id' => $this->coffee,
            'addon_ids' => [], 'notes' => null, 'qty' => 1, 'prepared' => false, 'cancelled_at' => now()->toIso8601String()]];
    }

    public function test_financial_line_cancel_stops_unsent_copy_and_replays_without_more_effects(): void
    {
        [$round,$seat,$payload] = $this->cancellationFixture();
        $url = '/api/v1/device/tables/'.$seat->uuid.'/cancel-line';
        $this->p6Staff($this->till, 7, 'POST', $url, $payload)->assertOk()->assertJsonPath('data.outcome', 'cancelled');
        $this->p6Staff($this->till, 7, 'POST', $url, $payload)->assertOk()->assertJsonPath('data.outcome', 'replayed');
        $this->assertDatabaseCount('pos_kv2_submissions', 1);
        $s = DB::table('pos_kv2_submissions')->sole();
        $this->assertSame(2, (int) $s->revision);
        $snapshot = Wire::read(DB::table('pos_kv2_revisions')->where('revision', 2)->sole()->snapshot);
        $this->assertSame('1.000000', $snapshot['intent']['lines'][0]['quantity']);
        $this->assertDatabaseHas('pos_kv2_deliveries', ['revision' => 1, 'state' => 'cancelled']);
        $this->assertDatabaseHas('pos_kv2_deliveries', ['revision' => 2, 'state' => 'queued', 'purpose' => 'original']);
        $this->assertDatabaseCount('pos_kv2_events', 2);
        $this->assertNull($round->fresh()->kitchen_printed_at);
        $this->assertDatabaseCount('pos_payments', 0);
        $this->assertDatabaseCount('pos_stock_movements', 0);
    }

    public function test_prepared_done_cannot_be_denied_and_sent_copy_gets_delta(): void
    {
        [$round,$seat,$payload] = $this->cancellationFixture();
        $s = DB::table('pos_kv2_submissions')->sole();
        $work = DB::table('pos_kv2_work')->sole();
        $this->p6Staff($this->till, 8, 'POST', '/api/v1/device/kitchen-v2/events', [
            'protocol_version' => 1, 'event_id' => (string) Str::uuid(), 'epoch' => 1, 'occurred_at' => now()->toIso8601String(),
            'action' => 'done_all', 'submission_uuid' => $s->uuid, 'expected_revision' => 1, 'area_uuid' => $work->area_uuid,
        ])->assertOk();
        DB::table('pos_kv2_deliveries')->update(['state' => 'sent_unconfirmed']);
        $e = PreparationEvidence::forOrder($this->p6Order($s->order_uuid));
        $this->assertSame([(int) $round->priced_lines[0]['order_item_id']], $e['done']);
        $url = '/api/v1/device/tables/'.$seat->uuid.'/cancel-line';
        $this->p6Staff($this->till, 7, 'POST', $url, $payload)->assertConflict()->assertJsonPath('errors.0.code', 'kitchen_preparation_review_required');
        $this->assertSame(2, (int) $round->fresh()->priced_lines[0]['qty']);
        $payload['prepared'] = true;
        $this->p6Staff($this->till, 7, 'POST', $url, $payload)->assertOk();
        $this->assertDatabaseHas('pos_kv2_deliveries', ['revision' => 2, 'purpose' => 'change', 'state' => 'queued']);
        $this->assertDatabaseHas('pos_kv2_work', ['revision' => 2, 'state' => 'outstanding']);
        $this->assertNull($round->fresh()->kitchen_printed_at);
    }

    public function test_kitchen_rejection_cannot_be_released_by_the_existing_tablet_button(): void
    {
        $data = $this->p6Submit()->assertCreated()->json('data');
        $s = DB::table('pos_kv2_submissions')->sole();
        $this->p6Staff($this->till, 7, 'POST', '/api/v1/device/kitchen-v2/events', [
            'protocol_version' => 1, 'event_id' => (string) Str::uuid(), 'epoch' => 1, 'occurred_at' => now()->toIso8601String(),
            'action' => 'reject', 'submission_uuid' => $s->uuid, 'expected_revision' => 1,
        ])->assertOk();
        $this->p6Staff($this->till, 7, 'POST', '/api/v1/device/tablet-orders/'.$data['tablet_order_uuid'].'/send-to-kitchen')->assertConflict();
        $this->assertDatabaseCount('pos_kv2_deliveries', 0);
        $this->assertNull(TabletOrder::query()->sole()->sent_to_kitchen_at);
        $this->assertDatabaseCount('pos_qr_order_rounds', 0);
    }

    public function test_station_payment_and_exact_sync_replay_do_not_create_another_kitchen_order(): void
    {
        app(Configuration::class)->setPolicy(100, [10], 10, 'qr_web', 'immediate', 'test');
        $this->activate();
        [$station,$order] = $this->quickQr();
        $data = ['order_uuid' => $order->uuid];
        $original = DB::table('pos_kv2_deliveries')->sole()->uuid;
        $this->p6As($station, 'POST', '/api/v1/device/qr/claim-charge', ['order_uuid' => $order->uuid])->assertOk();
        $at = now()->toIso8601String();
        $events = ['events' => [['client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => $at,
            'payload' => ['order_uuid' => $data['order_uuid'], 'paid_at' => $at, 'staff_id' => 7,
                'payments' => [['method' => 'card', 'amount_baisas' => 2000, 'status' => 'success', 'softpos_reference' => 'K4-SYNTHETIC', 'softpos_auth_code' => 'K4-TEST']]]]]];
        $response = $this->p6As($station, 'POST', '/api/v1/device/sync/push', $events)->assertOk();
        $this->assertSame('processed', $response->json('data.results.0.status'), $response->getContent());
        $stock = DB::table('pos_stock_movements')->count();
        $this->p6As($station, 'POST', '/api/v1/device/sync/push', $events)->assertOk();
        $this->assertDatabaseCount('pos_payments', 1);
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseCount('pos_kv2_submissions', 1);
        $this->assertDatabaseCount('pos_kv2_deliveries', 1);
        $this->assertSame($original, DB::table('pos_kv2_deliveries')->sole()->uuid);
        $this->assertSame($stock, DB::table('pos_stock_movements')->count());
    }

    private function quickQr(): array
    {
        $station = $this->p6Device('mdev_k4_station', 'payment_station');
        $secret = 'synthetic-k4-qr-secret';
        $session = QrSession::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'device_id' => $station->id,
            'token' => hash('sha256', (string) Str::uuid()), 'token_expires_at' => now()->addMinute(),
            'client_secret_hash' => QrSession::hashClientSecret($secret), 'status' => 'active',
            'bound_at' => now(), 'last_seen_at' => now(), 'expires_at' => now()->addMinutes(30),
        ]);
        $body = ['client_request_id' => (string) Str::uuid(), 'checkout_choice' => 'machine', 'phone' => '90001234',
            'lines' => [$this->p6Line($this->coffee, 2)]];
        $headers = ['X-QR-Session' => $session->uuid, 'X-QR-Client-Secret' => $secret];
        $this->withHeaders($headers)->postJson('/api/v1/public/qr/checkout', $body)->assertCreated();
        $this->withHeaders($headers)->postJson('/api/v1/public/qr/checkout', $body)->assertCreated();

        return [$station, Order::query()->sole()];
    }

    public function test_qr_quick_manual_policy_waits_without_financial_side_effects(): void
    {
        [, $order] = $this->quickQr();
        $this->assertSame('awaiting_payment', $order->status);
        $this->assertDatabaseHas('pos_kv2_submissions', ['source' => 'qr_web', 'state' => 'waiting_approval']);
        $this->assertDatabaseCount('pos_kv2_submissions', 1);
        $this->assertDatabaseCount('pos_kv2_deliveries', 0);
        $this->assertDatabaseCount('pos_payments', 0);
    }

    public function test_qr_table_manual_and_immediate_use_original_qr_confirmation(): void
    {
        foreach (['manual', 'immediate'] as $policy) {
            app(Configuration::class)->setPolicy(100, [10], 10, 'qr_web', $policy, 'test');
            $this->activate();
            $table = $this->seatingTable();
            $opened = app(OpenDineInTableAction::class)->handle($this->p6Device('mdev_qr_'.$policy, 'payment_station'), $table->id);
            $session = app(BindQrTableSessionAction::class)->handle($opened['table_token'], 'k4-qr-secret');
            $session->update(['origin' => 'table_card']);
            $body = ['client_request_id' => (string) Str::uuid(), 'phone' => '92004321', 'lines' => [$this->p6Line($this->coffee)]];
            $result = app(SubmitDineInQrRoundAction::class)->handle($session->id, $body, '127.0.0.1');
            $round = QrOrderRound::query()->where('order_id', $result['order']->id)->sole();
            $submission = DB::table('pos_kv2_submissions')->where('round_id', $round->id)->sole();
            if ($policy === 'manual') {
                $this->assertSame('pending_confirmation', $round->status);
                $this->assertSame('waiting_approval', $submission->state);
                $this->p6Staff($this->handheld, 7, 'POST', '/api/v1/device/qr/confirm-round', ['round_id' => $round->id])->assertOk();
            }
            $this->assertSame('accepted', $round->fresh()->status);
            $this->assertDatabaseHas('pos_kv2_submissions', ['uuid' => $submission->uuid, 'state' => 'released']);
            $this->assertNull($round->fresh()->kitchen_printed_at);
        }
        $this->assertDatabaseCount('pos_kv2_submissions', 2);
        $this->assertDatabaseCount('pos_kv2_deliveries', 2);
        $this->assertDatabaseCount('pos_payments', 0);
    }

    public function test_done_combo_parent_does_not_mark_undone_children_prepared(): void
    {
        $this->immediate();
        $combo = $this->p4Combo('Coffee set', '3.000', [
            ['Drink', 1, 1, [$this->coffee => '0.000']], ['Cake', 1, 1, [$this->cake => '0.000']],
        ]);
        $table = $this->seatingTable();
        $data = $this->p6Submit(['order_type' => 'dine_in', 'table_uuid' => $table->uuid,
            'lines' => [$this->p6Line($combo['id'], 2)]])->assertCreated()->json('data');
        $s = DB::table('pos_kv2_submissions')->sole();
        $root = DB::table('pos_kv2_work')->get()->first(fn ($w) => ! isset(Wire::read($w->line)['parent_line_uuid']));
        $event = ['protocol_version' => 1, 'event_id' => (string) Str::uuid(), 'epoch' => 1, 'occurred_at' => now()->toIso8601String(),
            'action' => 'item_done', 'submission_uuid' => $s->uuid, 'expected_revision' => 1, 'area_uuid' => $root->area_uuid, 'line_uuid' => $root->line_uuid];
        $this->p6Staff($this->till, 8, 'POST', '/api/v1/device/kitchen-v2/events', $event)->assertOk();
        $order = $this->p6Order($data['order_uuid']);
        $parent = OrderItem::query()->where('order_id', $order->id)->whereNull('parent_order_item_id')->sole();
        $ids = app(QuickOrderCancellationWasteAction::class)->preparedIds($order);
        $this->assertSame([(int) $parent->id], $ids);
        $state = PreparationEvidence::forOrder($order);
        $this->assertCount(2, $state['review']);
        $this->assertNull(QrOrderRound::query()->sole()->kitchen_printed_at);
        $this->assertDatabaseCount('pos_payments', 0);
        $this->assertDatabaseCount('pos_stock_movements', 0);
    }

    public function test_direct_online_till_and_handheld_rounds_capture_once_without_legacy_print(): void
    {
        foreach ([$this->till, $this->handheld] as $device) {
            $table = $this->seatingTable();
            $base = ['seating_key' => (string) Str::uuid(), 'table_id' => $table->id, 'queued_offline' => false];
            $opened = $this->p6Staff($device, 7, 'POST', '/api/v1/device/tables/open',
                $base + ['opened_at' => now()->toIso8601String(), 'joined_table_ids' => []])->assertOk()->json('data');
            $body = $base + ['client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(),
                'lines' => [$this->p6Line($this->coffee)]];
            $url = '/api/v1/device/tables/'.$opened['table_session_uuid'].'/round';
            $this->p6Staff($device,7,'POST',$url,$body)->assertOk();
            $this->p6Staff($device,7,'POST',$url,$body)->assertOk();
        }
        $this->assertDatabaseCount('pos_kv2_submissions',2);
        $this->assertDatabaseHas('pos_kv2_submissions',['source' => 'main_pos']);
        $this->assertDatabaseHas('pos_kv2_submissions',['source' => 'handheld']);
        $this->assertSame(0,QrOrderRound::query()->whereNotNull('kitchen_printed_at')->count());
        $this->assertDatabaseCount('pos_payments',0);
    }
}
