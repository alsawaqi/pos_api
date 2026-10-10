<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Kitchen\Configuration;
use App\Models\Device;
use App\Support\Staff\StaffToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class IndependentK3RenewalReviewTest extends TestCase
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

    public function test_cached_issuance_cannot_roll_a_newer_certificate_back(): void
    {
        $this->withK3Issuer(function (): void {
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            $csr = openssl_csr_new(['commonName' => 'REVIEW RENEWAL'], $key, ['digest_alg' => 'sha256']);
            openssl_csr_export($csr, $csrPem);
            $old = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);
            openssl_x509_export($old, $oldPem);
            DB::table('pos_kv2_devices')->where('device_id', $this->device->id)->update(['certificate_thumbprint' => openssl_x509_fingerprint($oldPem, 'sha256')]);
            $first = $this->kitchenPost('certificate', ['csr' => $csrPem, 'certificate' => $oldPem])->assertOk()->json('data');
            $renew = ['csr' => $csrPem, 'certificate' => $first['certificate'], 'renewal_id' => (string) Str::uuid()];
            $second = $this->kitchenPost('certificate', $renew)->assertOk()->json('data');
            $this->assertNotSame($first['thumbprint'], $second['thumbprint']);
            $again = $this->kitchenPost('certificate', $renew)->assertOk()->json('data');
            $this->assertSame($second, $again);
            $this->kitchenPost('certificate', [...$renew, 'renewal_id' => (string) Str::uuid()])->assertForbidden();
            $rollback = $this->kitchenPost('certificate', ['csr' => $csrPem, 'certificate' => $second['certificate']]);
            $this->assertContains($rollback->status(), [403, 409], 'An older cached issuance must not accept a different current-certificate input and restore an obsolete thumbprint');
            $this->assertSame($second['thumbprint'], DB::table('pos_kv2_devices')->where('device_id', $this->device->id)->value('certificate_thumbprint'));
        });
    }
}
