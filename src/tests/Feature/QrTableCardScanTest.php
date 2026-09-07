<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\OpenDineInTableAction;
use App\Models\QrSession;
use App\Models\Table;
use App\Models\TableSession;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery;
use PDOException;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

class QrTableCardScanTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-07 12:00:00', 'UTC'));
        Cache::flush();
    }

    private function enable(): void
    {
        DB::table('pos_branch_settings')->updateOrInsert(
            ['company_id' => 100, 'branch_id' => 10, 'key' => 'qr_table_card_enabled'], ['value' => '"on"'],
        );
    }

    private function scan(Table $table, string $secret = 'synthetic-owner'): TestResponse
    {
        return $this->postJson('/api/v1/public/qr/table-bind', ['table_token' => $table->qr_token, 'client_secret' => $secret]);
    }

    public function test_disabled_and_unknown_bind_keep_the_exact_generic_response_and_only_known_table_is_logged(): void
    {
        $table = $this->seatingTable();
        $expected = ['data' => null, 'errors' => [['code' => 'qr_bind_failed', 'message' => 'QR session could not be bound.']]];
        $this->scan($table)->assertNotFound()->assertExactJson($expected);
        $this->assertDatabaseHas('pos_qr_session_scans', ['outcome' => 'refused_disabled', 'company_id' => 100, 'branch_id' => 10]);
        Log::spy();
        foreach ([1, 2] as $attempt) {
            $response = $this->postJson('/api/v1/public/qr/table-bind', [
                'table_token' => 'unknown-synthetic-card', 'client_secret' => 'synthetic-owner',
            ])->assertNotFound()->assertExactJson($expected);
        }
        $this->assertDatabaseCount('pos_qr_session_scans', 1);
        Log::shouldHaveReceived('info')->withArgs(fn ($message, $context) => $message === 'qr.table_bind.unknown_token'
            && strlen($context['token_hash_prefix']) === 12 && strlen($context['ip_hash']) === 64)->once();
        fwrite(STDOUT, "\nT9_BIND_UNKNOWN=".json_encode($response->json())."\n");
    }

    public function test_card_scan_opens_one_device_less_seating_and_journals_the_scan(): void
    {
        $table = $this->seatingTable();
        $this->enable();
        $response = $this->scan($table)->assertOk()->assertJsonPath('data.read_only', false)
            ->assertJsonPath('data.handover', false)->assertJsonPath('data.geofence', 'unfenced');
        $session = QrSession::query()->sole();
        $seating = TableSession::query()->sole();
        $this->assertSame('table_card', $session->origin);
        $this->assertNull($session->device_id);
        $this->assertSame($seating->id, $session->table_session_id);
        $this->assertSame('table_card', $seating->origin);
        $this->assertSame('T-0907-001', $seating->temp_reference);
        $this->assertNull($seating->opened_by_device_id);
        $this->assertSame('2026-09-07T18:00:00+00:00', $session->expires_at->toIso8601String());
        $event = DB::table('pos_table_session_events')->sole();
        $this->assertSame('opened', $event->event_type);
        $this->assertTrue(json_decode($event->payload, true)['card_scan']);
        $this->assertDatabaseHas('pos_qr_session_scans', ['outcome' => 'opened', 'role' => 'owner', 'qr_session_id' => $session->id]);
        fwrite(STDOUT, "\nT9_BIND_OPENED=".json_encode($response->json())."\n");
    }

    public function test_card_scan_attaches_to_staff_bill_without_changing_any_bill_column(): void
    {
        $table = $this->seatingTable();
        $seating = $this->seatingRow($table);
        $bill = $this->seatingOrder($seating);
        $before = $bill->fresh()->getRawOriginal();
        $this->enable();
        $response = $this->scan($table)->assertOk();
        $this->assertSame($before, $bill->fresh()->getRawOriginal());
        $this->assertSame($seating->id, QrSession::query()->sole()->table_session_id);
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseHas('pos_qr_session_scans', ['outcome' => 'attached']);
        $this->assertDatabaseHas('pos_table_session_events', ['event_type' => 'attached']);
        fwrite(STDOUT, "\nT9_BIND_ATTACHED=".json_encode($response->json())."\n");
    }

    public function test_unique_index_loser_restarts_and_reads_the_committed_scanner_as_owner(): void
    {
        $table = $this->seatingTable();
        $this->enable();
        $winner = app(BindQrTableSessionAction::class)->handle($table->qr_token, 'winner');
        $connection = DB::connection();
        // Model the PostgreSQL loser at the transaction seam: its aborted
        // insert is rolled back, while the other scanner has committed.
        DB::swap(Mockery::mock(DB::getFacadeRoot()));
        DB::shouldReceive('transaction')->once()->ordered()->andThrow(
            new QueryException('pgsql', 'insert into pos_qr_sessions', [],
                new PDOException('23505 unique constraint pos_qr_sessions_table_live_unique')),
        );
        DB::shouldReceive('transaction')->once()->ordered()->andReturnUsing(
            fn ($callback, $attempts) => $connection->transaction($callback, $attempts),
        );
        $loser = app(BindQrTableSessionAction::class)->handle($table->qr_token, 'loser');
        $this->assertFalse($winner['read_only']);
        $this->assertTrue($loser['read_only']);
        $this->assertNull($loser['session_uuid']);
        $this->assertSame(1, QrSession::query()->count());
        $this->assertSame(1, TableSession::query()->count());
        $this->assertSame(['opened', 'read_only'], $connection->table('pos_qr_session_scans')->orderBy('id')->pluck('outcome')->all());
        $this->assertTrue(QrSession::query()->sole()->clientSecretMatches('winner'));
        $this->assertFalse(QrSession::query()->sole()->clientSecretMatches('loser'));
    }

    public function test_joined_and_unpaid_tables_are_refused_and_logged(): void
    {
        $primary = $this->seatingRow($this->seatingTable('Primary'));
        $joinedTable = $this->seatingTable('Joined');
        $this->seatingRow($joinedTable, ['merged_into_id' => $primary->id]);
        $this->enable();
        $this->scan($joinedTable)->assertConflict()->assertJsonPath('errors.0.code', 'qr_table_joined');
        $table = $this->seatingTable('Unpaid');
        $seating = $this->seatingRow($table);
        $this->seatingOrder($seating, ['status' => 'held']);
        $this->scan($table)->assertConflict()->assertJsonPath('errors.0.code', 'qr_table_has_unpaid_order');
        $this->assertDatabaseHas('pos_qr_session_scans', ['outcome' => 'refused_joined']);
        $this->assertDatabaseHas('pos_qr_session_scans', ['outcome' => 'refused_unpaid']);
        $this->assertDatabaseCount('pos_qr_sessions', 0);
    }

    public function test_inactive_table_and_floor_refuse_with_tenant_attribution(): void
    {
        $table = $this->seatingTable(attributes: ['status' => 'inactive']);
        $this->enable();
        $this->scan($table)->assertNotFound();
        $table->update(['status' => 'active']);
        DB::table('pos_floors')->where('id', $table->floor_id)->update(['status' => 'inactive']);
        $this->scan($table)->assertNotFound();
        $this->assertSame(['refused_table', 'refused_table'], DB::table('pos_qr_session_scans')->pluck('outcome')->all());
        $this->assertSame([100], DB::table('pos_qr_session_scans')->distinct()->pluck('company_id')->all());
        $this->assertSame([10], DB::table('pos_qr_session_scans')->distinct()->pluck('branch_id')->all());
    }

    public function test_expired_bill_less_credential_allows_a_new_scan_but_disabling_cards_does_not_kill_existing_owner(): void
    {
        $table = $this->seatingTable();
        $this->enable();
        $this->scan($table)->assertOk();
        $old = QrSession::query()->sole();
        $old->update(['expires_at' => now()]);
        $this->scan($table, 'replacement')->assertOk()->assertJsonPath('data.handover', false);
        $this->assertSame('expired', $old->fresh()->status);
        $current = QrSession::query()->latest('id')->first();
        DB::table('pos_branch_settings')->where('key', 'qr_table_card_enabled')->update(['value' => '"off"']);
        $this->withHeaders(['X-QR-Session' => $current->uuid, 'X-QR-Client-Secret' => 'replacement'])
            ->getJson('/api/v1/public/qr/status')->assertOk();
        $this->scan($this->seatingTable('New table'))->assertNotFound();
    }

    public function test_station_first_bind_remains_available_when_cards_are_off(): void
    {
        $table = $this->seatingTable();
        $station = $this->seatingDevice('payment_station');
        $opened = app(OpenDineInTableAction::class)->handle($station, $table->id);
        $response = $this->scan($table)->assertOk()->assertJsonPath('data.session_uuid', $opened['session']->uuid);
        $this->assertDatabaseHas('pos_qr_session_scans', ['outcome' => 'first_bind']);
        fwrite(STDOUT, "\nT9_BIND_FIRST=".json_encode($response->json())."\n");
    }

    public function test_table_menu_is_additive_and_discloses_no_location_or_occupancy(): void
    {
        $table = $this->seatingTable();
        $this->enable();
        $response = $this->getJson('/api/v1/public/qr/table-menu?t='.$table->qr_token)
            ->assertOk()->assertJsonPath('data.branch.name', 'Seating branch 10')
            ->assertJsonPath('data.card.enabled', true)->assertJsonPath('data.card.geofence_mode', 'advisory')
            ->assertJsonPath('data.card.branch_fenced', false)
            ->assertJsonMissingPath('data.session_uuid')->assertJsonMissingPath('data.branch.latitude')
            ->assertJsonMissingPath('data.rounds');
        fwrite(STDOUT, "\nT9_TABLE_MENU=".json_encode($response->json())."\n");
    }
}
