<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\QrDineInException;
use App\Actions\Qr\SubmitDineInQrRoundAction;
use App\Actions\Qr\SupersedeAbandonedTableSessionAction;
use App\Models\Customer;
use App\Models\QrSession;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

class QrTableCardAdmissionTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-07 12:00:00', 'UTC'));
    }

    private function card(array $attributes = []): QrSession
    {
        $table = $this->seatingTable();

        return QrSession::query()->create(array_replace([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => null, 'table_id' => $table->id, 'origin' => 'table_card',
            'status' => QrSession::STATUS_ACTIVE, 'token' => Str::random(64),
            'token_expires_at' => now()->addHours(6), 'expires_at' => now()->addHours(6),
            'client_secret_hash' => QrSession::hashClientSecret('synthetic-card-secret'),
            'bound_at' => now(),
        ], $attributes));
    }

    private function authenticate(QrSession $session): void
    {
        $this->withHeaders([
            'X-QR-Session' => $session->uuid, 'X-QR-Client-Secret' => 'synthetic-card-secret',
        ]);
    }

    public function test_only_table_card_with_a_table_bypasses_the_device_gate(): void
    {
        foreach ([null, 'station', 'staff_till', 'TABLE_CARD'] as $origin) {
            $session = $this->card(['origin' => $origin]);
            $this->authenticate($session);
            foreach (['menu', 'status'] as $path) {
                $this->getJson('/api/v1/public/qr/'.$path)->assertNotFound()
                    ->assertJsonPath('errors.0.code', 'qr_session_not_found');
            }
        }
        $quick = $this->card(['table_id' => null]);
        $this->authenticate($quick);
        $this->getJson('/api/v1/public/qr/status')->assertNotFound()
            ->assertJsonPath('errors.0.code', 'qr_session_not_found');
        $card = $this->card();
        $this->authenticate($card);
        $this->getJson('/api/v1/public/qr/status')->assertOk();
    }

    public function test_released_card_is_refused_even_by_closed_status_and_direct_round_replay(): void
    {
        $session = $this->card(['status' => 'closed', 'released_at' => now(), 'closed_at' => now()]);
        $this->authenticate($session);
        foreach (['menu', 'status'] as $path) {
            $this->getJson('/api/v1/public/qr/'.$path)->assertNotFound()
                ->assertJsonPath('errors.0.code', 'qr_session_not_found');
        }
        foreach (['table-round', 'table-finish'] as $path) {
            $this->postJson('/api/v1/public/qr/'.$path, [])->assertNotFound()
                ->assertJsonPath('errors.0.code', 'qr_session_not_found');
        }
        try {
            app(SubmitDineInQrRoundAction::class)->handle($session->id, [
                'client_request_id' => 'released-round', 'lines' => [],
            ], '127.0.0.1');
            $this->fail('Released credentials must never replay or append a round.');
        } catch (QrDineInException $exception) {
            $this->assertSame('qr_session_not_found', $exception->codeName);
        }
        $this->assertSame('closed', $session->fresh()->status);
        $this->assertDatabaseCount('pos_orders', 0);
    }

    public function test_card_expires_by_its_own_horizon_and_station_credentials_keep_the_station_gate(): void
    {
        $card = $this->card(['expires_at' => now()]);
        $this->authenticate($card);
        $this->getJson('/api/v1/public/qr/status')->assertNotFound();
        $this->assertSame('expired', $card->fresh()->status);
        $station = $this->seatingDevice('payment_station', attributes: ['status' => 'inactive']);
        $credential = $this->card(['device_id' => $station->id, 'origin' => 'station']);
        $this->authenticate($credential);
        $this->getJson('/api/v1/public/qr/status')->assertNotFound();
        $this->assertNull(app(BindQrTableSessionAction::class)->handle($credential->table->qr_token, 'other-secret'));
        $station->update(['status' => 'active']);
        $this->getJson('/api/v1/public/qr/status')->assertOk();
    }

    public function test_handover_http_round_keeps_customer_identity_without_requiring_phone_and_preserves_replay(): void
    {
        $old = $this->card(['status' => 'closed', 'released_at' => now()]);
        $seating = $this->seatingRow($old->table);
        $old->update(['table_session_id' => $seating->id]);
        $session = $this->card([
            'table_id' => $old->table_id, 'table_session_id' => $seating->id, 'handover_from_id' => $old->id,
        ]);
        $customer = Customer::query()->create([
            'company_id' => 100, 'uuid' => (string) Str::uuid(), 'phone' => '91234567', 'name' => 'Synthetic customer',
        ]);
        $order = $this->seatingOrder($seating, [
            'qr_session_id' => $session->id, 'source' => 'qr_web',
            'customer_id' => $customer->id, 'plate_number' => 'SYNTHETIC 9',
        ]);
        $product = $this->seatingProduct();
        $payload = [
            'client_request_id' => 'handover-http-round',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
        ];
        $this->authenticate($session);
        $this->postJson('/api/v1/public/qr/table-round', $payload)
            ->assertCreated()->assertJsonPath('data.order.uuid', $order->uuid);
        $this->assertSame((int) $customer->id, (int) $order->fresh()->customer_id);
        $this->assertSame('SYNTHETIC 9', $order->fresh()->plate_number);
        $this->postJson('/api/v1/public/qr/table-round', $payload)
            ->assertCreated()->assertJsonPath('data.replayed', true);
        $payload['client_request_id'] = 'handover-identity-refused';
        $payload['phone'] = '91234568';
        $this->postJson('/api/v1/public/qr/table-round', $payload)
            ->assertUnprocessable()->assertJsonPath('errors.0.code', 'qr_round_identity_already_set');
        $this->assertDatabaseCount('pos_qr_order_rounds', 1);
        $this->assertSame((int) $customer->id, (int) $order->fresh()->customer_id);
    }

    public function test_card_open_can_supersede_abandoned_seating_with_null_device_attribution(): void
    {
        $table = $this->seatingTable();
        $seating = $this->seatingRow($table);
        $result = DB::transaction(fn () => app(SupersedeAbandonedTableSessionAction::class)
            ->handle($table, null, now(), 100, 10));
        $this->assertSame((int) $seating->id, (int) $result->id);
        $this->assertSame(TableSession::STATUS_EXPIRED, $seating->fresh()->status);
        $this->assertNull($seating->fresh()->closed_by_device_id);
        $this->assertDatabaseHas('pos_table_session_events', [
            'table_session_id' => $seating->id, 'event_type' => 'expired', 'device_id' => null,
        ]);
    }
}
