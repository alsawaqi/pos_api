<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OrderItem;
use App\Models\SyncEvent;
use App\Models\TableSessionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class PreparedWasteCancellationIdentityTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPosStaff([7, 8]);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    public static function identityCases(): iterable
    {
        foreach (['sync', 'table'] as $firstSource) {
            foreach ([8, null] as $nextStaff) {
                foreach ([false, true] as $legacyResult) {
                    yield $firstSource.' to sync; staff '.($nextStaff ?? 'omitted').'; '.($legacyResult ? 'legacy result' : 'stamped result') => [$firstSource, $nextStaff, $legacyResult];
                }
            }
        }
    }

    #[DataProvider('identityCases')]
    public function test_one_cancellation_allowance_survives_proof_source_and_recorder_changes(
        string $firstSource,
        ?int $nextStaff,
        bool $legacyResult,
    ): void {
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
        $order = $this->seatingOrder($seating, ['subtotal' => '3.000', 'grand_total' => '3.000']);
        $product = $this->seatingProduct();
        $product->update(['stock_mode' => 'unit', 'cost_price' => '0.200']);
        DB::table('pos_branch_product')->insert([
            'branch_id' => 10, 'product_id' => $product->id, 'is_available' => true,
            'stock_qty' => '-2.000', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $item = OrderItem::query()->create([
            'order_id' => $order->id, 'product_id' => $product->id, 'product_name_snapshot' => 'Fixture',
            'qty' => 3, 'unit_price_snapshot' => '1.000', 'line_total' => '3.000',
            'line_discount' => '0.000', 'status' => OrderItem::STATUS_OPEN,
        ]);
        $this->seatingRound($seating, $order, [
            'priced_lines' => [[
                'line_index' => 0, 'product_id' => (int) $product->id, 'qty' => 3,
                'unit_price_baisas' => 1000, 'line_discount_baisas' => 0,
                'line_total_baisas' => 3000, 'addons' => [], 'notes' => null,
                'order_item_id' => (int) $item->id,
            ]], 'subtotal_baisas' => 3000, 'total_baisas' => 3000,
        ]);
        $requestId = (string) Str::uuid();
        $at = now()->startOfSecond()->toIso8601String();
        $cancel = [
            'seating_key' => $seating->client_request_id, 'table_id' => (int) $seating->table_id,
            'queued_offline' => false, 'client_request_id' => $requestId,
            'product_id' => (int) $product->id, 'addon_ids' => [], 'notes' => null,
            'qty' => 2, 'prepared' => true, 'cancelled_at' => $at, 'staff_id' => 7,
        ];
        $cancelEvent = ['client_event_id' => $requestId, 'event_type' => 'table.session.cancel_line',
            'client_timestamp' => $at, 'payload' => $cancel];
        if ($firstSource === 'sync') {
            $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [$cancelEvent]])
                ->assertOk()->assertJsonPath('data.results.0.result.outcome', 'cancelled');
        } else {
            $this->withToken($device->plainTextToken)->postJson('/api/v1/device/tables/'.$seating->uuid.'/cancel-line', $cancel)
                ->assertOk()->assertJsonPath('data.outcome', 'cancelled');
        }
        $journal = TableSessionEvent::query()->sole()->getRawOriginal();
        $this->assertSame('1.000', $item->fresh()->qty);
        $this->assertSame('1.000', $order->fresh()->grand_total);
        $waste = ['client_event_id' => (string) Str::uuid(), 'event_type' => 'product.waste',
            'client_timestamp' => $at, 'payload' => [
                'table_cancellation_request_id' => $requestId, 'staff_id' => 7,
                'note' => 'Prepared fixture loss',
                'lines' => [['product_id' => (int) $product->id, 'qty' => 1, 'reason' => 'other']],
            ]];
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [$waste]])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.table_cancellation_waste.source', $firstSource);
        if ($legacyResult) {
            // Shape of a pre-stamp success: keep its real movement and payload,
            // but omit the consumption stamp that did not exist in that build.
            $prior = SyncEvent::query()->where('client_event_id', $waste['client_event_id'])->sole();
            $result = $prior->result_json;
            unset($result['table_cancellation_waste']);
            $prior->update(['result_json' => $result]);
        }
        if ($firstSource === 'table') {
            $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [$cancelEvent]])
                ->assertOk()->assertJsonPath('data.results.0.result.outcome', 'replayed');
        }
        $second = $waste;
        $second['client_event_id'] = (string) Str::uuid();
        if ($nextStaff === null) {
            unset($second['payload']['staff_id']);
        } else {
            $second['payload']['staff_id'] = $nextStaff;
        }
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [$second]])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [$second]])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');
        $third = $waste;
        $third['client_event_id'] = (string) Str::uuid();
        $third['payload']['lines'][0]['qty'] = 0.25;
        $this->withToken($device->plainTextToken)->postJson('/api/v1/device/sync/push', ['events' => [$third]])
            ->assertOk()->assertJsonPath('data.results.0.status', 'failed');
        $this->assertSame(2, DB::table('pos_product_stock_movements')->count());
        $this->assertSame(-2.0, (float) DB::table('pos_product_stock_movements')->sum('quantity'));
        $this->assertSame(-4.0, (float) DB::table('pos_branch_product')->value('stock_qty'));
        $this->assertSame($journal, TableSessionEvent::query()->sole()->getRawOriginal());
        $this->assertSame('1.000', $item->fresh()->qty);
        $this->assertSame('1.000', $order->fresh()->grand_total);
    }
}
