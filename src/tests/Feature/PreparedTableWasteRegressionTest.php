<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\SyncEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The product.waste sync handler: a device records that cooked or bought-in
 * products on its branch shelf were wasted — a signed-negative 'waste'
 * ProductStockMovement (with reason + frozen cost) + the shelf decrement. The
 * merchant Loss/Waste report surfaces it. Wastage is never an expense.
 */
class PreparedTableWasteRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Phase 4 — the product.waste recorder (staff 7) must exist in the tenant.
        $this->seedPosStaff([7]);
    }

    private function device(string $token = 'mdev_w'): Device
    {
        return Device::factory()->paired($token)->create(['company_id' => 100, 'branch_id' => 10]);
    }

    private function seedProduct(int $id, string $mode, ?string $costPrice, string $name = 'Item'): void
    {
        DB::table('pos_products')->insert([
            'id' => $id, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'category_id' => null,
            'name' => $name, 'base_price' => '1.000', 'cost_price' => $costPrice,
            'stock_mode' => $mode, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedShelf(int $productId, string $qty): void
    {
        DB::table('pos_branch_product')->insert([
            'branch_id' => 10, 'product_id' => $productId, 'is_available' => true,
            'stock_qty' => $qty, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function wasteEvent(array $payload): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'product.waste',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => $payload,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function push(array $events, string $token = 'mdev_w'): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => $events]);
    }

    public function test_prepared_table_waste_accepts_negative_and_zero_shelf_and_replays_once(): void
    {
        $device = $this->device();
        foreach (['negative' => '-21.000', 'zero' => '0.000', 'legacy' => '-4.000'] as $kind => $stock) {
            $id = ['negative' => 1, 'zero' => 2, 'legacy' => 3][$kind];
            $this->seedProduct($id, 'unit', '0.200');
            $this->seedShelf($id, $stock);
            $requestId = (string) Str::uuid();
            $at = now()->startOfSecond();
            SyncEvent::create([
                'client_event_id' => $requestId, 'device_id' => $device->id,
                'event_type' => 'table.session.cancel_line', 'client_timestamp' => $at,
                'server_received_at' => $at, 'processed_at' => $at, 'ack_status' => 'processed',
                'payload_json' => ['client_request_id' => $requestId, 'product_id' => $id,
                    'qty' => 1, 'prepared' => true, 'staff_id' => 7],
                'result_json' => ['outcome' => 'bill_terminal', 'cancelled_qty' => 0],
            ]);
            $payload = ['lines' => [['product_id' => $id, 'qty' => 1, 'reason' => 'other']],
                'note' => 'cancelled after preparation — table 1, ref F25-TEST',
                'staff_id' => 7, 'wasted_at' => $at->toIso8601String()];
            if ($kind !== 'legacy') {
                $payload['table_cancellation_request_id'] = $requestId;
            }
            $event = $this->wasteEvent($payload);
            $event['client_timestamp'] = $at->toIso8601String();
            $response = $this->push([$event])->assertOk();
            $this->assertSame('processed', $response->json('data.results.0.status'), json_encode($response->json()));
            $this->assertSame((float) $stock - 1, (float) DB::table('pos_branch_product')->where('product_id', $id)->value('stock_qty'));
            $this->assertDatabaseHas('pos_product_stock_movements', ['product_id' => $id, 'branch_id' => 10,
                'movement_type' => 'waste', 'quantity' => '-1.000', 'unit_cost' => '0.200', 'recorded_by_pos_staff_id' => 7]);
            $this->push([$event])->assertOk()->assertJsonPath('data.results.0.status', 'processed');
            $this->assertSame(1, DB::table('pos_product_stock_movements')->where('product_id', $id)->count());
            $this->assertSame((float) $stock - 1, (float) DB::table('pos_branch_product')->where('product_id', $id)->value('stock_qty'));
        }
    }

    public function test_text_alone_or_another_devices_cancellation_does_not_bypass_shelf_validation(): void
    {
        $this->device();
        $other = $this->device('other-device');
        $this->seedProduct(1, 'unit', '0.200');
        $this->seedShelf(1, '-2.000');
        $requestId = (string) Str::uuid();
        SyncEvent::create(['client_event_id' => $requestId, 'device_id' => $other->id,
            'event_type' => 'table.session.cancel_line', 'client_timestamp' => now(),
            'server_received_at' => now(), 'processed_at' => now(), 'ack_status' => 'processed',
            'payload_json' => ['product_id' => 1, 'qty' => 1, 'prepared' => true, 'staff_id' => 7],
            'result_json' => ['outcome' => 'bill_terminal', 'cancelled_qty' => 0]]);
        foreach ([null, $requestId] as $ref) {
            $payload = ['lines' => [['product_id' => 1, 'qty' => 1, 'reason' => 'other']],
                'staff_id' => 7, 'note' => 'cancelled after preparation — table 1'];
            if ($ref !== null) {
                $payload['table_cancellation_request_id'] = $ref;
            }
            $this->push([$this->wasteEvent($payload)])->assertOk()->assertJsonPath('data.results.0.status', 'failed');
        }
        $this->assertDatabaseCount('pos_product_stock_movements', 0);
        $this->assertSame(-2.0, (float) DB::table('pos_branch_product')->where('product_id', 1)->value('stock_qty'));
    }
}
