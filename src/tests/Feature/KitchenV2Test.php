<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\CancelExpiredQuickOrdersAction;
use App\Actions\Qr\QrChargeException;
use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\ClaimKitchenTicketAction;
use App\Kitchen\Access;
use App\Kitchen\Compatibility;
use App\Kitchen\Configuration;
use App\Kitchen\DomainLinkage;
use App\Kitchen\Grants;
use App\Kitchen\KitchenFault;
use App\Kitchen\Routing;
use App\Kitchen\Wire;
use App\Models\Device;
use App\Models\Order;
use App\Models\SyncEvent;
use App\Support\Staff\StaffToken;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class KitchenV2Test extends TestCase
{
    use RefreshDatabase,TableSessionFixtures;

    private Device $device;

    private array $bundle;

    private int $product;

    private string $area;

    private string $printer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['kitchen.enabled' => true]);
        $this->device = $this->seatingDevice(attributes: ['assignment_activated_at' => now()]);
        $this->seedPosStaff([1]);
        DB::table('pos_staff')->where('id', 1)->update(['position' => 'manager']);
        $this->product = $this->seatingProduct()->id;
        $this->area = (string) Str::uuid();
        $this->printer = (string) Str::uuid();
        $this->bundle = ['areas' => [['id' => $this->area, 'name' => 'Grill']], 'destinations' => [['id' => $this->printer, 'type' => 'printer', 'name' => 'Kitchen printer', 'address' => '192.168.100.173', 'port' => 9100, 'profile' => 'escpos-unverified', 'paused' => false]], 'rules' => [], 'all_items' => [], 'fallback' => ['areas' => [$this->area], 'destinations' => [$this->printer]]];
        $c = app(Configuration::class);
        $c->setRouting(100, [10], 10, $this->bundle, 'test-merchant');
        $c->setPolicy(100, [10], 10, 'staff', 'immediate', 'test-merchant');
        $c->enroll(100, [10], $this->device->id, str_repeat('a', 64), [$this->area], 'test-admin');
        $c->assign(100, [10], $this->device->id, 'test-admin', 'All legacy executors stopped in isolated test.');
        $this->activate();
    }

    private function envelope(string $action, array $extra = []): array
    {
        return [...['protocol_version' => 1, 'event_id' => (string) Str::uuid(), 'epoch' => (int) DB::table('pos_kv2_branches')->where('branch_id', 10)->value('epoch'), 'occurred_at' => now()->toIso8601String(), 'action' => $action], ...$extra];
    }

    private function kitchenPost(string $path, array $data, ?Device $device = null, ?int $staff = 1)
    {
        $device ??= $this->device;
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($device->plainTextToken)->withHeader('X-Staff-Token', $staff ? StaffToken::issue($device->id, $staff) : '')->postJson('/api/v1/device/kitchen-v2/'.$path, $data);
    }

    private function kitchenGet(string $path, ?Device $device = null)
    {
        $device ??= $this->device;
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($device->plainTextToken)->getJson('/api/v1/device/'.$path);
    }

    private function activate(): void
    {
        $c = $this->kitchenGet('kitchen-v2/configuration')->assertOk()->json('data');
        $id = (string) Str::uuid();
        foreach (['prepare_configuration', 'commit_configuration', 'finish_configuration'] as $phase) {
            $this->kitchenPost('configuration/ack', $this->envelope($phase, ['version' => $c['desired_version'], 'hash' => $c['desired']['hash'], 'activation_id' => $id]))->assertOk();
        }
    }

    private function intent(array $extra = []): array
    {
        return $this->envelope('submit', [...['submission_uuid' => (string) Str::uuid(), 'order_uuid' => (string) Str::uuid(), 'round_uuid' => (string) Str::uuid(), 'revision' => 1, 'policy_version' => (int) DB::table('pos_kv2_branches')->where('branch_id', 10)->value('applied_version'), 'source' => 'main_pos', 'domain_event_uuid' => (string) Str::uuid(), 'domain_state' => 'submitted', 'context' => ['reference' => 'TEST-001', 'table' => 'A2'], 'lines' => [['line_uuid' => (string) Str::uuid(), 'product_id' => $this->product, 'category_id' => null, 'quantity' => '2.000000', 'name' => 'Fish', 'name_ar' => 'سمك', 'notes' => 'No salt', 'modifiers' => ['Extra lemon'], 'removals' => [], 'allergens' => ['fish']]]], ...$extra]);
    }

    private function submitted(array $extra = []): array
    {
        $i = $this->intent($extra);
        $this->kitchenPost('submissions', $i)->assertCreated();

        return $i;
    }

    private function action(array $i, string $action, array $extra = []): array
    {
        return $this->envelope($action, [...['submission_uuid' => $i['submission_uuid'], 'expected_revision' => $i['revision']], ...$extra]);
    }

    public function test_submission_and_event_replays_do_not_duplicate_work_and_changed_payload_conflicts(): void
    {
        $i = $this->intent();
        $first = $this->kitchenPost('submissions', $i)->assertCreated()->json('data');
        $second = $this->kitchenPost('submissions', $i)->assertCreated()->assertJsonPath('data.replayed', true)->json('data');
        $this->assertSame($first['sequence'], $second['sequence']);
        $this->assertDatabaseCount('pos_kv2_submissions', 1);
        $this->assertDatabaseCount('pos_kv2_deliveries', 1);
        $i['context']['reference'] = 'ALTERED';
        $this->kitchenPost('submissions', $i)->assertConflict()->assertJsonPath('errors.0.code', 'event_payload_conflict');
        $this->assertDatabaseCount('pos_orders', 0);
        $this->assertDatabaseCount('pos_payments', 0);
    }

    public function test_manual_policy_is_frozen_and_new_settings_do_not_release_waiting_orders(): void
    {
        app(Configuration::class)->setPolicy(100, [10], 10, 'staff', 'manual', 'owner');
        $this->activate();
        $i = $this->submitted();
        $this->assertDatabaseCount('pos_kv2_deliveries', 0);
        app(Configuration::class)->setPolicy(100, [10], 10, 'staff', 'immediate', 'owner');
        $this->activate();
        $this->assertDatabaseHas('pos_kv2_submissions', ['uuid' => $i['submission_uuid'], 'state' => 'waiting_approval']);
        $approve = $this->action($i, 'approve');
        $this->kitchenPost('events', $approve)->assertOk()->assertJsonPath('data.state', 'released');
        $this->kitchenPost('events', $approve)->assertOk()->assertJsonPath('data.replayed', true);
        $this->kitchenPost('events', $this->action($i, 'approve'))->assertConflict();
        $this->assertDatabaseCount('pos_kv2_deliveries', 1);
    }

    public function test_done_all_ready_undo_new_cycle_and_handover_do_not_change_finance(): void
    {
        $i = $this->submitted();
        $done = $this->action($i, 'done_all', ['area_uuid' => $this->area]);
        $r = $this->kitchenPost('events', $done)->assertOk()->assertJsonPath('data.state', 'ready')->json('data');
        $time = DB::table('pos_kv2_work')->value('done_at');
        $this->kitchenPost('events', $done)->assertOk();
        $this->assertSame($time, DB::table('pos_kv2_work')->value('done_at'));
        $this->kitchenPost('events', $this->action($i, 'undo', ['area_uuid' => $this->area, 'line_uuid' => $i['lines'][0]['line_uuid']]))->assertOk()->assertJsonPath('data.state', 'released');
        $r2 = $this->kitchenPost('events', $this->action($i, 'item_done', ['area_uuid' => $this->area, 'line_uuid' => $i['lines'][0]['line_uuid']]))->assertOk()->json('data');
        $this->assertNotSame($r['ready_cycle'], $r2['ready_cycle']);
        $this->kitchenPost('events', $this->action($i, 'collected', ['ready_cycle' => $r['ready_cycle']]))->assertConflict();
        $handover = $this->action($i, 'collected', ['ready_cycle' => $r2['ready_cycle']]);
        $this->kitchenPost('events', $handover)->assertOk();
        $this->kitchenPost('events', $handover)->assertOk()->assertJsonPath('data.replayed', true);
        $this->kitchenPost('events', $this->action($i, 'undo', ['area_uuid' => $this->area, 'line_uuid' => $i['lines'][0]['line_uuid']]))->assertConflict();
        $this->assertDatabaseCount('pos_orders', 0);
        $this->assertDatabaseCount('pos_payments', 0);
        $this->assertDatabaseCount('pos_stock_movements', 0);
    }

    public function test_cross_tenant_branch_stale_epoch_staff_and_area_fail_without_writes(): void
    {
        $i = $this->submitted();
        $count = DB::table('pos_kv2_events')->count();
        $this->kitchenPost('events', $this->action($i, 'done_all', ['area_uuid' => (string) Str::uuid()]))->assertForbidden();
        $this->kitchenPost('events', $this->action($i, 'done_all', ['area_uuid' => $this->area]), staff: null)->assertForbidden();
        $stale = $this->action($i, 'done_all', ['area_uuid' => $this->area]);
        $stale['epoch'] = 999;
        $this->kitchenPost('events', $stale)->assertConflict();
        foreach ([$this->seatingDevice('handheld', 11), $this->seatingDevice('fixed_pos', 20, 200)] as $foreign) {
            $this->kitchenPost('events', $this->action($i, 'approve'), $foreign)->assertForbidden();
            $this->kitchenGet('kitchen-v2/snapshot', $foreign)->assertForbidden();
        }
        $this->assertSame($count, DB::table('pos_kv2_events')->count());
    }

    public function test_routing_item_precedence_multiple_copies_fallback_and_missing_work(): void
    {
        $i = $this->intent();
        $cat = (string) Str::uuid();
        $pass = (string) Str::uuid();
        $b = $this->bundle;
        $b['destinations'][] = [...$b['destinations'][0], 'id' => $cat];
        $b['destinations'][] = [...$b['destinations'][0], 'id' => $pass];
        $b['all_items'] = [$pass, $this->printer];
        $b['rules'] = [['kind' => 'category', 'reference_id' => 7, 'areas' => [$this->area], 'destinations' => [$cat]], ['kind' => 'item', 'reference_id' => $this->product, 'areas' => [$this->area], 'destinations' => [$this->printer, $pass]]];
        $i['lines'][0]['category_id'] = 7;
        $r = Routing::resolve(Routing::validate($b), $i['lines']);
        $this->assertCount(2, $r['copies']);
        $this->assertArrayNotHasKey($cat, $r['copies']);
        $this->assertCount(1, $r['work']);
        $b['rules'] = [];
        $b['fallback'] = ['areas' => [], 'destinations' => []];
        $r = Routing::resolve($b, $i['lines']);
        $this->assertSame([$i['lines'][0]['line_uuid']], $r['needs_routing']);
        app(Configuration::class)->setRouting(100, [10], 10, $b, 'owner');
        $this->activate();
        $this->submitted();
        $this->assertDatabaseHas('pos_kv2_submissions', ['state' => 'needs_routing']);
        $this->assertDatabaseCount('pos_kv2_deliveries', 0);
    }

    public function test_branch_policy_scope_all_branches_and_future_inheritance_preserve_printer_addresses(): void
    {
        $this->seatingBranch(11);
        $this->seatingBranch(20, 200);
        $c = app(Configuration::class);
        $c->setPolicy(100, [10, 11], 10, 'qr_web', 'immediate', 'owner');
        $c->setPolicy(100, [10, 11], 11, 'customer_tablet', 'immediate', 'owner');
        $c->setPolicy(100, [10, 11], null, 'qr_web', 'manual', 'owner');
        $this->assertDatabaseHas('pos_kv2_policies', ['company_id' => 100, 'branch_scope' => 0, 'source' => 'qr_web', 'release_mode' => 'manual']);
        $this->assertSame(0, DB::table('pos_kv2_policies')->where('company_id', 100)->where('source', 'qr_web')->where('branch_scope', '>', 0)->count());
        $this->assertDatabaseHas('pos_kv2_policies', ['branch_scope' => 11, 'source' => 'customer_tablet', 'release_mode' => 'immediate']);
        $this->assertSame(0, DB::table('pos_kv2_branches')->where('company_id', 200)->count());
        $this->seatingBranch(12);
        $new = $c->setRouting(100, [12], 12, $this->bundle, 'owner');
        $this->assertSame('manual', $new['bundle']['policies']['qr_web']);
        $current = $this->kitchenGet('kitchen-v2/configuration')->json('data.desired.bundle');
        $this->assertSame('192.168.100.173', $current['destinations'][0]['address']);
        try {
            $c->setPolicy(100, [10], null, 'qr_web', 'immediate', 'limited-user');
            $this->fail();
        } catch (KitchenFault $e) {
            $this->assertSame('branch_forbidden', $e->reason);
        }
    }

    public function test_full_payment_mode_is_rejected(): void
    {
        try {
            app(Configuration::class)->setPolicy(100, [10], 10, 'qr_web', 'after_full_payment', 'owner');
            $this->fail();
        } catch (KitchenFault $e) {
            $this->assertSame('release_mode_unsupported', $e->reason);
        }
    }

    public function test_activation_lost_ack_replay_and_pending_versions_are_recoverable(): void
    {
        $old = $this->intent();
        app(Configuration::class)->setPolicy(100, [10], 10, 'staff', 'manual', 'owner');
        $c = $this->kitchenGet('kitchen-v2/configuration')->json('data');
        $activation = (string) Str::uuid();
        $prep = $this->envelope('prepare_configuration', ['version' => $c['desired_version'], 'hash' => $c['desired']['hash'], 'activation_id' => $activation]);
        $this->kitchenPost('configuration/ack', $prep)->assertOk();
        $this->kitchenPost('configuration/ack', $prep)->assertOk()->assertJsonPath('data.replayed', true);
        $this->kitchenPost('submissions', $old)->assertConflict()->assertJsonPath('errors.0.code', 'configuration_activation_pending');
        $this->kitchenGet('kitchen-v2/configuration')->assertJsonPath('data.applied_version', $old['policy_version']);
        foreach (['commit_configuration', 'finish_configuration'] as $action) {
            $payload = [...$prep, 'event_id' => (string) Str::uuid(), 'action' => $action];
            $this->kitchenPost('configuration/ack', $payload)->assertOk();
            $this->kitchenPost('configuration/ack', $payload)->assertOk()->assertJsonPath('data.replayed', true);
        }
        $this->kitchenPost('submissions', $old)->assertConflict()->assertJsonPath('errors.0.code', 'stale_configuration');
        $this->assertDatabaseCount('pos_kv2_submissions', 0);
    }

    public function test_printer_uncertainty_is_not_retried_and_result_ack_is_not_another_send(): void
    {
        $i = $this->submitted();
        $id = DB::table('pos_kv2_deliveries')->value('uuid');
        $attempt = (string) Str::uuid();
        $this->kitchenPost('delivery-results', $this->envelope('claim_delivery', ['delivery_uuid' => $id, 'attempt_uuid' => $attempt]))->assertOk();
        foreach (['sending', 'sent_unconfirmed'] as $result) {
            $r = $this->envelope('delivery_result', ['delivery_uuid' => $id, 'attempt_uuid' => $attempt, 'result' => $result]);
            $this->kitchenPost('delivery-results', $r)->assertOk();
            $this->kitchenPost('delivery-results', $r)->assertOk()->assertJsonPath('data.replayed', true);
        }
        $this->kitchenPost('delivery-results', $this->envelope('claim_delivery', ['delivery_uuid' => $id, 'attempt_uuid' => (string) Str::uuid()]))->assertConflict();
        $this->assertDatabaseHas('pos_kv2_submissions', ['uuid' => $i['submission_uuid'], 'state' => 'released']);
        $this->assertDatabaseCount('pos_kv2_attempts', 1);
        $reprint = $this->envelope('reprint', ['delivery_uuid' => $id, 'reason' => 'Operator checked missing paper']);
        $this->kitchenPost('delivery-results', $reprint)->assertOk();
        $this->kitchenPost('delivery-results', $reprint)->assertOk();
        $this->assertDatabaseCount('pos_kv2_deliveries', 2);
    }

    public function test_delivery_before_send_failure_retry_and_old_result_is_fenced(): void
    {
        $this->submitted();
        $id = DB::table('pos_kv2_deliveries')->value('uuid');
        $old = (string) Str::uuid();
        $this->kitchenPost('delivery-results', $this->envelope('claim_delivery', ['delivery_uuid' => $id, 'attempt_uuid' => $old]))->assertOk();
        $this->kitchenPost('delivery-results', $this->envelope('delivery_result', ['delivery_uuid' => $id, 'attempt_uuid' => $old, 'result' => 'failed_before_send']))->assertOk();
        $this->kitchenPost('delivery-results', $this->envelope('claim_delivery', ['delivery_uuid' => $id, 'attempt_uuid' => (string) Str::uuid()]))->assertOk();
        $this->kitchenPost('delivery-results', $this->envelope('delivery_result', ['delivery_uuid' => $id, 'attempt_uuid' => $old, 'result' => 'sending']))->assertConflict();
    }

    public function test_pending_order_link_does_not_lose_done_evidence_or_create_sale(): void
    {
        $i = $this->submitted();
        $this->kitchenPost('events', $this->action($i, 'done_all', ['area_uuid' => $this->area]))->assertOk();
        $this->kitchenPost('events', $this->action($i, 'link'))->assertConflict();
        $order = Order::query()->create(['uuid' => $i['order_uuid'], 'company_id' => 100, 'branch_id' => 10, 'device_id' => $this->device->id, 'source' => 'main_pos', 'order_type' => 'quick', 'status' => 'paid', 'subtotal' => '1.000', 'discount_total' => '0.000', 'comp_total' => '0.000', 'tax_total' => '0.000', 'grand_total' => '1.000', 'opened_at' => now()]);
        $link = $this->action($i, 'link');
        $this->kitchenPost('events', $link)->assertOk()->assertJsonPath('data.link_state', 'linked');
        $this->kitchenPost('events', $link)->assertOk();
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseCount('pos_kv2_deliveries', 1);
        $this->assertSame('done', Compatibility::preparation(100, 10, $i['order_uuid'])[0]['evidence']);
        $this->assertSame([], Compatibility::preparation(200, 20, $i['order_uuid']));
    }

    public function test_outer_transaction_failure_leaves_no_submission_receipt_or_partial_work(): void
    {
        $before = DB::table('pos_kv2_events')->count();
        try {
            DB::transaction(function () {
                $this->submitted();
                throw new RuntimeException('injected failure');
            });
        } catch (RuntimeException $e) {
            $this->assertSame('injected failure', $e->getMessage());
        }
        $this->assertDatabaseCount('pos_kv2_submissions', 0);
        $this->assertDatabaseCount('pos_kv2_work', 0);
        $this->assertDatabaseCount('pos_kv2_deliveries', 0);
        $this->assertSame($before, DB::table('pos_kv2_events')->count());
    }

    public function test_new_revision_does_not_inherit_changed_done_and_stale_done_all_is_rejected(): void
    {
        $i = $this->submitted();
        $this->kitchenPost('events', $this->action($i, 'done_all', ['area_uuid' => $this->area]))->assertOk();
        $lines = $i['lines'];
        $lines[0]['quantity'] = '3.000000';
        $this->kitchenPost('events', $this->action($i, 'amend', ['replacement' => ['lines' => $lines]]))->assertOk()->assertJsonPath('data.revision', 2)->assertJsonPath('data.state', 'released');
        $this->kitchenPost('events', $this->action($i, 'done_all', ['area_uuid' => $this->area]))->assertConflict()->assertJsonPath('errors.0.code', 'stale_revision');
        $this->assertDatabaseCount('pos_kv2_submissions', 1);
        $this->assertDatabaseCount('pos_kv2_revisions', 2);
        $this->assertDatabaseHas('pos_kv2_work', ['revision' => 2, 'quantity' => '3.000000', 'state' => 'outstanding']);
        $this->assertDatabaseHas('pos_kv2_deliveries', ['revision' => 1, 'state' => 'cancelled']);
    }

    public function test_kds_has_no_financial_configuration_or_cross_area_access(): void
    {
        $i = $this->submitted();
        $kds = $this->seatingDevice('kitchen_display', attributes: ['assignment_activated_at' => now()]);
        app(Configuration::class)->enroll(100, [10], $kds->id, str_repeat('b', 64), [], 'admin');
        foreach (['config', 'customers/search', 'approvers', 'kitchen-v2/configuration', 'kitchen-v2/deliveries'] as $url) {
            $this->kitchenGet($url, $kds)->assertForbidden();
        }
        $this->kitchenGet('kitchen-v2/snapshot', $kds)->assertOk()->assertJsonCount(0, 'data.cards');
        $this->kitchenPost('events', $this->action($i, 'collected', ['ready_cycle' => (string) Str::uuid()]), $kds)->assertForbidden();
        $this->kitchenPost('events', $this->action($i, 'done_all', ['area_uuid' => $this->area]), $kds)->assertForbidden();
    }

    public function test_protocol_shape_domain_holds_and_quantities_fail_without_mutation(): void
    {
        foreach ([['protocol_version' => 2], ['revision' => 2], ['domain_state' => 'held'], ['source' => 'qr_web'], ['policy_version' => 999]] as $extra) {
            $this->kitchenPost('submissions', $this->intent($extra))->assertStatus(in_array('held', $extra, true) || isset($extra['protocol_version']) || isset($extra['source']) ? 422 : 409);
        }
        $i = $this->intent();
        $i['lines'][0]['quantity'] = 1.2;
        $this->kitchenPost('submissions', $i)->assertUnprocessable();
        $i = $this->intent();
        $i['lines'][0]['product_id'] = 9999;
        $this->kitchenPost('submissions', $i)->assertUnprocessable();
        $this->assertDatabaseCount('pos_kv2_submissions', 0);
    }

    public function test_route_changes_preserve_existing_delivery_snapshot_and_reassignment_rejects_unresolved_work(): void
    {
        $this->submitted();
        $old = DB::table('pos_kv2_deliveries')->value('snapshot');
        $b = $this->bundle;
        $b['destinations'][0]['address'] = '192.168.100.199';
        app(Configuration::class)->setRouting(100, [10], 10, $b, 'owner');
        $this->activate();
        $this->assertSame($old, DB::table('pos_kv2_deliveries')->value('snapshot'));
        app(Configuration::class)->suspend(100, [10], 10, 'owner');
        $this->kitchenGet('kitchen-v2/deliveries')->assertConflict();
        try {
            app(Configuration::class)->assign(100, [10], $this->device->id, 'owner', 'Old device stopped and isolated.');
            $this->fail();
        } catch (KitchenFault $e) {
            $this->assertSame('delivery_reconciliation_required', $e->reason);
        }
        $this->assertTrue(Compatibility::ownsBranch(100, 10));
        $this->assertDatabaseCount('pos_kv2_submissions', 1);
    }

    public function test_multi_area_done_only_completes_own_work_and_items_can_be_done_individually(): void
    {
        $other = (string) Str::uuid();
        $b = $this->bundle;
        $b['areas'][] = ['id' => $other, 'name' => 'Drinks'];
        $b['fallback']['areas'][] = $other;
        app(Configuration::class)->setRouting(100, [10], 10, $b, 'owner');
        app(Configuration::class)->enroll(100, [10], $this->device->id, str_repeat('a', 64), [$this->area, $other], 'admin');
        $this->activate();
        $i = $this->intent();
        $i['lines'][] = [...$i['lines'][0], 'line_uuid' => (string) Str::uuid()];
        $this->kitchenPost('submissions', $i)->assertCreated();
        $this->kitchenPost('events', $this->action($i, 'item_done', ['area_uuid' => $this->area, 'line_uuid' => $i['lines'][0]['line_uuid']]))->assertOk()->assertJsonPath('data.state', 'released');
        $this->kitchenPost('events', $this->action($i, 'done_all', ['area_uuid' => $this->area]))->assertOk()->assertJsonPath('data.state', 'released');
        $this->assertSame(2, DB::table('pos_kv2_work')->where('area_uuid', $other)->where('state', 'outstanding')->count());
        $this->kitchenPost('events', $this->action($i, 'done_all', ['area_uuid' => $other]))->assertOk()->assertJsonPath('data.state', 'ready');
    }

    public function test_canonical_source_identity_cannot_be_resubmitted_with_new_uuids(): void
    {
        $i = $this->submitted();
        $i['event_id'] = (string) Str::uuid();
        $i['submission_uuid'] = (string) Str::uuid();
        $i['round_uuid'] = (string) Str::uuid();
        $this->kitchenPost('submissions', $i)->assertConflict()->assertJsonPath('errors.0.code', 'source_already_submitted');
        $this->assertDatabaseCount('pos_kv2_deliveries', 1);
    }

    public function test_valid_qr_financial_held_is_not_a_kitchen_hold_but_wrong_lines_are_rejected(): void
    {
        app(Configuration::class)->setPolicy(100, [10], 10, 'qr_web', 'immediate', 'owner');
        $this->activate();
        $i = $this->intent(['source' => 'qr_web']);
        $table = $this->seatingTable();
        $seat = $this->seatingRow($table, ['opened_by_device_id' => $this->device->id]);
        $order = $this->seatingOrder($seat, ['uuid' => $i['order_uuid'], 'source' => 'qr_web', 'status' => 'held']);
        $round = $this->seatingRound($seat, $order, ['priced_lines' => [['product_id' => $this->product, 'qty' => '2.000000', 'line_total_baisas' => 2000]], 'needs_review' => false]);
        $i['round_id'] = $round->id;
        $bad = $i;
        $bad['lines'][0]['quantity'] = '3.000000';
        $this->kitchenPost('submissions', $bad)->assertUnprocessable()->assertJsonPath('errors.0.code', 'order_lines_mismatch');
        $this->kitchenPost('submissions', $i)->assertCreated()->assertJsonPath('data.state', 'released');
        $this->assertSame('held', $order->fresh()->status);
    }

    public function test_invalid_or_accounting_rounds_never_release_and_reject_keeps_no_delivery(): void
    {
        $i = $this->intent(['source' => 'customer_tablet']);
        $table = $this->seatingTable();
        $seat = $this->seatingRow($table);
        $order = $this->seatingOrder($seat, ['uuid' => $i['order_uuid'], 'source' => 'customer_tablet']);
        $round = $this->seatingRound($seat, $order, ['needs_review' => true, 'priced_lines' => [['product_id' => $this->product, 'qty' => 2, 'held_reason' => 'inactive', 'line_total_baisas' => null]]]);
        $i['round_id'] = $round->id;
        $this->kitchenPost('submissions', $i)->assertUnprocessable();
        $round->update(['needs_review' => false, 'priced_lines' => [['product_id' => $this->product, 'qty' => 2, 'line_total_baisas' => 2000]]]);
        $validLines = $round->priced_lines;
        $round->update(['priced_lines' => [['product_id' => $this->product, 'qty' => 2, 'line_total_baisas' => 2000, 'accounting_only' => true]]]);
        $this->kitchenPost('submissions', $i)->assertUnprocessable()->assertJsonPath('errors.0.code', 'accounting_only_round');
        $round->update(['priced_lines' => $validLines]);
        $this->kitchenPost('submissions', $i)->assertCreated()->assertJsonPath('data.state', 'waiting_approval');
        $reject = $this->action($i, 'reject');
        $this->kitchenPost('events', $reject)->assertOk();
        $this->kitchenPost('events', $reject)->assertOk();
        $this->kitchenPost('events', $this->action($i, 'approve'))->assertConflict();
        $this->assertDatabaseCount('pos_kv2_deliveries', 0);
    }

    public function test_paused_queue_survives_cancel_and_possible_send_generates_cancel_notice(): void
    {
        $b = $this->bundle;
        $b['destinations'][0]['paused'] = true;
        app(Configuration::class)->setRouting(100, [10], 10, $b, 'owner');
        $this->activate();
        $i = $this->submitted();
        $id = DB::table('pos_kv2_deliveries')->value('uuid');
        $this->kitchenPost('delivery-results', $this->envelope('claim_delivery', ['delivery_uuid' => $id, 'attempt_uuid' => (string) Str::uuid()]))->assertConflict();
        $this->kitchenPost('delivery-results', $this->envelope('resume_delivery', ['delivery_uuid' => $id]))->assertOk();
        $attempt = (string) Str::uuid();
        $this->kitchenPost('delivery-results', $this->envelope('claim_delivery', ['delivery_uuid' => $id, 'attempt_uuid' => $attempt]))->assertOk();
        $this->kitchenPost('delivery-results', $this->envelope('delivery_result', ['delivery_uuid' => $id, 'attempt_uuid' => $attempt, 'result' => 'sending']))->assertOk();
        $this->kitchenPost('events', $this->action($i, 'cancel'))->assertOk();
        $this->assertDatabaseHas('pos_kv2_deliveries', ['purpose' => 'cancel', 'state' => 'paused']);
        $this->assertDatabaseHas('pos_kv2_deliveries', ['uuid' => $id, 'state' => 'sending']);
        $this->assertSame('review_required', Compatibility::preparation(100, 10, $i['order_uuid'])[0]['evidence']);
    }

    public function test_handheld_only_can_host_approve_complete_and_handover(): void
    {
        // Same enrollment and contract; no fixed till dependency.
        $this->device->forceFill(['device_type' => 'handheld'])->save();
        $i = $this->submitted(['source' => 'handheld']);
        $r = $this->kitchenPost('events', $this->action($i, 'done_all', ['area_uuid' => $this->area]))->assertOk()->json('data');
        $this->kitchenPost('events', $this->action($i, 'served', ['ready_cycle' => $r['ready_cycle']]))->assertOk()->assertJsonPath('data.state', 'served');
    }

    public function test_old_clients_cannot_claim_prints_after_cutover_and_journal_survives_disable(): void
    {
        $table = $this->seatingTable();
        $seat = $this->seatingRow($table);
        $order = $this->seatingOrder($seat);
        $round = $this->seatingRound($seat, $order);
        try {
            app(ClaimKitchenTicketAction::class)->handle($this->device, ['ticket_key' => 'round:'.$round->id]);
            $this->fail();
        } catch (QrDineInException $e) {
            $this->assertSame('kitchen_v2_required', $e->codeName);
        }
        $this->submitted();
        config(['kitchen.enabled' => false]);
        $this->kitchenGet('kitchen-v2/snapshot')->assertStatus(503);
        $this->assertDatabaseCount('pos_kv2_submissions', 1);
        $this->assertTrue(Compatibility::ownsBranch(100, 10));
    }

    public function test_kitchen_released_order_still_requires_expired_session_for_cancellation(): void
    {
        app(Configuration::class)->setPolicy(100, [10], 10, 'qr_web', 'immediate', 'owner');
        $this->activate();
        $i = $this->intent(['source' => 'qr_web']);
        $table = $this->seatingTable();
        $seat = $this->seatingRow($table);
        $order = $this->seatingOrder($seat, ['uuid' => $i['order_uuid'], 'source' => 'qr_web', 'status' => 'held', 'order_type' => 'quick', 'table_id' => null]);
        $round = $this->seatingRound($seat, $order, ['needs_review' => false, 'priced_lines' => [['product_id' => $this->product, 'qty' => 2, 'line_total_baisas' => 2000]]]);
        $i['round_id'] = $round->id;
        $this->kitchenPost('submissions', $i)->assertCreated();
        try {
            app(CancelExpiredQuickOrdersAction::class)->preview($this->device, $order->uuid);
            $this->fail();
        } catch (QrChargeException $e) {
            $this->assertSame('qr_session_active', $e->codeName);
        }
        $this->assertSame('held', $order->fresh()->status);
    }

    public function test_grants_are_signed_expiring_and_bound_to_device_epoch_certificate_and_scopes(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $file = tempnam(sys_get_temp_dir(), 'k1-key-');
        file_put_contents($file, $pem);
        chmod($file, 0600);
        $public = openssl_pkey_get_details($key)['key'];
        config(['kitchen.private_key_path' => $file, 'kitchen.key_id' => 'test', 'kitchen.public_keys' => ['test' => $public]]);
        try {
            $response = $this->kitchenPost('grants', ['kind' => 'staff'])->assertOk()->json('data');
            $claims = (array) JWT::decode($response['grant'], ['test' => new Key($public, 'ES256')]);
            $this->assertSame(43200, $claims['exp'] - $claims['iat']);
            $this->assertSame([$this->area], $claims['area_ids']);
            $this->assertObjectHasProperty('x5t#S256', $claims['cnf']);
            $identity = ['company_id' => 100, 'branch_id' => 10, 'device_id' => $this->device->id, 'assignment' => $this->device->assignment_activated_at->toIso8601String(), 'epoch' => 1, 'cnf' => (array) $claims['cnf'], 'area_ids' => [$this->area]];
            $g = app(Grants::class);
            $this->assertSame(1, $g->verify($response['grant'], $identity, 'kitchen.complete')['staff_id']);
            foreach ([['epoch' => 999], ['device_id' => 999], ['cnf' => ['x5t#S256' => 'wrong']], ['area_ids' => []]] as $wrong) {
                try {
                    $g->verify($response['grant'], [...$identity, ...$wrong], 'kitchen.complete');
                    $this->fail();
                } catch (KitchenFault $e) {
                    $this->assertSame('grant_identity_mismatch', $e->reason);
                }
            }
            foreach (['wrong-audience', 'overlong', 'expired', 'wrong-scope'] as $case) {
                $altered = Wire::read(Wire::json($claims));
                if ($case === 'wrong-audience') {
                    $altered['aud'] = 'other';
                }if ($case === 'overlong') {
                    $altered['exp'] = $altered['iat'] + 43201;
                }if ($case === 'expired') {
                    $altered['iat'] = time() - 50000;
                    $altered['nbf'] = $altered['iat'];
                    $altered['exp'] = time() - 1;
                }if ($case === 'wrong-scope') {
                    $altered['scopes'] = ['kitchen.host'];
                }$jwt = JWT::encode($altered, $pem, 'ES256', 'test');
                try {
                    $g->verify($jwt, $identity, 'kitchen.complete');
                    $this->fail($case);
                } catch (KitchenFault $e) {
                    $this->assertSame(403, $e->status);
                }
            }
            $tampered = $response['grant'];
            $parts = explode('.', $tampered);
            $parts[1][3] = $parts[1][3] === 'a' ? 'b' : 'a';
            try {
                $g->verify(implode('.', $parts), $identity, 'kitchen.complete');
                $this->fail();
            } catch (KitchenFault $e) {
                $this->assertSame('grant_invalid', $e->reason);
            }
            DB::table('pos_staff')->where('id', 1)->update(['status' => 'inactive']);
            $this->kitchenPost('grants', ['kind' => 'staff'])->assertForbidden();
        } finally {
            unlink($file);
        }
    }

    public function test_canonical_json_vector_preserves_quantity_and_unicode(): void
    {
        $a = ['z' => '2.000000', 'a' => ['name' => 'سمك', 'modifiers' => ['  no salt  ', 'lemon']]];
        $canonical = '{"a":{"modifiers":["  no salt  ","lemon"],"name":"سمك"},"z":"2.000000"}';
        $this->assertSame(hash('sha256', $canonical), Wire::hash($a));
        $this->assertSame(Wire::hash($a), Wire::hash(['a' => $a['a'], 'z' => $a['z']]));
    }

    public function test_authenticated_foreign_scope_cannot_read_or_mutate_a_submission_or_delivery(): void
    {
        $i = $this->submitted();
        $delivery = DB::table('pos_kv2_deliveries')->value('uuid');
        foreach ([[100, 11, 2], [200, 20, 3]] as [$company,$branch,$staff]) {
            $d = $this->seatingDevice('handheld', $branch, $company, ['assignment_activated_at' => now()]);
            $this->seedPosStaff([$staff], $company, $branch);
            $c = app(Configuration::class);
            $c->setRouting($company, [$branch], $branch, $this->bundle, 'test-owner');
            $c->enroll($company, [$branch], $d->id, str_repeat('c', 64), [$this->area], 'test-admin');
            $c->assign($company, [$branch], $d->id, 'test-admin', 'Isolated synthetic host, no old printer executor.');
            DB::table('pos_kv2_branches')->where('company_id', $company)->where('branch_id', $branch)->update(['mode' => 'active', 'applied_version' => 1]);
            $this->kitchenGet('kitchen-v2/snapshot', $d)->assertOk()->assertJsonCount(0, 'data.cards');
            $this->kitchenPost('events', $this->action($i, 'approve'), $d, $staff)->assertNotFound();
            $this->kitchenPost('delivery-results', $this->envelope('claim_delivery', ['delivery_uuid' => $delivery, 'attempt_uuid' => (string) Str::uuid()]), $d, $staff)->assertNotFound();
        }
        $this->assertDatabaseCount('pos_kv2_submissions', 1);
        $this->assertDatabaseCount('pos_kv2_deliveries', 1);
    }

    public function test_kds_valid_grant_completes_only_assigned_area_and_revoked_staff_stops_it(): void
    {
        $i = $this->submitted();
        $kds = $this->seatingDevice('kitchen_display', attributes: ['assignment_activated_at' => now()]);
        app(Configuration::class)->enroll(100, [10], $kds->id, str_repeat('b', 64), [$this->area], 'admin');
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $file = tempnam(sys_get_temp_dir(), 'k1-key-');
        file_put_contents($file, $pem);
        chmod($file, 0600);
        config(['kitchen.private_key_path' => $file, 'kitchen.key_id' => 'test', 'kitchen.public_keys' => ['test' => openssl_pkey_get_details($key)['key']]]);
        try {
            $binding = DB::table('pos_kv2_devices')->where('device_id', $kds->id)->first();
            $branch = DB::table('pos_kv2_branches')->where('branch_id', 10)->first();
            $access = new Access($kds, $branch, $binding, 1);
            $grant = app(Grants::class)->issue($access->identity(), ['kitchen.complete', 'kitchen.undo'], 1)['grant'];
            $this->kitchenGet('kitchen-v2/snapshot', $kds)->assertOk()->assertJsonCount(1, 'data.cards')->assertJsonMissingPath('data.cards.0.staff_id')->assertJsonMissingPath('data.cards.0.customer');
            $this->app['auth']->forgetGuards();
            $this->flushHeaders();
            $this->withToken($kds->plainTextToken)->withHeader('X-Kitchen-Grant', $grant)->postJson('/api/v1/device/kitchen-v2/events', $this->action($i, 'done_all', ['area_uuid' => $this->area]))->assertOk()->assertJsonPath('data.state', 'ready');
            DB::table('pos_staff')->where('id', 1)->update(['status' => 'inactive']);
            $this->app['auth']->forgetGuards();
            $this->withToken($kds->plainTextToken)->withHeader('X-Kitchen-Grant', $grant)->postJson('/api/v1/device/kitchen-v2/events', $this->action($i, 'undo', ['area_uuid' => $this->area, 'line_uuid' => $i['lines'][0]['line_uuid']]))->assertForbidden();
        } finally {
            unlink($file);
        }
    }

    public function test_reassignment_and_oversize_requests_leave_intents_unacknowledged(): void
    {
        $i = $this->intent();
        $i['context']['notes'] = str_repeat('x', 1048577);
        $this->kitchenPost('submissions', $i)->assertStatus(413);
        $this->assertDatabaseCount('pos_kv2_submissions', 0);
        $this->device->forceFill(['assignment_activated_at' => now()->addMinute()])->save();
        $this->kitchenPost('submissions', $this->intent())->assertForbidden();
    }

    public function test_snapshot_paginates_active_work_without_age_cutoff(): void
    {
        $i = $this->submitted();
        DB::table('pos_kv2_submissions')->where('uuid', $i['submission_uuid'])->update(['released_at' => now()->subDays(100)]);
        $this->kitchenGet('kitchen-v2/snapshot')->assertOk()->assertJsonCount(1, 'data.cards');
        $this->kitchenGet('kitchen-v2/snapshot?after=1')->assertOk()->assertJsonCount(0, 'data.cards');
        $this->kitchenGet('kitchen-v2/events?after=0')->assertOk()->assertJsonMissingPath('data.events.0.payload')->assertJsonMissingPath('data.events.0.staff_id');
    }

    public function test_signed_coordinator_batch_reconciles_once_and_rolls_back_causal_gaps(): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $file = tempnam(sys_get_temp_dir(), 'k1-key-');
        file_put_contents($file, $pem);
        chmod($file, 0600);
        config(['kitchen.private_key_path' => $file, 'kitchen.key_id' => 'test', 'kitchen.public_keys' => ['test' => openssl_pkey_get_details($key)['key']]]);
        try {
            $grant = $this->kitchenPost('grants', ['kind' => 'staff'])->assertOk()->json('data.grant');
            $i = $this->intent();
            $done = $this->action($i, 'done_all', ['area_uuid' => $this->area]);
            $wrap = fn ($event) => ['device_id' => $this->device->id, 'grant' => $grant, 'event' => $event];
            $this->kitchenPost('sync', ['events' => [$wrap($done), $wrap($i)]])->assertNotFound();
            $this->assertDatabaseCount('pos_kv2_submissions', 0);
            $batch = ['events' => [$wrap($i), $wrap($done)]];
            $this->kitchenPost('sync', $batch)->assertOk()->assertJsonPath('data.receipts.1.state', 'ready');
            $this->kitchenPost('sync', $batch)->assertOk()->assertJsonPath('data.receipts.0.replayed', true)->assertJsonPath('data.receipts.1.replayed', true);
            $this->kitchenPost('submissions', $i)->assertCreated()->assertJsonPath('data.replayed', true);
            $this->assertDatabaseCount('pos_kv2_deliveries', 1);
            $this->assertStringNotContainsString($grant, DB::table('pos_kv2_events')->pluck('payload')->implode(''));
            $second = $this->intent();
            $bad = $this->action($second, 'done_all', ['area_uuid' => (string) Str::uuid()]);
            $this->kitchenPost('sync', ['events' => [$wrap($second), $wrap($bad)]])->assertForbidden();
            $this->assertDatabaseCount('pos_kv2_submissions', 1);
        } finally {
            unlink($file);
        }
    }

    public function test_cancelled_claim_is_fenced_and_explicit_reassignment_is_audited(): void
    {
        $i = $this->submitted();
        $id = DB::table('pos_kv2_deliveries')->value('uuid');
        $attempt = (string) Str::uuid();
        $this->kitchenPost('delivery-results', $this->envelope('claim_delivery', ['delivery_uuid' => $id, 'attempt_uuid' => $attempt]))->assertOk();
        $b = $this->bundle;
        $new = (string) Str::uuid();
        $b['destinations'][] = [...$b['destinations'][0], 'id' => $new, 'address' => '192.168.100.174'];
        app(Configuration::class)->setRouting(100, [10], 10, $b, 'owner');
        $this->activate();
        $action = $this->envelope('reassign_delivery', ['delivery_uuid' => $id, 'destination_uuid' => $new, 'reason' => 'Operator reviewed unsent job and selected second printer']);
        $this->kitchenPost('delivery-results', $action)->assertOk();
        $this->kitchenPost('delivery-results', $action)->assertOk()->assertJsonPath('data.replayed', true);
        $this->kitchenPost('delivery-results', $this->envelope('delivery_result', ['delivery_uuid' => $id, 'attempt_uuid' => $attempt, 'result' => 'sending']))->assertConflict();
        $this->assertDatabaseHas('pos_kv2_deliveries', ['uuid' => $id, 'state' => 'cancelled']);
        $this->assertDatabaseHas('pos_kv2_attempts', ['uuid' => $attempt, 'state' => 'cancelled']);
        $this->assertDatabaseCount('pos_kv2_deliveries', 2);
        $this->kitchenPost('events', $this->action($i, 'cancel'))->assertOk();
        $this->assertSame(0, DB::table('pos_kv2_deliveries')->where('state', '!=', 'cancelled')->count());
    }

    public function test_frozen_client_contract_fixtures_match_php_routing_and_hashing(): void
    {
        $dir = resource_path('kitchen/v1');
        $bundle = json_decode(file_get_contents($dir.'/configuration.json'), true, 512, JSON_THROW_ON_ERROR);
        $intent = json_decode(file_get_contents($dir.'/submission.json'), true, 512, JSON_THROW_ON_ERROR);
        $expected = json_decode(file_get_contents($dir.'/routing-expected.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($expected, Routing::resolve(Routing::validate($bundle), $intent['lines']));
        $vector = json_decode(file_get_contents($dir.'/canonicalization.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($vector['sha256'], Wire::hash($vector['input']));
    }

    private function markKitchenDeliverySent(string $uuid): void
    {
        $attempt = (string) Str::uuid();
        $this->kitchenPost('delivery-results', $this->envelope('claim_delivery', ['delivery_uuid' => $uuid, 'attempt_uuid' => $attempt]))->assertOk();
        foreach (['sending', 'sent_unconfirmed'] as $state) {
            $this->kitchenPost('delivery-results', $this->envelope('delivery_result', ['delivery_uuid' => $uuid, 'attempt_uuid' => $attempt, 'result' => $state]))->assertOk();
        }
    }

    public function test_cumulative_sent_projection_survives_delta_noop_and_cancel(): void
    {
        $i = $this->intent();
        $i['lines'][] = [...$i['lines'][0], 'line_uuid' => (string) Str::uuid(), 'name' => 'Second fish'];
        $this->kitchenPost('submissions', $i)->assertCreated();
        $this->markKitchenDeliverySent(DB::table('pos_kv2_deliveries')->value('uuid'));
        $lines = $i['lines'];
        $lines[1]['notes'] = 'Changed only B';
        $this->kitchenPost('events', $this->action($i, 'amend', ['replacement' => ['lines' => $lines]]))->assertOk();
        $delta = DB::table('pos_kv2_deliveries')->where('revision', 2)->sole();
        $this->assertSame([$lines[1]], Wire::read($delta->snapshot)['lines']);
        $this->markKitchenDeliverySent($delta->uuid);
        $i['revision'] = 2;
        $this->kitchenPost('events', $this->action($i, 'amend', ['replacement' => ['lines' => $lines]]))->assertOk();
        $this->assertSame(0, DB::table('pos_kv2_deliveries')->where('revision', 3)->count());
        $i['revision'] = 3;
        $this->kitchenPost('events', $this->action($i, 'cancel'))->assertOk();
        $cancel = Wire::read(DB::table('pos_kv2_deliveries')->where('purpose', 'cancel')->sole()->snapshot);
        $this->assertEqualsCanonicalizing(array_column($lines, 'line_uuid'), $cancel['removed_line_uuids']);
        $this->assertEqualsCanonicalizing($lines, $cancel['removed_lines']);
        $this->assertSame($i['submission_uuid'], $cancel['submission_uuid']);
        $this->assertSame($i['context'], $cancel['context']);
    }

    public function test_unsent_change_notice_is_replaced_against_all_sent_lines(): void
    {
        $i = $this->submitted();
        $this->markKitchenDeliverySent(DB::table('pos_kv2_deliveries')->value('uuid'));
        $lines = $i['lines'];
        $lines[0]['notes'] = 'First amendment';
        $this->kitchenPost('events', $this->action($i, 'amend', ['replacement' => ['lines' => $lines]]))->assertOk();
        $lines[0]['notes'] = 'Final amendment';
        $i['revision'] = 2;
        $this->kitchenPost('events', $this->action($i, 'amend', ['replacement' => ['lines' => $lines]]))->assertOk();
        $this->assertDatabaseHas('pos_kv2_deliveries', ['revision' => 2, 'state' => 'cancelled']);
        $d = DB::table('pos_kv2_deliveries')->where('revision', 3)->sole();
        $this->assertSame('change', $d->purpose);
        $this->assertSame($lines, Wire::read($d->snapshot)['lines']);
    }

    public function test_original_and_reprint_payloads_include_frozen_order_identity(): void
    {
        $i = $this->submitted();
        $row = $this->kitchenGet('kitchen-v2/deliveries')->assertOk()->json('data.deliveries.0');
        foreach (['submission_uuid', 'order_uuid', 'round_uuid', 'source', 'context', 'revision'] as $key) {
            $this->assertSame($i[$key], $row['snapshot'][$key]);
        }
        $this->markKitchenDeliverySent($row['delivery_uuid']);
        $this->kitchenPost('delivery-results', $this->envelope('reprint', ['delivery_uuid' => $row['delivery_uuid'], 'reason' => 'Operator checked paper']))->assertOk();
        $copy = Wire::read(DB::table('pos_kv2_deliveries')->where('purpose', 'reprint')->sole()->snapshot);
        $this->assertSame($i['order_uuid'], $copy['order_uuid']);
        $this->assertSame($i['context'], $copy['context']);
        $this->assertSame($row['delivery_uuid'], $copy['reprint_of']);
    }

    public function test_context_only_amendment_updates_board_and_emits_identified_change_notice(): void
    {
        $i = $this->submitted();
        $this->markKitchenDeliverySent(DB::table('pos_kv2_deliveries')->value('uuid'));
        $context = ['reference' => 'TEST-NEW', 'table' => 'B9', 'notes' => 'Use the side entrance'];
        $this->kitchenPost('events', $this->action($i, 'amend', ['replacement' => ['context' => $context]]))->assertOk();
        $this->kitchenGet('kitchen-v2/snapshot')->assertOk()->assertJsonPath('data.cards.0.context', $context);
        $d = Wire::read(DB::table('pos_kv2_deliveries')->where('revision', 2)->sole()->snapshot);
        $this->assertSame([], $d['lines']);
        $this->assertTrue($d['context_changed']);
        $this->assertSame($i['context'], $d['previous_context']);
        $this->assertSame($context, $d['context']);
        $this->assertSame($i['round_uuid'], $d['round_uuid']);
        $this->assertSame($i['context'], Wire::read(DB::table('pos_kv2_deliveries')->where('revision', 1)->sole()->snapshot)['context']);
    }

    public function test_cancel_after_route_move_replaces_unsent_cancel_without_identity_collision(): void
    {
        $i = $this->submitted();
        $this->markKitchenDeliverySent(DB::table('pos_kv2_deliveries')->value('uuid'));
        $nextPrinter = (string) Str::uuid();
        $b = $this->bundle;
        $b['destinations'][] = [...$b['destinations'][0], 'id' => $nextPrinter, 'address' => '192.168.100.174'];
        $b['fallback']['destinations'] = [$nextPrinter];
        app(Configuration::class)->setRouting(100, [10], 10, $b, 'owner');
        $this->activate();
        $version = (int) DB::table('pos_kv2_branches')->where('branch_id', 10)->value('applied_version');
        $this->kitchenPost('events', $this->action($i, 'amend', ['replacement' => ['policy_version' => $version]]))->assertOk();
        $i['revision'] = 2;
        $cancel = $this->action($i, 'cancel');
        $this->kitchenPost('events', $cancel)->assertOk()->assertJsonPath('data.state', 'cancelled');
        $this->kitchenPost('events', $cancel)->assertOk()->assertJsonPath('data.replayed', true);
        $pending = DB::table('pos_kv2_deliveries')->where('state', 'queued')->get();
        $this->assertCount(1, $pending);
        $this->assertSame('cancel', $pending[0]->purpose);
        $this->assertSame($this->printer, $pending[0]->destination_uuid);
        $this->assertSame($cancel['event_id'], $pending[0]->copy_key);
        $this->assertSame([$i['lines'][0]['line_uuid']], Wire::read($pending[0]->snapshot)['removed_line_uuids']);
    }

    public function test_numeric_source_identifiers_match_item_routes_and_keep_original_replay_identity(): void
    {
        $specific = (string) Str::uuid();
        $this->bundle['destinations'][] = [...$this->bundle['destinations'][0], 'id' => $specific, 'name' => 'Specific'];
        $this->bundle['rules'] = [['kind' => 'item', 'reference_id' => (string) $this->product, 'areas' => [$this->area], 'destinations' => [$specific]]];
        app(Configuration::class)->setRouting(100, [10], 10, $this->bundle, 'review-regression');
        $this->activate();
        $i = $this->intent();
        $i['lines'][0]['product_id'] = (string) $this->product;
        $this->kitchenPost('submissions', $i)->assertCreated();
        $this->assertSame($specific, DB::table('pos_kv2_deliveries')->sole()->destination_uuid);
        $this->kitchenPost('submissions', $i)->assertCreated()->assertJsonPath('data.replayed', true);
        $changed = $i;
        $changed['lines'][0]['product_id'] = $this->product;
        $this->kitchenPost('submissions', $changed)->assertConflict()->assertJsonPath('errors.0.code', 'event_payload_conflict');
        $i['lines'][0]['quantity'] = '3.000000';
        $this->kitchenPost('events', $this->action($i, 'amend', ['replacement' => ['lines' => $i['lines']]]))->assertOk();
        $this->assertSame([$specific], DB::table('pos_kv2_deliveries')->where('state', 'queued')->pluck('destination_uuid')->all());
    }

    public function test_numeric_category_ids_are_normalized_before_category_validation_and_routing(): void
    {
        $category = DB::table('pos_product_categories')->insertGetId(['company_id' => 100, 'name' => 'Mains', 'uuid' => (string) Str::uuid()]);
        DB::table('pos_products')->where('id', $this->product)->update(['category_id' => $category]);
        $specific = (string) Str::uuid();
        $this->bundle['destinations'][] = [...$this->bundle['destinations'][0], 'id' => $specific, 'name' => 'Category'];
        $this->bundle['rules'] = [['kind' => 'category', 'reference_id' => (string) $category, 'areas' => [$this->area], 'destinations' => [$specific]]];
        app(Configuration::class)->setRouting(100, [10], 10, $this->bundle, 'review-regression');
        $this->activate();
        $i = $this->intent();
        $i['lines'][0]['product_id'] = (string) $this->product;
        $i['lines'][0]['category_id'] = (string) $category;
        $this->kitchenPost('submissions', $i)->assertCreated();
        $this->assertSame($specific, DB::table('pos_kv2_deliveries')->sole()->destination_uuid);
    }

    private function withK3Issuer(callable $test): void
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        $file = tempnam(sys_get_temp_dir(), 'k3-key-');
        file_put_contents($file, $pem);
        chmod($file, 0600);
        $csr = openssl_csr_new(['commonName' => 'SYNTHETIC K3 ROOT'], $key, ['digest_alg' => 'sha256']);
        $certificate = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
        openssl_x509_export($certificate, $root);
        $rootFile = tempnam(sys_get_temp_dir(), 'k3-root-');
        file_put_contents($rootFile, $root);
        config(['kitchen.private_key_path' => $file, 'kitchen.key_id' => 'test', 'kitchen.public_keys' => ['test' => openssl_pkey_get_details($key)['key']], 'kitchen.root_certificate_path' => $rootFile, 'kitchen.ca_private_key_path' => $file]);
        try {
            $test();
        } finally {
            unlink($file);
            unlink($rootFile);
        }
    }

    public function test_k3_actual_dart_causal_trace_reconciles_once(): void
    {
        $this->withK3Issuer(function (): void {
            $trace = json_decode(file_get_contents(base_path('tests/Fixtures/kitchen-v1/k3-dart-trace.json')), true, 512, JSON_THROW_ON_ERROR);
            $bundle = $trace['bundle'];
            $version = (int) DB::table('pos_kv2_branches')->where('branch_id', 10)->value('applied_version');
            DB::table('pos_kv2_configurations')->where('branch_id', 10)->where('version', $version)->update(['bundle' => Wire::json($bundle), 'bundle_hash' => Wire::hash($bundle)]);
            DB::table('pos_kv2_devices')->where('device_id', $this->device->id)->update(['areas' => Wire::json(array_column($bundle['areas'], 'id'))]);
            $staff = $this->kitchenPost('grants', ['kind' => 'staff'])->assertOk()->json('data.grant');
            $host = $this->kitchenPost('grants', ['kind' => 'host'])->assertOk()->json('data.grant');
            $events = [];
            foreach ($trace['events'] as $event) {
                if (isset($event['policy_version'])) {
                    $event['policy_version'] = $version;
                }
                if (isset($event['replacement']['policy_version'])) {
                    $event['replacement']['policy_version'] = $version;
                }
                $events[] = ['device_id' => $this->device->id, 'grant' => in_array($event['action'], ['claim_delivery', 'delivery_result'], true) ? $host : $staff, 'event' => $event];
            }
            $response = $this->kitchenPost('sync', ['events' => $events])->assertOk()->json('data');
            $this->assertCount(count($events), $response['receipts']);
            $this->kitchenPost('sync', ['events' => $events])->assertOk()->assertJsonPath('data.receipts.0.replayed', true);
            $order = DB::table('pos_kv2_submissions')->where('uuid', $trace['final_order']['submission_uuid'])->first();
            $this->assertSame('served', $order->state);
            $this->assertSame($trace['final_order']['ready_cycle'], $order->ready_cycle);
            $this->assertSame(2, (int) $order->revision);
            foreach ($trace['deliveries'] as $delivery) {
                $actual = DB::table('pos_kv2_deliveries')->where('uuid', $delivery['delivery_uuid'])->first();
                $this->assertNotNull($actual, 'Dart delivery identity must match PHP');
                $this->assertSame($delivery['state'], $actual->state);
                $this->assertSame($delivery['purpose'], $actual->purpose);
                $this->assertSame($delivery['snapshot']['lines'], Wire::read($actual->snapshot)['lines']);
            }
            $this->assertDatabaseCount('pos_kv2_attempts', 2);
            $this->assertDatabaseCount('pos_orders', 0);
            $this->assertDatabaseCount('pos_payments', 0);
        });
    }

    public function test_k3_host_bootstrap_is_scoped_and_refresh_removes_revoked_staff_and_peers(): void
    {
        $this->withK3Issuer(function (): void {
            $runtime = $this->kitchenGet('kitchen-v2/runtime')->assertOk()->json('data');
            $this->assertSame(100, $runtime['runtime']['identity']['company_id']);
            $this->assertSame(10, $runtime['runtime']['identity']['branch_id']);
            $this->assertSame('P-256', $runtime['public_keys']['test']['crv']);
            $this->assertArrayNotHasKey('d', $runtime['public_keys']['test']);
            $this->assertContains('kitchen.complete', $runtime['runtime']['staff'][1]);
            $other = $this->seatingDevice('handheld', attributes: ['assignment_activated_at' => now()]);
            app(Configuration::class)->enroll(100, [10], $other->id, str_repeat('b', 64), [$this->area], 'admin');
            $this->kitchenGet('kitchen-v2/runtime', $other)->assertForbidden();
            DB::table('pos_staff')->where('id', 1)->update(['status' => 'inactive']);
            $other->status = 'inactive';
            $other->save();
            $fresh = $this->kitchenGet('kitchen-v2/runtime')->assertOk()->json('data.runtime');
            $this->assertArrayNotHasKey(1, $fresh['staff']);
            $this->assertArrayNotHasKey($other->id, $fresh['peers']);
            $this->assertNotSame($runtime['runtime']['host_credential_id'], $fresh['host_credential_id']);
        });
    }

    public function test_k3_sync_preserves_grant_bounded_acceptance_time_and_rejects_future_time(): void
    {
        $this->withK3Issuer(function (): void {
            $grant = $this->kitchenPost('grants', ['kind' => 'staff'])->assertOk()->json('data.grant');
            $accepted = now()->toIso8601String();
            $i = $this->intent();
            $this->travel(15)->minutes();
            $this->kitchenPost('sync', ['events' => [['device_id' => $this->device->id, 'grant' => $grant, 'event' => $i, 'accepted_at' => $accepted]]])->assertOk();
            $row = DB::table('pos_kv2_submissions')->where('uuid', $i['submission_uuid'])->first();
            $this->assertTrue(CarbonImmutable::parse($row->released_at)->equalTo(CarbonImmutable::parse($accepted)));
            $bad = $this->intent();
            $this->kitchenPost('sync', ['events' => [['device_id' => $this->device->id, 'grant' => $grant, 'event' => $bad, 'accepted_at' => now()->addHour()->toIso8601String()]]])->assertUnprocessable()->assertJsonPath('errors.0.code', 'acceptance_time_invalid');
            $this->assertDatabaseCount('pos_kv2_submissions', 1);
        });
    }

    public function test_k3_certificate_provisioning_requires_enrolled_key_and_replays_the_same_certificate(): void
    {
        $this->withK3Issuer(function (): void {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            $csr = openssl_csr_new(['commonName' => 'SYNTHETIC DEVICE'], $key, ['digest_alg' => 'sha256']);
            openssl_csr_export($csr, $csrPem);
            $old = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
            openssl_x509_export($old, $oldPem);
            $input = ['csr' => $csrPem, 'certificate' => $oldPem];
            $this->kitchenPost('certificate', $input)->assertForbidden()->assertJsonPath('errors.0.code', 'certificate_not_enrolled');
            DB::table('pos_kv2_devices')->where('device_id', $this->device->id)->update(['certificate_thumbprint' => openssl_x509_fingerprint($oldPem, 'sha256')]);
            $first = $this->kitchenPost('certificate', $input)->assertOk()->json('data');
            $second = $this->kitchenPost('certificate', $input)->assertOk()->json('data');
            $this->assertSame($first, $second);
            $this->assertSame(1, openssl_x509_verify($first['certificate'], openssl_pkey_get_public($first['root_certificate'])));
            $this->assertStringContainsString('CA:FALSE', openssl_x509_parse($first['certificate'])['extensions']['basicConstraints']);
            $this->assertSame($first['thumbprint'], DB::table('pos_kv2_devices')->where('device_id', $this->device->id)->value('certificate_thumbprint'));
            $wrong = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            $wrongCsr = openssl_csr_new(['commonName' => 'OTHER'], $wrong, ['digest_alg' => 'sha256']);
            openssl_csr_export($wrongCsr, $wrongPem);
            $this->kitchenPost('certificate', ['csr' => $wrongPem, 'certificate' => $oldPem])->assertUnprocessable()->assertJsonPath('errors.0.code', 'certificate_key_mismatch');
            DB::table('pos_kv2_devices')->where('device_id', $this->device->id)->update(['enabled' => false]);
            $this->kitchenPost('certificate', $input)->assertForbidden();
        });
    }

    public function test_k3_runtime_and_admission_both_hold_branch_unavailable_products(): void
    {
        $this->withK3Issuer(function (): void {
            DB::table('pos_branch_product')->insert(['branch_id' => 10, 'product_id' => $this->product, 'is_available' => false]);
            $runtime = $this->kitchenGet('kitchen-v2/runtime')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('data.runtime');
            $this->assertArrayNotHasKey($this->product, $runtime['catalogue']);
            $this->kitchenPost('submissions', $this->intent())->assertUnprocessable()->assertJsonPath('errors.0.code', 'catalogue_hold');
            $this->assertDatabaseCount('pos_kv2_submissions', 0);
        });
    }

    public function test_k4_financial_link_replays_preserve_original_ids_and_done_work(): void
    {
        $intent = $this->submitted();
        $this->kitchenPost('events', $this->action($intent, 'done_all', ['area_uuid' => $this->area]))->assertOk();
        $order = $this->seatingOrder($this->seatingRow($this->seatingTable()));
        $event = new SyncEvent(['client_event_id' => $intent['domain_event_uuid']]);
        for ($i = 0; $i < 2; $i++) {
            $this->travel(2)->seconds();
            DomainLinkage::forEvent($event, $this->device, ['order_id' => $order->id]);
        }
        $this->assertDatabaseHas('pos_kv2_submissions', ['uuid' => $intent['submission_uuid'], 'order_uuid' => $intent['order_uuid'],
            'order_id' => $order->id, 'link_state' => 'linked', 'state' => 'ready']);
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseCount('pos_kv2_deliveries', 1);
        $this->assertDatabaseCount('pos_payments', 0);
        $this->assertDatabaseCount('pos_stock_movements', 0);
        $this->assertSame('done', Compatibility::preparation(100, 10, $intent['order_uuid'])[0]['evidence']);
    }

    public function test_k4_financial_link_does_not_adopt_other_device_assignment_or_company(): void
    {
        $intent = $this->submitted();
        $order = $this->seatingOrder($this->seatingRow($this->seatingTable()));
        $event = new SyncEvent(['client_event_id' => $intent['domain_event_uuid']]);
        $other = $this->seatingDevice(attributes: ['assignment_activated_at' => now()]);
        DomainLinkage::forEvent($event, $other, ['order_id' => $order->id]);
        $this->device->assignment_activated_at = now()->addHour();
        DomainLinkage::forEvent($event, $this->device, ['order_id' => $order->id]);
        $this->assertDatabaseHas('pos_kv2_submissions', ['uuid' => $intent['submission_uuid'], 'order_id' => null, 'link_state' => 'pending']);
        $this->assertDatabaseCount('pos_kv2_deliveries', 1);
    }

    public function test_k4_financial_review_keeps_physical_evidence_without_redispatch(): void
    {
        $intent = $this->submitted();
        $seat = $this->seatingRow($this->seatingTable());
        $order = $this->seatingOrder($seat);
        $round = $this->seatingRound($seat, $order, ['needs_review' => true, 'status' => 'pending_confirmation']);
        DomainLinkage::forEvent(new SyncEvent(['client_event_id' => $intent['domain_event_uuid']]),
            $this->device, ['order_id' => $order->id, 'round_id' => $round->id]);
        $this->assertDatabaseHas('pos_kv2_submissions', ['uuid' => $intent['submission_uuid'], 'order_id' => $order->id,
            'round_id' => $round->id, 'link_state' => 'review_required']);
        $this->assertDatabaseCount('pos_kv2_deliveries', 1);
        $this->assertDatabaseCount('pos_stock_movements', 0);
    }

    public function test_k4_handheld_round_on_a_till_bill_uses_the_round_source(): void
    {
        $handheld = $this->seatingDevice('handheld', attributes: ['assignment_activated_at' => now()]);
        app(Configuration::class)->enroll(100, [10], $handheld->id, str_repeat('b', 64), [$this->area], 'test');
        $seat = $this->seatingRow($this->seatingTable());
        $order = $this->seatingOrder($seat, ['source' => 'main_pos']);
        $round = $this->seatingRound($seat, $order, ['resolved_by_device_id' => $handheld->id,
            'priced_lines' => [['product_id' => $this->product, 'qty' => 2, 'line_total_baisas' => 2000]]]);
        $intent = $this->intent(['source' => 'handheld', 'order_uuid' => $order->uuid, 'round_id' => $round->id]);
        $this->kitchenPost('submissions', $intent, $handheld)->assertCreated();
        $wrong = $this->intent(['source' => 'main_pos', 'order_uuid' => $order->uuid, 'round_id' => $round->id]);
        $this->kitchenPost('submissions', $wrong)->assertUnprocessable()->assertJsonPath('errors.0.code', 'round_source_mismatch');
        $this->assertSame('main_pos', $order->fresh()->source);
        $this->assertDatabaseCount('pos_kv2_deliveries', 1);
    }

    public function test_k4_client_connection_has_pinned_host_and_no_host_authority(): void
    {
        $this->withK3Issuer(function (): void {
            $other = $this->seatingDevice('handheld', attributes: ['assignment_activated_at' => now()]);
            app(Configuration::class)->enroll(100, [10], $other->id, str_repeat('b', 64), [$this->area], 'admin');
            $response = $this->kitchenGet('kitchen-v2/connection', $other)->assertOk()->json('data');
            $this->assertFalse($response['is_coordinator']);
            $this->assertSame((int) $other->id, $response['identity']['device_id']);
            $this->assertSame((int) $this->device->id, $response['coordinator_identity']['device_id']);
            $this->assertNotEmpty($response['coordinator_thumbprint']);
            $this->assertArrayNotHasKey('grant', $response);
            $this->assertArrayNotHasKey('runtime', $response);
            $this->assertArrayNotHasKey('d', $response['public_keys']['test']);
            $this->kitchenGet('kitchen-v2/runtime', $other)->assertForbidden();
        });
    }

    public function test_k5_display_bootstrap_and_pin_session_are_minimal_and_scoped(): void
    {
        $this->withK3Issuer(function (): void {
            $otherArea = (string) Str::uuid();
            $bundle = $this->bundle;
            $bundle['areas'][] = ['id' => $otherArea, 'name' => 'Private other area'];
            $bundle['display'] = ['fallback_minutes' => 22];
            app(Configuration::class)->setRouting(100, [10], 10, $bundle, 'owner');
            $this->activate();
            $d = $this->seatingDevice('kitchen_display', attributes: ['assignment_activated_at' => now()]);
            app(Configuration::class)->enroll(100, [10], $d->id, str_repeat('b', 64), [$this->area], 'admin');
            DB::table('pos_staff')->where('id', 1)->update(['pin_hash' => Hash::make('4815')]);
            $connection = $this->kitchenGet('kitchen-v2/display/connection', $d)->assertOk()->assertJsonCount(1, 'data.areas')->assertJsonPath('data.display.fallback_minutes', 22)->json('data');
            foreach (['bundle', 'staff', 'catalogue', 'destinations', 'cloud_url', 'host_grant', 'device_token', 'staff_token'] as $key) {
                $this->assertArrayNotHasKey($key, $connection);
            }
            $this->assertStringNotContainsString('192.168.100.173', json_encode($connection));
            $this->assertStringNotContainsString('Private other area', json_encode($connection));
            foreach (['connection', 'runtime', 'configuration', 'intake', 'deliveries'] as $path) {
                $this->kitchenGet('kitchen-v2/'.$path, $d)->assertForbidden();
            }
            $session = $this->kitchenPost('display/session', ['pin' => '4815'], $d, null)->assertOk()->assertJsonMissingPath('data.staff_token')->assertJsonMissingPath('data.staff')->json('data');
            $access = Access::from($this->app['request'], 'provisioning');
            $claims = app(Grants::class)->verify($session['grant'], $access->identity(), 'kitchen.complete');
            $this->assertSame(['kitchen.complete', 'kitchen.undo'], $claims['scopes']);
            $this->assertSame([$this->area], $claims['area_ids']);
            $this->assertLessThanOrEqual(43200, $claims['exp'] - $claims['iat']);
            $this->kitchenPost('display/session', ['grant' => $session['grant']], $d, null)->assertOk();
            $this->kitchenPost('grants', ['kind' => 'host'], $d, null)->assertForbidden();
            DB::table('pos_staff')->where('id', 1)->update(['status' => 'inactive']);
            $this->kitchenPost('display/session', ['grant' => $session['grant']], $d, null)->assertForbidden();
        });
    }

    public function test_k5_display_refuses_cross_role_empty_area_and_changed_assignment(): void
    {
        $this->withK3Issuer(function (): void {
            $this->kitchenGet('kitchen-v2/display/connection')->assertForbidden();
            $d = $this->seatingDevice('kitchen_display', attributes: ['assignment_activated_at' => now()]);
            app(Configuration::class)->enroll(100, [10], $d->id, str_repeat('b', 64), [], 'admin');
            $this->kitchenGet('kitchen-v2/display/connection', $d)->assertForbidden()->assertJsonPath('errors.0.code', 'kds_areas_required');
            app(Configuration::class)->enroll(100, [10], $d->id, str_repeat('b', 64), [$this->area], 'admin');
            $d->forceFill(['assignment_activated_at' => now()->addMinute()])->save();
            $this->kitchenGet('kitchen-v2/display/connection', $d)->assertForbidden();
        });
    }

    public function test_k5_invalid_display_pin_uses_existing_lockout(): void
    {
        $this->withK3Issuer(function (): void {
            $d = $this->seatingDevice('kitchen_display', attributes: ['assignment_activated_at' => now()]);
            app(Configuration::class)->enroll(100, [10], $d->id, str_repeat('b', 64), [$this->area], 'admin');
            DB::table('pos_staff')->where('id', 1)->update(['pin_hash' => Hash::make('4815')]);
            for ($i = 0; $i < 4; $i++) {
                $this->kitchenPost('display/session', ['pin' => '9898'], $d, null)->assertUnauthorized();
            }
            $this->kitchenPost('display/session', ['pin' => '9898'], $d, null)->assertStatus(423);
            $this->kitchenPost('display/session', ['pin' => '4815'], $d, null)->assertStatus(423);
        });
    }

    public function test_k5_cooking_snapshot_and_fallback_are_validated_and_frozen(): void
    {
        $b = $this->bundle;
        $b['display'] = ['fallback_minutes' => 0];
        try {
            app(Configuration::class)->setRouting(100, [10], 10, $b, 'owner');
            $this->fail('invalid fallback accepted');
        } catch (ValidationException) {
        }
        $b['display']['fallback_minutes'] = 19;
        app(Configuration::class)->setRouting(100, [10], 10, $b, 'owner');
        $this->activate();
        $i = $this->intent();
        $i['lines'][0]['cooking_minutes'] = 27;
        $this->kitchenPost('submissions', $i)->assertCreated();
        $work = DB::table('pos_kv2_work')->first();
        $this->assertSame(27, Wire::read($work->line)['cooking_minutes']);
        $bad = $this->intent();
        $bad['lines'][0]['cooking_minutes'] = 241;
        $this->kitchenPost('submissions', $bad)->assertUnprocessable();
        $b['display']['fallback_minutes'] = 8;
        app(Configuration::class)->setRouting(100, [10], 10, $b, 'owner');
        $this->activate();
        $revision = DB::table('pos_kv2_revisions')->first();
        $this->assertSame(19,Wire::read($revision->snapshot)['configuration']['display']['fallback_minutes']);
        $this->assertSame(27,Wire::read(DB::table('pos_kv2_work')->first()->line)['cooking_minutes']);
    }

    public function test_k6_manual_retry_retains_before_send_attempt_and_rejects_uncertain_resume(): void
    {
        $this->submitted();
        $id = DB::table('pos_kv2_deliveries')->value('uuid');
        $attempt = (string) Str::uuid();
        $this->kitchenPost('delivery-results', $this->envelope('claim_delivery', ['delivery_uuid' => $id, 'attempt_uuid' => $attempt]))->assertOk();
        $this->kitchenPost('delivery-results', $this->envelope('delivery_result', ['delivery_uuid' => $id, 'attempt_uuid' => $attempt, 'result' => 'failed_before_send']))->assertOk();
        $resume = $this->envelope('resume_delivery', ['delivery_uuid' => $id]);
        $this->kitchenPost('delivery-results', $resume)->assertOk()->assertJsonPath('data.state', 'queued');
        $this->kitchenPost('delivery-results', $resume)->assertOk();
        $this->assertDatabaseHas('pos_kv2_attempts', ['uuid' => $attempt, 'state' => 'failed_before_send']);
        $next = (string) Str::uuid();
        $this->kitchenPost('delivery-results', $this->envelope('claim_delivery', ['delivery_uuid' => $id, 'attempt_uuid' => $next]))->assertOk();
        $this->kitchenPost('delivery-results', $this->envelope('delivery_result', ['delivery_uuid' => $id, 'attempt_uuid' => $next, 'result' => 'sending']))->assertOk();
        $this->kitchenPost('delivery-results', $this->envelope('delivery_result', ['delivery_uuid' => $id, 'attempt_uuid' => $next, 'result' => 'uncertain']))->assertOk();
        $this->kitchenPost('delivery-results', $this->envelope('resume_delivery', ['delivery_uuid' => $id]))->assertConflict();
        $this->assertSame(2, DB::table('pos_kv2_attempts')->where('delivery_id', DB::table('pos_kv2_deliveries')->where('uuid', $id)->value('id'))->count());
    }
    public function test_k6_reprints_preserve_change_and_cancel_meaning_recursively(): void
    {
        $sent = function (string $id): void {
            $attempt = (string) Str::uuid();
            $this->kitchenPost('delivery-results', $this->envelope('claim_delivery', ['delivery_uuid' => $id, 'attempt_uuid' => $attempt]))->assertOk();
            foreach (['sending', 'sent_unconfirmed'] as $state) {
                $this->kitchenPost('delivery-results', $this->envelope('delivery_result', ['delivery_uuid' => $id, 'attempt_uuid' => $attempt, 'result' => $state]))->assertOk();
            }
        };
        foreach (['change', 'cancel'] as $purpose) {
            $i = $this->submitted();
            $submission = DB::table('pos_kv2_submissions')->where('uuid', $i['submission_uuid'])->value('id');
            $sent(DB::table('pos_kv2_deliveries')->where('submission_id', $submission)->where('purpose', 'original')->value('uuid'));
            if ($purpose === 'change') {
                $lines = $i['lines'];
                $lines[0]['quantity'] = '3.000000';
                $this->kitchenPost('events', $this->action($i, 'amend', ['replacement' => ['lines' => $lines]]))->assertOk();
            } else {
                $this->kitchenPost('events', $this->action($i, 'cancel', ['reason' => 'Customer cancelled before preparation']))->assertOk();
            }
            $delivery = DB::table('pos_kv2_deliveries')->where('submission_id', $submission)->where('purpose', $purpose)->first();
            $saved = Wire::read($delivery->snapshot);
            $id = $delivery->uuid;
            for ($copy = 0; $copy < 2; $copy++) {
                $sent($id);
                $command = $this->envelope('reprint', ['delivery_uuid' => $id, 'reason' => 'Operator verified missing ticket']);
                $new = $this->kitchenPost('delivery-results', $command)->assertOk()->json('data.delivery_uuid');
                $this->kitchenPost('delivery-results', $command)->assertOk()->assertJsonPath('data.replayed', true);
                $snapshot = Wire::read(DB::table('pos_kv2_deliveries')->where('uuid', $new)->value('snapshot'));
                $this->assertSame($purpose, $snapshot['original_purpose'] ?? null);
                $this->assertSame($id, $snapshot['reprint_of']);
                $this->assertSame($saved['lines'], $snapshot['lines']);
                $id = $new;
            }
        }
    }

}
