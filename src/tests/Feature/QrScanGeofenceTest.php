<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\ScanGeofence;
use App\Models\QrSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

class QrScanGeofenceTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    public function test_verdict_matrix_and_tolerance_boundary_use_the_existing_fence_rule(): void
    {
        $branch = $this->seatingBranch();
        $branch->update(['latitude' => '23.5880000', 'longitude' => '58.3829000', 'geofence_radius_m' => 500]);
        $inside = ['lat' => 23.588, 'lng' => 58.3829, 'accuracy_m' => 35];
        $outside = ['lat' => 24.0, 'lng' => 58.3829, 'accuracy_m' => 35];
        $guard = app(ScanGeofence::class);
        foreach ([
            ['off', 'granted', $inside, 'off', 0],
            ['advisory', 'granted', $inside, 'inside', 0],
            ['advisory', 'granted', $outside, 'outside', 45812],
            ['enforce', 'denied', null, 'refused', null],
            ['advisory', 'unavailable', null, 'unknown', null],
            ['enforce', 'not_requested', null, 'unknown', null],
        ] as [$mode, $state, $location, $verdict, $distance]) {
            $this->assertSame(['verdict' => $verdict, 'distance_m' => $distance], $guard->verdict($branch, $mode, $state, $location));
        }
        foreach ([599 => 'inside', 601 => 'outside'] as $metres => $verdict) {
            $point = ['lat' => 23.588 + rad2deg($metres / 6371000), 'lng' => 58.3829];
            $this->assertSame(['verdict' => $verdict, 'distance_m' => $metres], $guard->verdict($branch, 'enforce', 'granted', $point));
        }
        $branch->update(['latitude' => null]);
        $this->assertSame(['verdict' => 'unfenced', 'distance_m' => null], $guard->verdict($branch, 'enforce', 'granted', $outside));
    }

    public function test_enforce_refuses_only_outside_with_exact_safe_message_and_advisory_allows_it(): void
    {
        $branch = $this->seatingBranch();
        $branch->update(['latitude' => '23.5880000', 'longitude' => '58.3829000']);
        foreach (['qr_table_card_enabled' => 'on', 'qr_scan_geofence_mode' => 'enforce'] as $key => $value) {
            DB::table('pos_branch_settings')->insert([
                'company_id' => 100, 'branch_id' => 10, 'key' => $key, 'value' => json_encode($value),
            ]);
        }
        $table = $this->seatingTable();
        $payload = [
            'table_token' => $table->qr_token, 'client_secret' => 'owner', 'location_state' => 'granted',
            'location' => ['lat' => 24, 'lng' => 58.3829, 'accuracy_m' => 35],
        ];
        $response = $this->postJson('/api/v1/public/qr/table-bind', $payload)->assertConflict()->assertExactJson([
            'data' => null, 'errors' => [[
                'code' => 'qr_scan_outside_branch',
                'message' => 'You need to be at Seating branch 10 to order from this table.',
            ]],
        ]);
        $this->assertDatabaseHas('pos_qr_session_scans', ['outcome' => 'refused_outside', 'geofence_verdict' => 'outside']);
        $this->assertDatabaseCount('pos_qr_sessions', 0);
        fwrite(STDOUT, "\nT9_BIND_OUTSIDE=".json_encode($response->json())."\n");
        DB::table('pos_branch_settings')->where('key', 'qr_scan_geofence_mode')->update(['value' => '"advisory"']);
        $this->postJson('/api/v1/public/qr/table-bind', $payload)->assertOk()->assertJsonPath('data.geofence', 'outside');
        $this->assertSame('outside', QrSession::query()->sole()->scan_geofence_verdict);
        DB::table('pos_branch_settings')->where('key', 'qr_scan_geofence_mode')->update(['value' => '"enforce"']);
        foreach (['denied' => 'refused', 'unavailable' => 'unknown', 'not_requested' => 'unknown'] as $state => $verdict) {
            $this->postJson('/api/v1/public/qr/table-bind', [
                'table_token' => $this->seatingTable($state)->qr_token, 'client_secret' => 'owner', 'location_state' => $state,
            ])->assertOk()->assertJsonPath('data.geofence', $verdict);
        }
        $this->assertSame(3, DB::table('pos_qr_session_scans')->whereNull('latitude')->whereNull('distance_m')->count());
    }
}
