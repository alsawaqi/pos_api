<?php

declare(strict_types=1);

namespace Tests\Postgres;

use App\Models\Device;
use App\Models\DeviceActivationToken;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

require_once __DIR__.'/P0DisposableDatabase.php';

/** Uses only the disposable database built by pos_admin's real migrations. */
final class LaunchP0RealSchemaTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('pgsql', DB::getDriverName());
        $this->assertSame(\p0DisposableDatabase('core', 'qr_fix4_p0'), DB::connection()->getDatabaseName());
        $this->assertTrue(DB::table('pos_admin_migrations')->where('migration', '2026_09_30_000001_bind_and_hash_pos_device_credentials')->exists());
        $this->assertGreaterThan(50, (int) DB::selectOne("SELECT count(*) AS n FROM pg_constraint WHERE contype = 'f'")->n);
    }

    private function tenant(): array
    {
        $company = DB::table('pos_companies')->insertGetId(['uuid' => Str::uuid(), 'name' => 'P0 synthetic', 'status' => 'active']);
        $branch = DB::table('pos_branches')->insertGetId(['uuid' => Str::uuid(), 'company_id' => $company, 'name' => 'Test', 'status' => 'active']);
        $device = Device::factory()->paired()->create(['company_id' => $company, 'branch_id' => $branch, 'device_type' => 'fixed_pos']);

        return [$company, $branch, $device];
    }

    public function test_hashed_activation_move_and_reactivation_on_all_surfaces(): void
    {
        [$a, $ab, $device] = $this->tenant();
        [$b, $bb] = $this->tenant();
        $raw = $device->plainTextToken;
        $this->assertSame(hash('sha256', $raw), $device->device_token);
        $this->withToken($raw)->postJson('/api/v1/device/heartbeat')->assertOk();
        DB::table('pos_devices')->where('id', $device->id)->update(['company_id' => $b, 'branch_id' => $bb]);
        foreach ([['GET', 'device/config'], ['GET', 'device/customers/search?q=90000001'], ['GET', 'device/reports/branch'], ['GET', 'device/orders/history'], ['POST', 'device/sync/push'], ['POST', 'broadcasting/auth']] as [$method, $route]) {
            Auth::forgetGuards();
            $this->withToken($raw)->json($method, '/api/v1/'.$route)->assertUnauthorized()->assertJsonPath('code', 'device_reactivation_required');
        }
        DeviceActivationToken::factory()->for($device)->forPlaintext('p0-real-code')->create();
        Auth::forgetGuards();
        $fresh = $this->postJson('/api/v1/auth/device/activate', ['code' => 'p0-real-code'])->assertOk()->json('data.device_token');
        $this->assertNotSame($raw, $fresh);
        Auth::forgetGuards();
        $this->withToken($fresh)->postJson('/api/v1/device/heartbeat', ['pending_outbox_count' => 2, 'quarantined_count' => 1, 'app_version' => 'p0-test'])->assertOk();
        $this->assertSame(2, (int) $device->fresh()->pending_outbox_count);
    }

    public function test_foreign_tag_is_permanently_refused_and_suspension_blocks_reads_and_writes(): void
    {
        [$a, $ab, $device] = $this->tenant();
        [$b, $bb] = $this->tenant();
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [[
            'client_event_id' => (string) Str::uuid(), 'event_type' => 'expense.log', 'client_timestamp' => now()->toIso8601String(),
            'identity' => ['company_id' => $b, 'branch_id' => $bb, 'device_uuid' => $device->uuid],
            'payload' => ['amount_baisas' => 1000, 'description' => 'must not write'],
        ]]])->assertOk()->assertJsonPath('data.results.0.result.code', 'identity_mismatch');
        $this->assertSame(0, DB::table('pos_expenses')->where('company_id', $b)->count());
        DB::table('pos_companies')->where('id', $a)->update(['status' => 'suspended']);
        foreach ([['GET', 'device/config'], ['POST', 'device/sync/push']] as [$method,$path]) {
            Auth::forgetGuards();
            $this->withToken($device->plainTextToken)->json($method, '/api/v1/'.$path)
                ->assertStatus(503)->assertJsonPath('errors.0.code', 'company_suspended');
        }
    }

    public function test_foreign_customers_and_orders_are_indistinguishable_from_missing(): void
    {
        [$a, $ab, $device] = $this->tenant();
        [$b, $bb] = $this->tenant();
        $customer = DB::table('pos_customers')->insertGetId(['uuid' => Str::uuid(), 'company_id' => $b, 'name' => 'Private', 'phone' => '90000001']);
        $this->withToken($device->plainTextToken)->getJson('/api/v1/device/customers/'.$customer)->assertNotFound()->assertJsonPath('errors.0.code', 'customer_not_found');
        $uuid = (string) Str::uuid();
        DB::table('pos_orders')->insert(['uuid' => $uuid, 'company_id' => $b, 'branch_id' => $bb, 'order_type' => 'quick', 'source' => 'main_pos', 'status' => 'open', 'subtotal' => 1, 'grand_total' => 1, 'opened_at' => now()]);
        $foreign = $this->postJson('/api/v1/device/qr/claim-settlement', ['order_uuid' => $uuid])->assertNotFound()->json('errors.0.code');
        $missing = $this->postJson('/api/v1/device/qr/claim-settlement', ['order_uuid' => (string) Str::uuid()])->assertNotFound()->json('errors.0.code');
        $this->assertSame($missing, $foreign);
    }

    public function test_public_table_bind_without_a_session_header_never_queries_an_empty_postgresql_uuid(): void
    {
        $this->postJson('/api/v1/public/qr/table-bind', ['table_token' => 'unknown-table', 'client_secret' => 'synthetic-private-secret'])
            ->assertNotFound();
        $this->withHeader('X-QR-Session', 'not-a-uuid')
            ->postJson('/api/v1/public/qr/table-bind', ['table_token' => 'unknown-table', 'client_secret' => 'synthetic-private-secret'])
            ->assertNotFound();
    }
}
