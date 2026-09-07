<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Models\QrSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

class QrScanLogPrivacyTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-07 12:00:00', 'UTC'));
    }

    public function test_each_scan_is_tenant_scoped_hmac_attributed_and_coordinates_require_permission(): void
    {
        $table = $this->seatingTable();
        $this->seatingBranch()->update(['latitude' => 23.588, 'longitude' => 58.3829]);
        DB::table('pos_branch_settings')->insert([
            'company_id' => 100, 'branch_id' => 10, 'key' => 'qr_table_card_enabled', 'value' => '"on"',
        ]);
        config(['qr.bff_client_ip_secret' => 'synthetic-bff-secret']);
        $this->withHeaders(['X-Pos-Web-Client-Auth' => 'synthetic-bff-secret', 'X-Pos-Web-Client-IP' => '198.51.100.7']);
        $fingerprint = hash('sha256', 'synthetic-cookie');
        $payload = [
            'table_token' => $table->qr_token, 'client_secret' => 'owner',
            'location_state' => 'granted', 'location' => ['lat' => 23.588, 'lng' => 58.3829, 'accuracy_m' => 35],
            'fingerprint_hash' => $fingerprint,
        ];
        $this->postJson('/api/v1/public/qr/table-bind', $payload)->assertOk();
        $credential = QrSession::query()->sole();
        $this->postJson('/api/v1/public/qr/table-bind', $payload)->assertOk();
        $payload['client_secret'] = 'viewer';
        $payload['location_state'] = 'denied';
        $this->postJson('/api/v1/public/qr/table-bind', $payload)->assertOk()->assertJsonPath('data.read_only', true);
        $rows = DB::table('pos_qr_session_scans')->orderBy('id')->get();
        $this->assertSame(['opened', 'replay', 'read_only'], $rows->pluck('outcome')->all());
        $this->assertSame(['owner', 'owner', 'viewer'], $rows->pluck('role')->all());
        $this->assertSame([$credential->id, $credential->id, null], $rows->pluck('qr_session_id')->all());
        foreach ($rows as $row) {
            $this->assertSame(100, $row->company_id);
            $this->assertSame(10, $row->branch_id);
            $this->assertSame($table->id, $row->table_id);
            $this->assertSame(hash_hmac('sha256', '198.51.100.7', config('app.key')), $row->ip_hash);
            $this->assertNotSame(hash('sha256', '198.51.100.7'), $row->ip_hash);
            $this->assertSame($fingerprint, $row->device_fingerprint_hash);
            $this->assertStringNotContainsString('198.51.100.7', json_encode($row));
            $this->assertStringNotContainsString('synthetic-cookie', json_encode($row));
        }
        $this->assertSame(35, $rows[0]->accuracy_m);
        $this->assertSame(0, $rows[0]->distance_m);
        $this->assertSame(23.588, (float) $rows[0]->latitude);
        foreach (['latitude', 'longitude', 'accuracy_m', 'distance_m'] as $column) {
            $this->assertNull($rows[2]->{$column});
            $this->assertArrayNotHasKey($column, $credential->getAttributes());
        }
        $this->assertSame($rows[0]->ip_hash, $credential->scan_ip_hash);
        $this->assertSame($fingerprint, $credential->scan_fingerprint_hash);
    }

    public function test_refusals_are_audited_under_the_resolved_branch_and_foreign_floor_fails_closed(): void
    {
        $table = $this->seatingTable(branchId: 20, companyId: 200);
        $this->postJson('/api/v1/public/qr/table-bind', [
            'table_token' => $table->qr_token, 'client_secret' => 'owner',
        ])->assertNotFound();
        $this->assertDatabaseHas('pos_qr_session_scans', [
            'company_id' => 200, 'branch_id' => 20, 'table_id' => $table->id,
            'role' => 'refused', 'outcome' => 'refused_disabled', 'qr_session_id' => null,
        ]);
        DB::table('pos_floors')->where('id', $table->floor_id)->update(['company_id' => 201]);
        $this->postJson('/api/v1/public/qr/table-bind', [
            'table_token' => $table->qr_token, 'client_secret' => 'owner',
        ])->assertNotFound();
        $this->assertDatabaseHas('pos_qr_session_scans', [
            'company_id' => 200, 'branch_id' => 20, 'role' => 'refused', 'outcome' => 'refused_table',
        ]);
        $this->assertDatabaseCount('pos_qr_sessions', 0);
        $this->assertDatabaseCount('pos_qr_session_scans', 2);
    }

    public function test_scan_retention_defaults_to_ninety_days_and_invalid_options_do_not_write(): void
    {
        $table = $this->seatingTable();
        foreach ([91, 90, 89] as $days) {
            app(BindQrTableSessionAction::class)->handle($table->qr_token, 'disabled');
            DB::table('pos_qr_session_scans')->where('id', DB::table('pos_qr_session_scans')->max('id'))
                ->update(['scanned_at' => now()->subDays($days)]);
        }
        foreach (['0', '-1', 'abc'] as $invalid) {
            $this->artisan('qr:prune-sessions', ['--scan-retention-days' => $invalid])
                ->expectsOutput('--scan-retention-days must be a positive integer.')->assertExitCode(2);
            $this->assertDatabaseCount('pos_qr_session_scans', 3);
        }
        $this->artisan('qr:prune-sessions')->expectsOutput('expired=0 deleted=0 seatings_expired=0')->assertExitCode(0);
        $this->assertDatabaseCount('pos_qr_session_scans', 2);
        $this->assertSame(now()->subDays(90)->format('Y-m-d H:i:s'), DB::table('pos_qr_session_scans')->min('scanned_at'));
        $this->artisan('qr:prune-sessions', ['--scan-retention-days' => '89'])->assertExitCode(0);
        $this->assertDatabaseCount('pos_qr_session_scans', 1);
    }

    public function test_scan_request_rejects_raw_identity_unknown_fields_and_invalid_location_shapes(): void
    {
        $table = $this->seatingTable();
        foreach ([
            ['phone' => '91234567'], ['ip' => '198.51.100.7'], ['fingerprint' => 'raw'],
            ['fingerprint_hash' => 'not-a-hash'], ['location_state' => 'granted'],
            ['location_state' => 'granted', 'location' => ['lat' => 91, 'lng' => 181, 'accuracy_m' => -1]],
            ['location_state' => 'granted', 'location' => ['lat' => 23, 'lng' => 58, 'accuracy_m' => 1, 'extra' => 1]],
        ] as $invalid) {
            $this->postJson('/api/v1/public/qr/table-bind', array_merge([
                'table_token' => $table->qr_token, 'client_secret' => 'owner',
            ], $invalid))->assertUnprocessable();
        }
        $this->assertDatabaseCount('pos_qr_sessions', 0);
        $this->assertDatabaseCount('pos_qr_session_scans', 0);
    }
}
