<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\ConfirmDineInQrRoundAction;
use App\Actions\Qr\QrDineInException;
use App\Actions\Qr\ReleaseTableCredentialAction;
use App\Actions\Qr\SubmitDineInQrRoundAction;
use App\Actions\Qr\WithLockedDineInQrRoundAction;
use App\Models\Order;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\Table;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

class QrCredentialHandoverTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-07 12:00:00', 'UTC'));
        $this->seatingBranch();
        foreach (['qr_table_card_enabled' => 'on', 'dine_in_round_mode' => 'staff_confirm'] as $key => $value) {
            DB::table('pos_branch_settings')->insert([
                'company_id' => 100, 'branch_id' => 10, 'key' => $key, 'value' => json_encode($value),
            ]);
        }
    }

    private function bind(Table $table, string $secret): QrSession
    {
        $result = app(BindQrTableSessionAction::class)->handle($table->qr_token, $secret);

        return QrSession::query()->where('uuid', $result['session_uuid'])->firstOrFail();
    }

    private function orderedTable(): array
    {
        $table = $this->seatingTable();
        $credential = $this->bind($table, 'old-phone');
        $product = $this->seatingProduct();
        app(SubmitDineInQrRoundAction::class)->handle($credential->id, [
            'client_request_id' => 'original-round', 'phone' => '91234567', 'plate_number' => 'SYNTHETIC 9',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
        ], '127.0.0.1');

        return [$table, $credential->fresh(), Order::query()->sole(), QrOrderRound::query()->sole()];
    }

    public function test_release_and_bind_change_only_bill_credential_and_preserve_the_pending_round(): void
    {
        [$table, $old, $bill, $round] = $this->orderedTable();
        $till = $this->seatingDevice();
        $billBefore = $bill->fresh()->getRawOriginal();
        $roundBefore = $round->fresh()->getRawOriginal();
        $seatingBefore = $old->tableSession->getRawOriginal();
        $this->withToken($till->plainTextToken)->postJson('/api/v1/device/tables/'.$old->tableSession->uuid.'/release-credential')
            ->assertOk()->assertJsonPath('data.released', 1)->assertJsonPath('data.bill_uuid', $bill->uuid);
        $this->assertSame($billBefore, $bill->fresh()->getRawOriginal());
        $this->assertSame($roundBefore, $round->fresh()->getRawOriginal());
        $this->assertSame($seatingBefore, $old->tableSession->fresh()->getRawOriginal());
        $this->assertSame(['released' => 0], app(ReleaseTableCredentialAction::class)->handle($till, $old->tableSession->uuid));
        $this->withHeaders(['X-QR-Session' => $old->uuid, 'X-QR-Client-Secret' => 'old-phone'])
            ->getJson('/api/v1/public/qr/status')->assertNotFound()->assertJsonPath('errors.0.code', 'qr_session_not_found');
        $response = $this->postJson('/api/v1/public/qr/table-bind', [
            'table_token' => $table->qr_token, 'client_secret' => 'new-phone',
        ])->assertOk()->assertJsonPath('data.handover', true);
        $current = QrSession::query()->where('uuid', $response->json('data.session_uuid'))->sole();
        $billBefore['qr_session_id'] = $current->id;
        $this->assertSame($billBefore, $bill->fresh()->getRawOriginal());
        $this->assertSame($roundBefore, $round->fresh()->getRawOriginal());
        $this->assertSame($old->id, $current->handover_from_id);
        $this->assertSame($old->table_session_id, $current->table_session_id);
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertDatabaseHas('pos_qr_session_scans', ['outcome' => 'handover', 'qr_session_id' => $current->id]);
        $event = DB::table('pos_table_session_events')->latest('id')->first();
        $this->assertSame('attached', $event->event_type);
        $this->assertSame($old->uuid, json_decode($event->payload, true)['handover_from']);
        $this->postJson('/api/v1/public/qr/table-bind', [
            'table_token' => $table->qr_token, 'client_secret' => 'third-phone',
        ])->assertOk()->assertJsonPath('data.read_only', true)->assertJsonPath('data.session_uuid', null);
        $this->assertSame($current->id, $bill->fresh()->qr_session_id);
        app(ConfirmDineInQrRoundAction::class)->handle($till, $round->id);
        $this->assertSame('accepted', $round->fresh()->status);
        $this->assertSame($old->id, $round->fresh()->qr_session_id);
        $this->assertSame('1.000', $bill->fresh()->grand_total);
        fwrite(STDOUT, "\nT9_BIND_HANDOVER=".json_encode($response->json())."\n");
    }

    public function test_multiple_handovers_keep_a_round_confirmable_through_a_phone_that_added_no_round(): void
    {
        [$table, $old, $bill, $round] = $this->orderedTable();
        $till = $this->seatingDevice();
        app(ReleaseTableCredentialAction::class)->handle($till, $old->tableSession->uuid);
        $middle = $this->bind($table, 'middle-phone');
        app(ReleaseTableCredentialAction::class)->handle($till, $old->tableSession->uuid);
        $current = $this->bind($table, 'current-phone');
        foreach ([$old, $middle] as $released) {
            $released->update(['closed_at' => now()->subDays(40), 'released_at' => now()->subDays(40)]);
        }
        $this->artisan('qr:prune-sessions')->expectsOutput('expired=0 deleted=0 seatings_expired=0')->assertExitCode(0);
        $this->assertSame($middle->id, $current->fresh()->handover_from_id);
        $this->assertSame($old->id, $middle->fresh()->handover_from_id);
        $this->assertSame(0, QrOrderRound::query()->where('qr_session_id', $middle->id)->count());
        $this->assertDatabaseCount('pos_qr_order_rounds', 1);
        app(ConfirmDineInQrRoundAction::class)->handle($till, $round->id);
        $this->assertSame('accepted', $round->fresh()->status);
        $this->assertSame($old->id, $round->fresh()->qr_session_id);
        $this->assertSame($current->id, $bill->fresh()->qr_session_id);
    }

    public function test_released_round_history_is_retained_even_without_a_successor_and_unrelated_credentials_still_prune(): void
    {
        [$table, $old, $bill, $round] = $this->orderedTable();
        app(ReleaseTableCredentialAction::class)->handle($this->seatingDevice(), $old->tableSession->uuid);
        $current = $this->bind($table, 'new-phone');
        // The retained round itself must protect its credential independently
        // of the successor-chain guard.
        $current->update(['handover_from_id' => null]);
        $old->update(['closed_at' => now()->subDays(40)]);
        $unrelated = $this->bind($this->seatingTable('Unrelated'), 'other');
        $unrelated->update(['status' => 'closed', 'closed_at' => now()->subDays(40)]);
        $this->artisan('qr:prune-sessions')->assertExitCode(0);
        $this->assertNotNull($old->fresh());
        $this->assertNotNull($round->fresh());
        $this->assertNull($unrelated->fresh());
        $this->assertSame($current->id, $bill->fresh()->qr_session_id);
    }

    public function test_nonreleased_foreign_or_other_seating_ancestors_cannot_authorize_a_round(): void
    {
        [$table, $old, $bill, $round] = $this->orderedTable();
        $till = $this->seatingDevice();
        app(ReleaseTableCredentialAction::class)->handle($till, $old->tableSession->uuid);
        $current = $this->bind($table, 'current');
        $valid = $old->fresh()->getRawOriginal();
        foreach ([
            ['released_at' => null], ['company_id' => 101], ['branch_id' => 11],
            ['table_id' => $this->seatingTable('Other')->id],
            ['table_session_id' => $this->seatingRow($this->seatingTable('Other seating'))->id],
        ] as $invalid) {
            DB::table('pos_qr_sessions')->where('id', $old->id)->update($invalid);
            try {
                app(WithLockedDineInQrRoundAction::class)->handle($till, $round->id, fn () => $this->fail('Invalid lineage authorized a round.'));
                $this->fail('Invalid lineage must be refused.');
            } catch (QrDineInException $exception) {
                $this->assertSame('qr_round_not_found', $exception->codeName);
            }
            DB::table('pos_qr_sessions')->where('id', $old->id)->update($valid);
        }
        $this->assertSame('pending_confirmation', $round->fresh()->status);
        $this->assertSame($current->id, $bill->fresh()->qr_session_id);
    }

    public function test_release_refuses_frozen_bills_and_live_claims_without_mutation(): void
    {
        [$table, $old, $bill] = $this->orderedTable();
        $till = $this->seatingDevice();
        foreach (['held', 'awaiting_payment', 'kitchen', 'paid'] as $status) {
            $bill->update(['status' => $status]);
            $this->assertReleaseRefused($till, $old->tableSession, 'qr_release_bill_frozen');
        }
        $bill->update([
            'status' => 'awaiting_payment', 'charge_device_id' => $till->id,
            'charge_claimed_at' => now(), 'charge_deadline_at' => now()->addMinutes(2),
        ]);
        $this->assertTrue(Order::query()->whereKey($bill->id)->withLiveClaim()->exists());
        $this->assertReleaseRefused($till, $old->tableSession, 'qr_release_bill_frozen');
        $bill->update(['status' => 'open', 'charge_device_id' => null, 'charge_claimed_at' => null, 'charge_deadline_at' => null]);
        $old->update(['status' => 'ordered']);
        $this->assertReleaseRefused($till, $old->tableSession, 'qr_release_bill_frozen');
        $this->assertNull($old->fresh()->released_at);
        $this->assertSame($old->id, $bill->fresh()->qr_session_id);
    }

    private function assertReleaseRefused($device, TableSession $seating, string $code): void
    {
        try {
            app(ReleaseTableCredentialAction::class)->handle($device, $seating->uuid);
            $this->fail('The unsafe release must be refused.');
        } catch (QrDineInException $exception) {
            $this->assertSame($code, $exception->codeName);
        }
    }

    public function test_release_rejects_joined_unattended_and_foreign_branch_targets(): void
    {
        $primary = $this->seatingRow($this->seatingTable());
        $joined = $this->seatingRow($this->seatingTable('Joined'), ['merged_into_id' => $primary->id]);
        $this->assertReleaseRefused($this->seatingDevice(), $joined, 'qr_table_joined');
        $this->assertReleaseRefused($this->seatingDevice('payment_station'), $primary, 'device_not_attended');
        $this->assertReleaseRefused($this->seatingDevice(branchId: 11), $primary, 'qr_table_not_found');
    }

    public function test_pending_and_expired_bill_credentials_release_but_expiry_alone_cannot_handover(): void
    {
        [$table, $old, $bill] = $this->orderedTable();
        $old->update(['expires_at' => now()]);
        $this->postJson('/api/v1/public/qr/table-bind', [
            'table_token' => $table->qr_token, 'client_secret' => 'not-released',
        ])->assertConflict()->assertJsonPath('errors.0.code', 'qr_table_has_unpaid_order');
        $this->assertSame('expired', $old->fresh()->status);
        $this->assertNull($old->fresh()->released_at);
        $till = $this->seatingDevice();
        $release = app(ReleaseTableCredentialAction::class)->handle($till, $old->tableSession->uuid);
        $this->assertSame(1, $release['released']);
        $this->assertSame('expired', $old->fresh()->status);
        $current = $this->bind($table, 'released-replacement');
        $this->assertSame($current->id, $bill->fresh()->qr_session_id);
        $pending = $this->bind($this->seatingTable('Pending'), 'pending');
        $pending->update(['status' => 'pending', 'bound_at' => null]);
        $this->assertSame(1, app(ReleaseTableCredentialAction::class)->handle($till, $pending->tableSession->uuid)['released']);
        $this->assertSame('closed', $pending->fresh()->status);
        fwrite(STDOUT, "\nT9_RELEASE=".json_encode($release)."\n");
    }
}
