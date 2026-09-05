<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Order;
use App\Models\QrSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class QrDeviceLessSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_device_less_sessions_remain_closed_to_public_rounds_and_station_charges(): void
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
}
