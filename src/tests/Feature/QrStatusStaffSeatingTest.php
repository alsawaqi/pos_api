<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\ClaimQrSettlementAction;
use App\Actions\Qr\ClearDineInQrTableAction;
use App\Actions\Qr\ConfirmDineInQrRoundAction;
use App\Actions\Qr\ReleaseTableCredentialAction;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

class QrStatusStaffSeatingTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-07 12:00:00', 'UTC'));
        $this->seatingBranch();
        DB::table('pos_branch_settings')->insert([
            'company_id' => 100, 'branch_id' => 10, 'key' => 'qr_table_card_enabled', 'value' => '"on"',
        ]);
    }

    public function test_status_shows_staff_rounds_before_adoption_without_granting_finish_or_changing_the_bill(): void
    {
        $table = $this->seatingTable();
        $seating = $this->seatingRow($table);
        $bill = $this->seatingOrder($seating, ['grand_total' => '2.000', 'subtotal' => '2.000']);
        $one = $this->seatingRound($seating, $bill);
        $two = $this->seatingRound($seating, $bill, ['round_no' => 2]);
        $rejected = $this->seatingRound($seating, $bill, ['round_no' => 3, 'status' => 'rejected']);
        $before = $bill->fresh()->getRawOriginal();
        $bind = app(BindQrTableSessionAction::class)->handle($table->qr_token, 'owner');
        $this->withHeaders(['X-QR-Session' => $bind['session_uuid'], 'X-QR-Client-Secret' => 'owner']);
        $status = $this->getJson('/api/v1/public/qr/status')->assertOk()
            ->assertJsonPath('data.order', null)
            ->assertJsonPath('data.dine_in.running_total_baisas', 2000)
            ->assertJsonPath('data.dine_in.bill_totals.grand_total_baisas', 2000)
            ->assertJsonPath('data.dine_in.bill_totals.manual_discount_baisas', 0)
            ->assertJsonPath('data.dine_in.seating.opened_by', 'staff')
            ->assertJsonPath('data.dine_in.seating.temp_reference', $seating->temp_reference)
            ->assertJsonPath('data.dine_in.credential.identity_required', false)
            ->assertJsonPath('data.dine_in.credential.identity_allowed', true)
            ->assertJsonPath('data.dine_in.credential.staff_confirm', true)
            ->assertJsonPath('data.dine_in.finish_and_pay.allowed', false)
            ->assertJsonPath('data.dine_in.finish_and_pay.refusal_code', 'qr_dine_in_order_required')
            ->assertJsonPath('data.dine_in.round_submission.allowed', true);
        $this->assertSame([$one->id, $two->id, $rejected->id], array_column($status->json('data.dine_in.rounds'), 'id'));
        $this->assertSame(['staff', 'staff', 'staff'], array_column($status->json('data.dine_in.rounds'), 'entered_by'));
        $this->assertSame('rejected', $status->json('data.dine_in.rounds.2.status'));
        $this->postJson('/api/v1/public/qr/table-finish', ['payment_choice' => 'counter'])
            ->assertConflict()->assertJsonPath('errors.0.code', 'qr_dine_in_order_required');
        $this->assertSame($before, $bill->fresh()->getRawOriginal());
        $round = $this->postJson('/api/v1/public/qr/table-round', [
            'client_request_id' => 'adoption', 'phone' => '91234567',
            'lines' => [['product_id' => $this->seatingProduct()->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
        ])->assertCreated()->assertJsonPath('data.round.status', 'pending_confirmation');
        $after = $this->getJson('/api/v1/public/qr/status')->assertOk()
            ->assertJsonPath('data.order.uuid', $bill->uuid)
            ->assertJsonPath('data.dine_in.running_total_baisas', 2000)
            ->assertJsonPath('data.dine_in.bill_totals.grand_total_baisas', 2000)
            ->assertJsonPath('data.dine_in.bill_totals.manual_discount_baisas', 0)
            ->assertJsonPath('data.dine_in.credential.identity_required', false)
            ->assertJsonPath('data.dine_in.credential.identity_allowed', true)
            ->assertJsonPath('data.dine_in.rounds.3.entered_by', 'customer');
        $this->assertSame(4, $round->json('data.round.round_no'));
        $this->assertDatabaseCount('pos_orders', 1);
        $this->assertSame('2.000', $bill->fresh()->grand_total);
        fwrite(STDOUT, "\nT9_STATUS_STAFF_BEFORE=".json_encode($status->json())."\nT9_STATUS_STAFF_AFTER=".json_encode($after->json())."\n");
    }

    public function test_handover_status_freezes_identity_and_finishing_settling_and_paying_closes_the_current_phone(): void
    {
        $table = $this->seatingTable();
        $till = $this->seatingDevice();
        $product = $this->seatingProduct();
        $bind = app(BindQrTableSessionAction::class)->handle($table->qr_token, 'old');
        $old = QrSession::query()->where('uuid', $bind['session_uuid'])->sole();
        $this->withHeaders(['X-QR-Session' => $old->uuid, 'X-QR-Client-Secret' => 'old']);
        $payload = [
            'client_request_id' => 'old-round', 'phone' => '91234567', 'plate_number' => 'SYNTHETIC 9',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
        ];
        $first = $this->postJson('/api/v1/public/qr/table-round', $payload)->assertCreated();
        app(ConfirmDineInQrRoundAction::class)->handle($till, $first->json('data.round.id'));
        $this->getJson('/api/v1/public/qr/status')->assertOk()->assertJsonPath('data.dine_in.credential.identity_required', false);
        $bill = Order::query()->sole();
        $customer = $bill->customer_id;
        app(ReleaseTableCredentialAction::class)->handle($till, $old->fresh()->tableSession->uuid);
        $bind = app(BindQrTableSessionAction::class)->handle($table->qr_token, 'new');
        $current = QrSession::query()->where('uuid', $bind['session_uuid'])->sole();
        $this->withHeaders(['X-QR-Session' => $current->uuid, 'X-QR-Client-Secret' => 'new']);
        $status = $this->getJson('/api/v1/public/qr/status')->assertOk()
            ->assertJsonPath('data.order.uuid', $bill->uuid)
            ->assertJsonPath('data.dine_in.credential.identity_required', false)
            ->assertJsonPath('data.dine_in.credential.staff_confirm', true)
            ->assertJsonPath('data.dine_in.rounds.0.entered_by', 'customer');
        $payload['client_request_id'] = 'new-identity';
        $this->postJson('/api/v1/public/qr/table-round', $payload)->assertUnprocessable()
            ->assertJsonPath('errors.0.code', 'qr_round_identity_already_set');
        unset($payload['phone'], $payload['plate_number']);
        $payload['client_request_id'] = 'new-round';
        $next = $this->postJson('/api/v1/public/qr/table-round', $payload)->assertCreated()
            ->assertJsonPath('data.order.uuid', $bill->uuid)->assertJsonPath('data.round.round_no', 2)
            ->assertJsonPath('data.round.status', 'pending_confirmation');
        app(ConfirmDineInQrRoundAction::class)->handle($till, $next->json('data.round.id'));
        $this->postJson('/api/v1/public/qr/table-finish', ['payment_choice' => 'counter'])->assertOk();
        $claim = app(ClaimQrSettlementAction::class)->handle($till, ['order_uuid' => $bill->uuid]);
        $this->assertSame(2000, $claim['charge_amount_baisas']);
        $this->withToken($till->plainTextToken)->postJson('/api/v1/device/sync/push', [
            'events' => [[
                'client_event_id' => (string) Str::uuid(), 'event_type' => 'order.pay', 'client_timestamp' => now()->toIso8601String(),
                'payload' => [
                    'order_uuid' => $bill->uuid, 'paid_at' => now()->toIso8601String(),
                    'payments' => [['method' => 'cash', 'amount_baisas' => 2000]],
                ],
            ]],
        ])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->assertSame('paid', $bill->fresh()->status);
        $this->assertSame($customer, $bill->fresh()->customer_id);
        $this->assertSame($current->id, $bill->fresh()->qr_session_id);
        $this->assertSame('closed', $current->fresh()->status);
        $this->assertNull($current->fresh()->released_at);
        $this->assertSame(TableSession::STATUS_CLOSED, $current->fresh()->tableSession->status);
        app(ClearDineInQrTableAction::class)->handle($till, $table->id);
        $newParty = app(BindQrTableSessionAction::class)->handle($table->qr_token, 'next-party');
        $this->assertFalse($newParty['handover']);
        $this->assertSame($current->id, $bill->fresh()->qr_session_id);
        fwrite(STDOUT, "\nT9_STATUS_HANDOVER=".json_encode($status->json())."\n");
    }
}
