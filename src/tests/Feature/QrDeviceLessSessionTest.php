<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\ClaimQrChargeAction;
use App\Actions\Qr\ConfirmDineInQrRoundAction;
use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class QrDeviceLessSessionTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    public function test_device_less_origin_null_sessions_remain_closed_to_public_rounds_and_station_charges(): void
    {
        $this->travelTo(Carbon::parse('2026-09-05 12:00:00', 'UTC'));
        $station = Device::factory()->paired('t2-device-less-station')->create([
            'company_id' => 100,
            'branch_id' => 10,
            'device_type' => 'payment_station',
        ]);
        DB::table('pos_tables')->insert([
            'id' => 200,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'floor_id' => 10,
            'label' => 'T2',
        ]);

        $secret = 't2-bound-phone-client-secret';
        $dineInUuid = (string) Str::uuid();
        $quickUuid = (string) Str::uuid();
        foreach ([
            [$dineInUuid, 200, QrSession::STATUS_ACTIVE],
            [$quickUuid, null, QrSession::STATUS_ORDERED],
        ] as [$uuid, $tableId, $status]) {
            DB::table('pos_qr_sessions')->insert([
                'uuid' => $uuid,
                'company_id' => 100,
                'branch_id' => 10,
                'device_id' => null,
                'table_id' => $tableId,
                'token' => hash('sha256', $uuid),
                'token_expires_at' => now()->addMinute(),
                'client_secret_hash' => QrSession::hashClientSecret($secret),
                'status' => $status,
                'bound_at' => now(),
                'last_seen_at' => now(),
                'expires_at' => now()->addHour(),
            ]);
            $this->assertNull(DB::table('pos_qr_sessions')->where('uuid', $uuid)->value('device_id'));
        }

        $this->withHeaders([
            'X-QR-Session' => $dineInUuid,
            'X-QR-Client-Secret' => $secret,
        ])->getJson('/api/v1/public/qr/menu')
            ->assertNotFound()
            ->assertJsonPath('errors.0.code', 'qr_session_not_found');

        $this->postJson('/api/v1/public/qr/table-round', [
            'client_request_id' => 't2-device-less-round',
            'phone' => '91234567',
            'lines' => [['product_id' => 1, 'qty' => '1.000']],
        ])->assertNotFound()
            ->assertJsonPath('errors.0.code', 'qr_session_not_found');

        $orderUuid = (string) Str::uuid();
        DB::table('pos_orders')->insert([
            'uuid' => $orderUuid,
            'company_id' => 100,
            'branch_id' => 10,
            'device_id' => null,
            'qr_session_id' => DB::table('pos_qr_sessions')->where('uuid', $quickUuid)->value('id'),
            'order_type' => 'quick',
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'source' => Order::SOURCE_QR_WEB,
            'temp_reference' => 'T-0905-001',
            'subtotal' => '2.500',
            'grand_total' => '2.500',
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken((string) $station->device_token)
            ->postJson('/api/v1/device/qr/claim-charge', ['order_uuid' => $orderUuid])
            ->assertStatus(409)
            ->assertJsonPath('errors.0.code', 'order_not_bound_to_device_session');

        $this->assertDatabaseCount('pos_qr_order_rounds', 0);
        $this->assertDatabaseHas('pos_orders', [
            'uuid' => $orderUuid,
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'charge_device_id' => null,
        ]);
    }

    public function test_device_less_table_card_admits_menu_status_round_finish_and_same_branch_station_claim(): void
    {
        $this->travelTo(Carbon::parse('2026-09-07 12:00:00', 'UTC'));
        $table = $this->seatingTable();
        $till = $this->seatingDevice();
        $station = $this->seatingDevice('payment_station');
        $product = $this->seatingProduct();
        DB::table('pos_branch_settings')->insert([
            'company_id' => 100, 'branch_id' => 10,
            'key' => 'dine_in_round_mode', 'value' => '"staff_confirm"',
        ]);
        $session = QrSession::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => null, 'table_id' => $table->id, 'origin' => 'table_card',
            'status' => QrSession::STATUS_ACTIVE, 'token' => Str::random(64),
            'token_expires_at' => now()->addHours(6), 'expires_at' => now()->addHours(6),
            'client_secret_hash' => QrSession::hashClientSecret('synthetic-card-secret'),
            'bound_at' => now(),
        ]);
        $this->withHeaders([
            'X-QR-Session' => $session->uuid, 'X-QR-Client-Secret' => 'synthetic-card-secret',
        ])->getJson('/api/v1/public/qr/menu')->assertOk();
        $this->getJson('/api/v1/public/qr/status')->assertOk();
        $round = $this->postJson('/api/v1/public/qr/table-round', [
            'client_request_id' => 't9-card-admission-round', 'phone' => '91234567',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
        ])->assertCreated()->assertJsonPath('data.round.status', 'pending_confirmation');
        $order = Order::query()->sole();
        $this->assertNull($order->device_id);
        $this->assertSame((int) $session->id, (int) $order->qr_session_id);
        $this->assertSame('table_card', $session->fresh()->tableSession->origin);
        $this->assertNull($session->fresh()->tableSession->opened_by_device_id);
        app(ConfirmDineInQrRoundAction::class)->handle($till, (int) $round->json('data.round.id'));
        $this->postJson('/api/v1/public/qr/table-finish', [
            'payment_choice' => 'station',
        ])->assertOk();
        $this->assertSame(QrSession::STATUS_ORDERED, $session->fresh()->status);
        $claim = app(ClaimQrChargeAction::class)->handle($station, ['order_uuid' => $order->uuid]);
        $this->assertSame(1000, $claim['charge_amount_baisas']);
        $this->assertSame((int) $station->id, (int) $order->fresh()->charge_device_id);
        $this->assertNull($order->fresh()->device_id);
    }
}
