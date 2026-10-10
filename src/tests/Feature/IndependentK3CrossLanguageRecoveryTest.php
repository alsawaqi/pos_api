<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Kitchen\Configuration;
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

final class IndependentK3CrossLanguageRecoveryTest extends TestCase
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
        $this->device = $this->seatingDevice(companyId: 1, attributes: ['assignment_activated_at' => now()]);
        $this->seedPosStaff([1], 1);
        DB::table('pos_staff')->where('id', 1)->update(['position' => 'manager']);

        $this->area = (string) Str::uuid();
        $this->printer = (string) Str::uuid();
        $this->bundle = ['areas' => [['id' => $this->area, 'name' => 'Grill']], 'destinations' => [['id' => $this->printer, 'type' => 'printer', 'name' => 'Kitchen printer', 'address' => '192.168.100.173', 'port' => 9100, 'profile' => 'escpos-unverified', 'paused' => false]], 'rules' => [], 'all_items' => [], 'fallback' => ['areas' => [$this->area], 'destinations' => [$this->printer]]];
        $c = app(Configuration::class);
        $c->setRouting(1, [10], 10, $this->bundle, 'test-merchant');
        $c->setPolicy(1, [10], 10, 'staff', 'immediate', 'test-merchant');
        $c->enroll(1, [10], $this->device->id, str_repeat('a', 64), [$this->area], 'test-admin');
        $c->assign(1, [10], $this->device->id, 'test-admin', 'All legacy executors stopped in isolated test.');
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

    private function fixtureInput(?callable $mutate = null): array
    {
        $fixture = json_decode(file_get_contents(is_file('/review-checkpoint.json') ? '/review-checkpoint.json' : base_path('tests/Fixtures/k3-independent-held-checkpoint.json')), true, 512, JSON_THROW_ON_ERROR);
        if ($mutate) {
            $d = json_decode($fixture['checkpoint_json'], true, 512, JSON_THROW_ON_ERROR);
            $mutate($d);
            $fixture['checkpoint_json'] = (new JsonCanonicalizer)->canonicalize($d);
            $fixture['checkpoint_hash'] = hash('sha256', $fixture['checkpoint_json']);
        }
        DB::table('pos_kv2_branches')->where('branch_id', 10)->update(['epoch' => 2]);

        return [...$fixture, 'request_id' => (string) Str::uuid(), 'reason' => 'Independent cross-language synthetic recovery test.',
            'range_reconciliation' => 'One locally accepted event, all original sequence evidence retained.',
            'old_host_isolation' => 'Synthetic retired executor isolated before new target assignment.'];
    }

    public function test_exact_dart_checkpoint_archives_without_dispatch_and_replays(): void
    {
        $input = $this->fixtureInput();
        $before = DB::table('pos_kv2_events')->count();
        $r = $this->kitchenPost('recovery/archive', $input)->assertOk()->assertJsonPath('data.sequence', 1)->assertJsonPath('data.original_identity.assignment', 'assignment-1')->assertJsonPath('data.original_authority', 'unverified')->json('data');
        $this->assertSame($r, $this->kitchenPost('recovery/archive', $input)->assertOk()->json('data'));
        $stored = Wire::read(DB::table('pos_kv2_audit')->where('action', 'recovery_archive')->value('detail'));
        $this->assertSame($input['checkpoint_json'], $stored['checkpoint_json']);
        $this->assertSame($before, DB::table('pos_kv2_events')->count());
        foreach (['pos_kv2_submissions', 'pos_kv2_deliveries', 'pos_orders', 'pos_payments'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
    }

    public function test_changed_payload_recomputed_outer_hash_does_not_hide_original_receipt_mismatch(): void
    {
        $input = $this->fixtureInput(function (array &$d): void {
            $event = Wire::read($d['tables']['outbox'][0]['event']);
            $event['context']['reference'] = 'TAMPERED';
            $d['tables']['outbox'][0]['event'] = Wire::json($event);
        });
        $this->kitchenPost('recovery/archive', $input)->assertUnprocessable()->assertJsonPath('errors.0.code', 'checkpoint_payload_mismatch');
    }

    public function test_receipt_actor_and_sequence_corruption_are_rejected(): void
    {
        $input = $this->fixtureInput(function (array &$d): void {
            $actor = Wire::read($d['tables']['receipts'][0]['origin']);
            $actor['device_id'] = 999;
            $d['tables']['receipts'][0]['origin'] = Wire::json($actor);
        });
        $this->kitchenPost('recovery/archive', $input)->assertUnprocessable()->assertJsonPath('errors.0.code', 'checkpoint_receipt_invalid');
        $input = $this->fixtureInput(function (array &$d): void {
            $d['tables']['outbox'][0]['sequence'] = 2;
        });
        $this->kitchenPost('recovery/archive', $input)->assertUnprocessable()->assertJsonPath('errors.0.code', 'checkpoint_sequence_invalid');
    }

    public function test_valid_origin_with_added_credential_is_rejected_before_archive(): void
    {
        $input = $this->fixtureInput(function (array &$d): void {
            $origin = Wire::read($d['tables']['outbox'][0]['origin']);
            $origin['grant'] = 'SYNTHETIC-NOT-A-REAL-GRANT';
            $d['tables']['outbox'][0]['origin'] = Wire::json($origin);
        });
        $this->kitchenPost('recovery/archive',$input)->assertUnprocessable()->assertJsonPath('errors.0.code','checkpoint_authority_forbidden');
    }
}
