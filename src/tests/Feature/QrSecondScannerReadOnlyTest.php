<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\OpenDineInTableAction;
use App\Models\QrSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

class QrSecondScannerReadOnlyTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-07 12:00:00', 'UTC'));
    }

    public function test_second_scanner_sees_only_table_label_and_never_changes_the_owner_row(): void
    {
        $table = $this->seatingTable();
        $station = $this->seatingDevice('payment_station');
        $session = app(OpenDineInTableAction::class)->handle($station, $table->id)['session'];
        app(BindQrTableSessionAction::class)->handle($table->qr_token, 'first-secret');
        foreach (['active', 'ordered'] as $status) {
            $session->update(['status' => $status]);
            $before = $session->fresh()->getRawOriginal();
            $this->travel(1)->minutes();
            $response = $this->postJson('/api/v1/public/qr/table-bind', [
                'table_token' => $table->qr_token, 'client_secret' => 'second-secret',
            ])->assertOk()->assertExactJson([
                'data' => [
                    'session_uuid' => null, 'status' => 'read_only', 'expires_at' => null, 'read_only' => true,
                    'reason' => 'qr_table_in_use', 'table' => ['uuid' => $table->uuid, 'label' => $table->label],
                ], 'meta' => [], 'errors' => [],
            ]);
            $this->assertSame($before, $session->fresh()->getRawOriginal());
            $this->assertTrue($session->fresh()->clientSecretMatches('first-secret'));
            $this->assertFalse($session->fresh()->clientSecretMatches('second-secret'));
            $scan = DB::table('pos_qr_session_scans')->latest('id')->first();
            $this->assertSame('viewer', $scan->role);
            $this->assertSame('read_only', $scan->outcome);
            $this->assertNull($scan->qr_session_id);
            fwrite(STDOUT, "\nT9_BIND_VIEWER_".$status.'='.json_encode($response->json())."\n");
        }
        $this->assertDatabaseCount('pos_qr_sessions', 1);
        $this->assertDatabaseCount('pos_qr_session_scans', 3);
    }

    public function test_same_secret_replay_updates_last_seen_only_in_active_and_ordered_states(): void
    {
        $table = $this->seatingTable();
        $session = app(OpenDineInTableAction::class)->handle($this->seatingDevice('payment_station'), $table->id)['session'];
        app(BindQrTableSessionAction::class)->handle($table->qr_token, 'owner');
        foreach (['active', 'ordered'] as $status) {
            $session->update(['status' => $status]);
            $before = $session->fresh()->getRawOriginal();
            $this->travel(1)->minutes();
            $response = $this->postJson('/api/v1/public/qr/table-bind', [
                'table_token' => $table->qr_token, 'client_secret' => 'owner',
            ])->assertOk()->assertExactJson([
                'data' => [
                    'session_uuid' => $session->uuid, 'status' => $status,
                    'expires_at' => $session->expires_at->toIso8601String(), 'read_only' => false,
                ], 'meta' => [], 'errors' => [],
            ]);
            $before['last_seen_at'] = now()->format('Y-m-d H:i:s');
            $this->assertSame($before, $session->fresh()->getRawOriginal());
            $this->assertNull($session->fresh()->secret_rotated_at);
            $this->assertDatabaseHas('pos_qr_session_scans', ['qr_session_id' => $session->id, 'role' => 'owner', 'outcome' => 'replay']);
            fwrite(STDOUT, "\nT9_BIND_REPLAY_".$status.'='.json_encode($response->json())."\n");
        }
    }

    public function test_dead_station_is_checked_before_both_owner_replay_and_viewer_response(): void
    {
        $table = $this->seatingTable();
        $station = $this->seatingDevice('payment_station');
        $session = app(OpenDineInTableAction::class)->handle($station, $table->id)['session'];
        app(BindQrTableSessionAction::class)->handle($table->qr_token, 'owner');
        $station->update(['status' => 'inactive']);
        $before = $session->fresh()->getRawOriginal();
        foreach (['owner', 'other'] as $secret) {
            $this->postJson('/api/v1/public/qr/table-bind', [
                'table_token' => $table->qr_token, 'client_secret' => $secret,
            ])->assertNotFound()->assertJsonPath('errors.0.code', 'qr_bind_failed');
            $this->assertSame($before, $session->fresh()->getRawOriginal());
        }
        $this->assertSame(2, DB::table('pos_qr_session_scans')->where('outcome', 'refused_station')->count());
        $this->assertSame(1, QrSession::query()->count());
    }
}
