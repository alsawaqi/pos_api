<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class T12Fix5CustomerWriteSafetyTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    public static function refusedIdentities(): iterable
    {
        foreach (['create', 'hold', 'transfer', 'attach-http', 'attach-sync'] as $route) {
            foreach (['deleted', 'cross-company', 'cross-company-link'] as $identity) {
                yield $route.'/'.$identity => [$route, $identity];
            }
        }
    }

    #[DataProvider('refusedIdentities')]
    public function test_unmerged_deleted_and_foreign_customers_still_refused(string $route, string $identity): void
    {
        $device = $this->seatingDevice();
        $product = $this->seatingProduct();
        $customer = Customer::create(['uuid' => Str::uuid(), 'company_id' => $identity === 'cross-company' ? 200 : 100, 'name' => 'Refused', 'phone' => '90000001']);
        if ($identity === 'deleted') {
            $customer->delete();
        } elseif ($identity === 'cross-company-link') {
            $foreign = Customer::create(['uuid' => Str::uuid(), 'company_id' => 200, 'name' => 'Foreign', 'phone' => '90000002']);
            $customer->update(['merged_into_customer_id' => $foreign->id]);
            $customer->delete();
        }
        $this->withToken($device->device_token);
        $uuid = (string) Str::uuid();
        if (str_starts_with($route, 'attach')) {
            $seat = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
            $order = $this->seatingOrder($seat);
            $this->seatingRound($seat, $order);
            $payload = ['table_id' => $seat->table_id, 'seating_key' => $seat->client_request_id, 'queued_offline' => false, 'client_request_id' => $uuid,
                'adjustment' => ['kind' => 'customer', 'mode' => 'attach', 'customer_id' => $customer->id]];
            $type = 'table.session.adjust';
            if ($route === 'attach-http') {
                $this->postJson('/api/v1/device/tables/'.$seat->uuid.'/adjust', $payload)->assertConflict()->assertJsonPath('errors.0.code', 'customer_not_found');
            }
        } else {
            $payload = ['order' => ['uuid' => $uuid, 'order_type' => 'quick', 'source' => 'main_pos', 'opened_at' => now()->toIso8601String(), 'customer_id' => $customer->id,
                'subtotal_baisas' => 1000, 'discount_total_baisas' => 0, 'tax_total_baisas' => 0, 'grand_total_baisas' => 1000,
                'lines' => [['product_id' => $product->id, 'qty' => 1, 'unit_price_baisas' => 1000, 'line_total_baisas' => 1000]]]];
            $type = 'order.'.$route;
            if ($route === 'transfer') {
                $payload['target_device_id'] = $this->seatingDevice('handheld')->id;
            }
        }
        if ($route !== 'attach-http') {
            $response = $this->postJson('/api/v1/device/sync/push', ['events' => [[
                'client_event_id' => (string) Str::uuid(), 'event_type' => $type, 'client_timestamp' => now()->toIso8601String(), 'payload' => $payload,
            ]]])->assertOk();
            if ($route === 'attach-sync') {
                $response->assertJsonPath('data.results.0.status', 'processed')
                    ->assertJsonPath('data.results.0.result.outcome', 'refused')
                    ->assertJsonPath('data.results.0.result.refusal_code', 'customer_not_found');
            } else {
                $response->assertJsonPath('data.results.0.status', 'failed');
            }
        }
        if (isset($order)) {
            $this->assertNull($order->fresh()->customer_id);
        } else {
            $this->assertFalse(Order::where('uuid', $uuid)->exists());
        }
        $this->assertSame($identity !== 'cross-company', $customer->fresh()->trashed());
    }
}
