<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Kitchen\Configuration;
use App\Kitchen\KitchenFault;
use App\Kitchen\Wire;
use App\Models\Device;
use App\Support\Staff\StaffToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Root23\JsonCanonicalizer\JsonCanonicalizer;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class IndependentK3RecoveryReviewTest extends TestCase
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

    private function checkpoint(): array
    {
        $branch = DB::table('pos_kv2_branches')->where('branch_id', 10)->first();
        $binding = DB::table('pos_kv2_devices')->where('device_id', $this->device->id)->first();
        $bundle = $this->kitchenGet('kitchen-v2/configuration')->assertOk()->json('data.desired.bundle');

        return ['format' => 'mithqal-kitchen-held-v1', 'identity' => ['company_id' => 100, 'branch_id' => 10, 'device_id' => $this->device->id, 'assignment' => $binding->assignment, 'epoch' => (int) $branch->epoch],
            'configuration' => ['applied_version' => (int) $branch->applied_version, 'bundle_hash' => Wire::hash($bundle), 'bundle' => $bundle],
            'sequence' => 0, 'activation' => null, 'tables' => array_fill_keys(['receipts', 'orders', 'jobs', 'outbox', 'intents', 'reports', 'cloud_inbox'], [])];
    }

    private function archiveInput(array $checkpoint): array
    {
        $raw = (new JsonCanonicalizer)->canonicalize($checkpoint);

        return ['request_id' => (string) Str::uuid(), 'checkpoint_json' => $raw, 'checkpoint_hash' => hash('sha256', $raw), 'reason' => 'Supervised synthetic recovery review.', 'range_reconciliation' => 'No missing range; sequence zero contains no accepted work.'];
    }

    private function readArchive(string $id)
    {
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($this->device->plainTextToken)->withHeader('X-Staff-Token', StaffToken::issue($this->device->id, 1))->getJson('/api/v1/device/kitchen-v2/recovery/archive/'.$id);
    }

    public function test_archive_is_held_idempotent_and_does_not_create_executable_work(): void
    {
        $input = $this->archiveInput($this->checkpoint());
        $before = DB::table('pos_kv2_events')->count();
        $r = $this->kitchenPost('recovery/archive', $input)->assertOk()->assertJsonPath('data.disposition', 'operator_attested_history_held')->assertJsonPath('data.original_authority', 'unverified')->json('data');
        $again = $this->kitchenPost('recovery/archive', $input)->assertOk()->json('data');
        $this->assertSame($r, $again);
        $this->assertSame(1, DB::table('pos_kv2_audit')->where('action', 'recovery_archive')->count());
        $read = $this->readArchive($input['request_id'])->assertOk()->assertJsonPath('data.checkpoint_json', $input['checkpoint_json']);
        $this->assertStringContainsString('no-store', $read->headers->get('Cache-Control'));
        $this->assertSame($before, DB::table('pos_kv2_events')->count());
        foreach (['pos_kv2_submissions', 'pos_kv2_deliveries', 'pos_orders', 'pos_payments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $changed = [...$input, 'reason' => 'Changed reason must not reuse immutable request.'];
        $this->kitchenPost('recovery/archive', $changed)->assertConflict();
    }

    public function test_authority_is_checked_before_cached_replay_and_foreign_scope_rejected(): void
    {
        $input = $this->archiveInput($this->checkpoint());
        $this->kitchenPost('recovery/archive', $input)->assertOk();
        DB::table('pos_staff')->where('id', 1)->update(['position' => 'cashier']);
        $this->kitchenPost('recovery/archive', $input)->assertForbidden();
        $this->readArchive($input['request_id'])->assertForbidden();
        DB::table('pos_staff')->where('id', 1)->update(['position' => 'manager']);
        $this->kitchenPost('recovery/archive', $input, staff: null)->assertForbidden();
        $d = $this->checkpoint();
        $d['identity']['company_id'] = 200;
        $this->kitchenPost('recovery/archive', $this->archiveInput($d))->assertForbidden();
        $other = $this->seatingDevice('handheld', 10, 100, ['assignment_activated_at' => now()]);
        app(Configuration::class)->enroll(100, [10], $other->id, str_repeat('b', 64), [$this->area], 'test-admin');
        $this->kitchenPost('recovery/archive', $input, $other)->assertForbidden();
        $this->assertSame(1, DB::table('pos_kv2_audit')->where('action', 'recovery_archive')->count());
    }

    public function test_replacement_needs_isolation_and_strictly_new_epoch(): void
    {
        $d = $this->checkpoint();
        $d['identity']['device_id'] += 100;
        $input = $this->archiveInput($d);
        $this->kitchenPost('recovery/archive', $input)->assertConflict();
        $input['old_host_isolation'] = 'Synthetic old executor physically isolated by supervisor.';
        $this->kitchenPost('recovery/archive', $input)->assertConflict();
        DB::table('pos_kv2_branches')->where('branch_id', 10)->increment('epoch');
        $this->kitchenPost('recovery/archive', $input)->assertOk()->assertJsonPath('data.original_identity.device_id', $d['identity']['device_id']);
    }

    public function test_hash_and_origin_failures_never_archive(): void
    {
        $d = $this->checkpoint();
        $input = $this->archiveInput($d);
        $input['checkpoint_hash'] = str_repeat('0', 64);
        $this->kitchenPost('recovery/archive', $input)->assertUnprocessable();
        $d['tables']['outbox'] = [['sequence' => 1, 'event_id' => (string) Str::uuid(), 'event' => '{}', 'origin' => Wire::json(['company_id' => 200, 'branch_id' => 20, 'epoch' => $d['identity']['epoch']]), 'state' => 'held', 'attempts' => 0, 'next_ms' => 0, 'error' => null, 'cloud_receipt' => null]];
        $this->kitchenPost('recovery/archive', $this->archiveInput($d))->assertUnprocessable();
        $this->assertSame(0, DB::table('pos_kv2_audit')->where('action', 'recovery_archive')->count());
    }

    public function test_checkpoint_cannot_archive_runtime_credentials(): void
    {
        $d = $this->checkpoint();
        $d['configuration']['device_token'] = 'SYNTHETIC-REVIEW-NOT-A-REAL-TOKEN';
        $this->kitchenPost('recovery/archive', $this->archiveInput($d))->assertUnprocessable();
        $this->assertSame(0, DB::table('pos_kv2_audit')->where('action', 'recovery_archive')->count());
    }

    public function test_normal_sync_still_rejects_expired_grant_without_writes(): void
    {
        $this->withK3Issuer(function (): void {
            $grant = $this->kitchenPost('grants', ['kind' => 'staff'])->assertOk()->json('data.grant');
            $accepted = now()->toIso8601String();
            $event = $this->intent();
            $before = DB::table('pos_kv2_events')->count();
            $this->travel(13)->hours();
            $this->kitchenPost('sync', ['events' => [['device_id' => $this->device->id, 'grant' => $grant, 'event' => $event, 'accepted_at' => $accepted]]])->assertForbidden();
            $this->assertSame($before, DB::table('pos_kv2_events')->count());
            $this->assertDatabaseCount('pos_kv2_submissions', 0);
            $this->assertDatabaseCount('pos_kv2_deliveries', 0);
            $this->kitchenPost('recovery/archive', $this->archiveInput($this->checkpoint()))->assertOk()->assertJsonPath('data.original_authority', 'unverified');
        });
    }

    public function test_nested_origin_cannot_archive_grant(): void
    {
        $d = $this->checkpoint();
        $origin = [...$d['identity'], 'staff_id' => 1, 'grant' => 'SYNTHETIC-REVIEW-NOT-A-REAL-GRANT'];
        $d['tables']['outbox'] = [['sequence' => 1, 'event_id' => (string) Str::uuid(), 'event' => '{}', 'origin' => Wire::json($origin), 'state' => 'held', 'attempts' => 0, 'next_ms' => 0, 'error' => null, 'cloud_receipt' => null]];
        $this->kitchenPost('recovery/archive', $this->archiveInput($d))->assertUnprocessable();
    }

    public function test_controlled_assignment_chain_fences_old_coordinator_and_archives_only_under_new_authority(): void
    {
        $old = $this->device;
        $checkpoint = $this->checkpoint();
        $oldEpoch = $checkpoint['identity']['epoch'];
        $next = $this->seatingDevice('handheld', 10, 100, ['assignment_activated_at' => now()->addSecond()]);
        $config = app(Configuration::class);
        $config->enroll(100, [10], $next->id, str_repeat('b', 64), [$this->area], 'synthetic-supervisor');
        try {
            $config->assign(100, [10], $next->id, 'synthetic-supervisor', '');
            $this->fail('Cutover must require isolation attestation.');
        } catch (KitchenFault $e) {
            $this->assertSame('controlled_cutover_evidence_required', $e->reason);
        }
        $isolation = 'Synthetic old host isolated and all pending ranges accounted for.';
        try {
            $config->assign(100, [10], $next->id, 'synthetic-supervisor', $isolation);
            $this->fail('Live host must be suspended first.');
        } catch (KitchenFault $e) {
            $this->assertSame('suspend_before_handover', $e->reason);
        }
        $config->suspend(100, [10], 10, 'synthetic-supervisor');
        $assigned = $config->assign(100, [10], $next->id, 'synthetic-supervisor', $isolation);
        $this->assertGreaterThan($oldEpoch, $assigned['epoch']);
        $this->kitchenGet('kitchen-v2/configuration', $old)->assertForbidden();
        $input = $this->archiveInput($checkpoint);
        $this->kitchenPost('recovery/archive', $input, $old)->assertForbidden();
        $this->device = $next;
        $this->activate();
        $before = DB::table('pos_kv2_events')->count();
        $this->kitchenPost('recovery/archive', $input)->assertConflict();
        $input['old_host_isolation'] = $isolation;
        $this->kitchenPost('recovery/archive', $input)->assertOk()->assertJsonPath('data.original_identity.device_id', $old->id)->assertJsonPath('data.target_identity.device_id', $next->id)->assertJsonPath('data.target_identity.epoch', $assigned['epoch'])->assertJsonPath('data.disposition', 'operator_attested_history_held');
        $this->kitchenPost('recovery/archive', $input, $old)->assertForbidden();
        $this->assertSame($before, DB::table('pos_kv2_events')->count());
        $this->assertSame(1, DB::table('pos_kv2_audit')->where('action','recovery_archive')->count());
        $assignAudit = Wire::read(DB::table('pos_kv2_audit')->where('action','assign')->orderByDesc('id')->value('detail'));
        $this->assertSame($isolation,$assignAudit['isolation_evidence']);
        foreach (['pos_kv2_submissions', 'pos_kv2_deliveries', 'pos_orders', 'pos_payments'] as $table) {
            $this->assertDatabaseCount($table,0);
        }
    }
}
