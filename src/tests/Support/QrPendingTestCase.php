<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\Qr\PresentQrPendingOrderAction;
use App\Models\Customer;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QrSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class QrPendingTestCase extends TestCase
{
    use RefreshDatabase;

    protected Device $till;

    protected Device $station;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-08 12:00:00'));
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->till = $this->device('fixed_pos');
        $this->station = $this->device('payment_station');
    }

    protected function device(string $type, int $branch = 10): Device
    {
        return Device::factory()->paired()->create([
            'company_id' => 100, 'branch_id' => $branch, 'device_type' => $type,
        ]);
    }

    protected function order(array $attributes = [], string $sessionCase = 'live'): Order
    {
        $session = $sessionCase === 'missing' ? null : QrSession::create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $this->station->id, 'token' => hash('sha256', (string) Str::uuid()),
            'token_expires_at' => now()->addMinute(),
            'status' => match ($sessionCase) {
                'closed' => 'closed', 'explicit_expired' => 'expired', default => 'ordered',
            },
            'expires_at' => $sessionCase === 'live' ? now()->addHour() : now()->subMinute(),
            'bound_at' => now()->subHour(), 'last_seen_at' => now()->subMinutes(10),
        ]);
        $customer = Customer::create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Synthetic QR customer',
            'phone' => 'synthetic-'.Str::uuid().'-5555', 'wallet_balance' => 0,
        ]);
        $order = Order::create($attributes + [
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10,
            'device_id' => $this->station->id, 'customer_id' => $customer->id,
            'qr_session_id' => $session?->id, 'client_request_id' => (string) Str::uuid(),
            'order_type' => 'quick', 'source' => 'qr_web', 'status' => 'held',
            'table_id' => null, 'temp_reference' => 'T-0908-001', 'receipt_number' => null,
            'subtotal' => '4.750', 'discount_total' => '0.000', 'tax_total' => '0.000',
            'grand_total' => '4.750', 'comp_total' => '0.000', 'opened_at' => now()->subSeconds(812),
        ]);
        OrderItem::create([
            'order_id' => $order->id, 'product_id' => null, 'product_name_snapshot' => 'Synthetic tea',
            'qty' => '1.000', 'unit_price_snapshot' => '4.750', 'line_discount' => '0.000',
            'line_total' => '4.750', 'status' => 'open',
        ]);

        return $order;
    }

    protected function charge(string $kind): array
    {
        if ($kind === 'none') {
            return [];
        }
        if ($kind === 'residue') {
            return ['charge_amount_baisas' => 4750];
        }

        return [
            'charge_device_id' => $this->station->id,
            'charge_amount_baisas' => 4750,
            'charge_roundup_amount_baisas' => 0,
            'charge_claimed_at' => now()->subMinute(),
            'charge_deadline_at' => $kind === 'expired_claim' ? now()->subSecond() : now()->addMinutes(3),
            'charge_outcome' => in_array($kind, ['live_claim', 'expired_claim'], true) ? null : $kind,
        ];
    }

    protected function getPending(?Device $device = null): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken((string) ($device ?? $this->till)->device_token)
            ->getJson('/api/v1/device/qr/pending-orders');
    }

    protected function move(Order $order, ?Device $device = null): TestResponse
    {
        return $this->postAs($device ?? $this->till, '/api/v1/device/qr/pending-orders/'.$order->uuid.'/to-counter');
    }

    protected function claim(Order $order, ?Device $device = null): TestResponse
    {
        return $this->postAs($device ?? $this->till, '/api/v1/device/qr/claim-settlement', ['order_uuid' => $order->uuid]);
    }

    protected function postAs(Device $device, string $url, array $payload = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken((string) $device->device_token)->postJson($url, $payload);
    }

    protected function snapshot(): array
    {
        $snapshot = [];
        foreach (['pos_orders', 'pos_qr_sessions', 'pos_order_items', 'pos_order_item_addons', 'pos_order_comps'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->map(fn ($row): array => (array) $row)->all();
        }

        return $snapshot;
    }

    protected function raw(Order $order): array
    {
        return (array) DB::table('pos_orders')->where('id', $order->id)->first();
    }

    protected function sessionRaw(Order $order): ?array
    {
        $row = DB::table('pos_qr_sessions')->where('id', $order->qr_session_id)->first();

        return $row === null ? null : (array) $row;
    }

    protected function withoutCharge(array $row): array
    {
        return array_diff_key($row, array_flip([...PresentQrPendingOrderAction::CHARGE_FIELDS, 'status', 'updated_at']));
    }
}
