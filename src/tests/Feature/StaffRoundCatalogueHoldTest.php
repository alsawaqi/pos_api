<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\LoadQrPricingInputAction;
use App\Models\AddOn;
use App\Models\AddOnGroup;
use App\Models\Device;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\QrOrderRound;
use App\Models\TableSession;
use App\Models\TableSessionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class StaffRoundCatalogueHoldTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-05 12:00:00', 'UTC'));
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    #[DataProvider('productAvailabilityReasons')]
    public function test_case_18_each_unpriceable_product_is_a_durable_processed_hold(string $reason): void
    {
        [$device, $seating, $payload] = $this->roundFixture();
        $product = Product::findOrFail($payload['lines'][0]['product_id']);
        if ($reason === 'inactive') {
            $product->update(['status' => 'inactive']);
        } elseif ($reason === 'outside_availability_window') {
            $product->update(['available_from' => '15:00:00', 'available_until' => '16:00:00']);
        } elseif ($reason === 'product_missing') {
            $payload['lines'][0]['product_id'] = 999999;
        } else {
            $product->update(['stock_mode' => 'unit']);
            DB::table('pos_branch_product')->insert([
                'branch_id' => $device->branch_id, 'product_id' => $product->id,
                'is_available' => $reason !== 'branch_unavailable',
                'stock_qty' => $reason === 'out_of_stock' ? 0 : 10,
            ]);
        }
        $payload['lines'][0]['notes'] = "No ice; customer's original note";
        $payload['lines'][0]['qty'] = 3;
        $ack = $this->push($device, $payload, 'held');
        $round = QrOrderRound::findOrFail($ack['round_id']);
        $bill = Order::findOrFail($seating->fresh()->order_id);
        $held = [['line_index' => 0, 'product_id' => $payload['lines'][0]['product_id'], 'addon_id' => null, 'reason' => $reason]];
        $this->assertSame($held, $ack['held_lines']);
        $this->assertSame(['catalogue'], $ack['review_reasons']);
        $this->assertTrue($ack['needs_review']);
        $this->assertFalse($ack['print_pending']);
        $this->assertSame('pending_confirmation', $round->status);
        $this->assertNull($round->qr_session_id);
        $this->assertNull($round->accepted_seq);
        $this->assertNull($round->confirm_payload);
        $this->assertNull($round->resolved_at);
        $this->assertSame(0, $round->subtotal_baisas);
        $this->assertSame(0, $round->tax_baisas);
        $this->assertSame(0, $round->total_baisas);
        $this->assertSame('0.000', $bill->grand_total);
        $this->assertSame('main_pos', $bill->source);
        $this->assertNull($bill->qr_session_id);
        $this->assertNull($bill->receipt_number);
        $this->assertSame(0, OrderItem::query()->where('order_id', $bill->id)->count());
        $this->assertSame([[
            'line_index' => 0, 'product_id' => $payload['lines'][0]['product_id'],
            'product_name' => $reason === 'product_missing' ? null : $product->name,
            'qty' => 3, 'notes' => $payload['lines'][0]['notes'], 'addon_ids' => [],
            'requested' => true, 'held_reason' => $reason, 'addon_id' => null,
            'unit_price_baisas' => null, 'line_total_baisas' => null,
        ]], $round->priced_lines);
        $this->assertSame(['round_pending', 'needs_review'], TableSessionEvent::query()->orderBy('id')->pluck('event_type')->all());
        $pending = TableSessionEvent::query()->where('event_type', 'round_pending')->sole();
        $this->assertSame((int) $pending->id, $ack['event_id']);
        $this->assertSame(['catalogue'], $pending->payload['review_reasons']);
        $this->assertSame([$payload['lines'][0]['product_id']], $pending->payload['held_product_ids']);
        $this->assertSame(1, $pending->payload['held_line_count']);
        $this->assertArrayNotHasKey('lines', $pending->payload);
        $this->assertArrayNotHasKey('priced_lines', $pending->payload);
        $this->assertStringNotContainsString($payload['lines'][0]['notes'], json_encode($pending->payload, JSON_THROW_ON_ERROR));
        fwrite(STDOUT, "\nT4_MATRIX_CASE_18_".$reason.'='.json_encode($ack, JSON_THROW_ON_ERROR)."\n");
    }

    public static function productAvailabilityReasons(): array
    {
        return array_map(static fn (string $reason): array => [$reason], [
            'inactive', 'branch_unavailable', 'outside_availability_window', 'out_of_stock', 'product_missing',
        ]);
    }

    public function test_case_19_partial_hold_preserves_original_positions_and_prices_only_the_available_subset(): void
    {
        [$device, $seating, $payload] = $this->roundFixture();
        $missing = $this->seatingProduct();
        DB::table('pos_branch_product')->insert([
            'branch_id' => $device->branch_id, 'product_id' => $missing->id, 'is_available' => false,
        ]);
        $available = $payload['lines'][0];
        $payload['lines'] = [
            ['product_id' => (int) $missing->id, 'qty' => 2, 'addon_ids' => [], 'notes' => null],
            $available + ['unit_price' => '999.000'],
            ['product_id' => (int) $missing->id, 'qty' => 1, 'addon_ids' => [], 'notes' => 'last line'],
        ];
        Product::findOrFail($available['product_id'])->update(['base_price' => '2.500']);
        $ack = $this->push($device, $payload, 'held');
        $round = QrOrderRound::findOrFail($ack['round_id']);
        $this->assertSame(2500, $ack['total_baisas']);
        $this->assertSame([0, 2], array_column($ack['held_lines'], 'line_index'));
        $this->assertSame([0, 1, 2], array_column($round->priced_lines, 'line_index'));
        $this->assertSame('branch_unavailable', $round->priced_lines[0]['held_reason']);
        $this->assertSame(2500, $round->priced_lines[1]['unit_price_baisas']);
        $this->assertSame(2500, $round->priced_lines[1]['line_total_baisas']);
        $this->assertSame('last line', $round->priced_lines[2]['notes']);
        $this->assertNotNull($round->confirm_payload);
        $this->assertSame('0.000', $seating->fresh()->order->grand_total);
        $this->assertDatabaseCount('pos_order_items', 0);
        $this->assertNull($round->accepted_seq);
        fwrite(STDOUT, "\nT4_MATRIX_CASE_19=".json_encode($ack, JSON_THROW_ON_ERROR)."\n");
    }

    #[DataProvider('addonDriftCases')]
    public function test_case_21_unavailable_addon_holds_its_whole_line(string $kind, string $reason): void
    {
        [$device, $seating, $payload] = $this->roundFixture();
        $group = AddOnGroup::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Milk choices',
            'is_global' => true, 'status' => 'active', 'selection_mode' => 'multiple',
            'min_selections' => 0, 'max_selections' => 3, 'display_order' => 1,
        ]);
        $addon = AddOn::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'add_on_group_id' => $group->id,
            'name' => 'Oat milk', 'price_delta' => '0.500', 'status' => 'active',
        ]);
        $payload['lines'][0]['addon_ids'] = [(int) $addon->id];
        if ($kind === 'missing') {
            $payload['lines'][0]['addon_ids'] = [999999];
        } elseif ($kind === 'inactive') {
            $addon->update(['status' => 'inactive']);
        } elseif ($kind === 'not_applicable') {
            $group->update(['is_global' => false]);
        } elseif ($kind === 'unavailable') {
            $linked = $this->seatingProduct();
            $linked->update(['status' => 'inactive']);
            $addon->update(['linked_product_id' => $linked->id]);
        } elseif ($kind === 'minimum') {
            $group->update(['min_selections' => 2]);
        } else {
            $group->update(['max_selections' => 0]);
        }
        $ack = $this->push($device, $payload, 'held');
        $round = QrOrderRound::findOrFail($ack['round_id']);
        $expectedAddonId = $reason === 'addon_selection_invalid' ? null : $payload['lines'][0]['addon_ids'][0];
        $this->assertSame([[
            'line_index' => 0, 'product_id' => $payload['lines'][0]['product_id'],
            'addon_id' => $expectedAddonId, 'reason' => $reason,
        ]], $ack['held_lines']);
        $this->assertSame($payload['lines'][0]['addon_ids'], $round->priced_lines[0]['addon_ids']);
        $this->assertSame(0, $ack['total_baisas']);
        $this->assertNull($round->confirm_payload);
        $this->assertSame('0.000', $seating->fresh()->order->grand_total);
        $this->assertDatabaseCount('pos_order_items', 0);
        fwrite(STDOUT, "\nT4_MATRIX_CASE_21_".$kind.'='.json_encode($ack, JSON_THROW_ON_ERROR)."\n");
    }

    public static function addonDriftCases(): array
    {
        return [
            ['missing', 'addon_unavailable'], ['inactive', 'addon_unavailable'],
            ['not_applicable', 'addon_unavailable'], ['unavailable', 'addon_unavailable'],
            ['minimum', 'addon_selection_invalid'], ['maximum', 'addon_selection_invalid'],
        ];
    }

    public function test_case_22_price_drift_uses_current_server_price_without_review(): void
    {
        [$device, $seating, $payload] = $this->roundFixture();
        Product::findOrFail($payload['lines'][0]['product_id'])->update(['base_price' => '3.750']);
        $payload['lines'][0]['qty'] = 2;
        $ack = $this->push($device, $payload, 'appended');
        $this->assertSame(7500, $ack['total_baisas']);
        $this->assertFalse($ack['needs_review']);
        $this->assertSame([], $ack['review_reasons']);
        $this->assertSame([], $ack['held_lines']);
        $this->assertSame('accepted', $ack['round_status']);
        $this->assertNotNull($ack['accepted_seq']);
        $this->assertSame('7.500', $seating->fresh()->order->grand_total);
        fwrite(STDOUT, "\nT4_MATRIX_CASE_22=".json_encode($ack, JSON_THROW_ON_ERROR)."\n");
    }

    public function test_replay_after_reactivation_keeps_stored_hold_and_online_twin_presentation(): void
    {
        [$device, $seating, $payload] = $this->roundFixture();
        $product = Product::findOrFail($payload['lines'][0]['product_id']);
        $product->update(['status' => 'inactive']);
        $first = $this->push($device, $payload, 'held');
        $roundBefore = QrOrderRound::findOrFail($first['round_id'])->getRawOriginal();
        $billBefore = $seating->fresh()->order->getRawOriginal();
        $product->update(['status' => 'active', 'base_price' => '9.500']);
        $replay = $this->push($device, $payload, 'replayed');
        $expected = array_replace($first, ['outcome' => 'replayed', 'event_id' => null]);
        unset($expected['event_ids']);
        $this->assertSame($expected, $replay);
        $online = $this->withToken($device->device_token)
            ->postJson('/api/v1/device/tables/'.$seating->uuid.'/round', $payload)->assertOk()->json('data');
        $this->assertSame($expected, $online);
        $this->assertSame($roundBefore, QrOrderRound::findOrFail($first['round_id'])->getRawOriginal());
        $this->assertSame($billBefore, $seating->fresh()->order->getRawOriginal());
        $this->assertDatabaseCount('pos_qr_order_rounds', 1);
        $this->assertDatabaseCount('pos_table_session_events', 2);
    }

    public function test_unknown_key_held_round_ack_points_to_round_pending_not_its_preceding_open_event(): void
    {
        [$device, $seating, $payload] = $this->roundFixture();
        $payload['seating_key'] = (string) Str::uuid();
        $payload['table_id'] = (int) $this->seatingTable('New party')->id;
        Product::findOrFail($payload['lines'][0]['product_id'])->update(['status' => 'inactive']);
        $ack = $this->push($device, $payload, 'held');
        $this->assertSame(['opened', 'round_pending', 'needs_review'], TableSessionEvent::query()->orderBy('id')->pluck('event_type')->all());
        $events = TableSessionEvent::query()->orderBy('id')->get();
        $this->assertSame($events->pluck('id')->all(), $ack['event_ids']);
        $this->assertSame((int) $events[1]->id, $ack['event_id']);
        $this->assertNotSame((int) $events[0]->id, $ack['event_id']);
        $this->assertSame((int) $ack['round_id'], $events[1]->payload['round_id']);
        $this->assertNull($seating->fresh()->order_id);
        $this->assertSame(2, TableSession::query()->where('status', 'open')->count());
        $this->assertDatabaseCount('pos_orders', 1);
    }

    public function test_online_twin_creates_the_same_all_held_storage_without_a_failed_response(): void
    {
        [$device, $seating, $payload] = $this->roundFixture();
        Product::findOrFail($payload['lines'][0]['product_id'])->update(['status' => 'inactive']);
        $payload['queued_offline'] = false;
        $response = $this->withToken($device->device_token)
            ->postJson('/api/v1/device/tables/'.$seating->uuid.'/round', $payload)->assertOk()
            ->assertJsonPath('data.outcome', 'held')
            ->assertJsonPath('data.review_reasons', ['catalogue'])
            ->assertJsonPath('data.held_lines.0.reason', 'inactive')
            ->assertJsonPath('data.total_baisas', 0)
            ->assertJsonPath('data.print_pending', false);
        $round = QrOrderRound::findOrFail($response->json('data.round_id'));
        $this->assertNull($round->confirm_payload);
        $this->assertNull($round->qr_session_id);
        $this->assertSame((int) $seating->id, (int) $round->table_session_id);
        $this->assertSame('0.000', $seating->fresh()->order->grand_total);
        $this->assertSame((int) $round->id, TableSessionEvent::findOrFail($response->json('data.event_id'))->payload['round_id']);
    }

    #[DataProvider('forbiddenClientPrices')]
    public function test_client_price_keys_are_malformed_and_failed_even_when_null(string $key): void
    {
        [$device, $seating, $payload] = $this->roundFixture();
        data_set($payload, $key, null);
        $response = $this->withToken($device->device_token)->postJson('/api/v1/device/sync/push', [
            'events' => [[
                'client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.round',
                'client_timestamp' => now()->toIso8601String(), 'payload' => $payload,
            ]],
        ])->assertOk()->assertJsonPath('data.results.0.status', 'failed');
        $this->assertNotEmpty($response->json('data.results.0.result.error'));
        $this->assertNull($seating->fresh()->order_id);
        $this->assertDatabaseCount('pos_orders', 0);
        $this->assertDatabaseCount('pos_qr_order_rounds', 0);
        $this->assertDatabaseCount('pos_table_session_events', 0);
    }

    public static function forbiddenClientPrices(): array
    {
        return array_map(static fn (string $key): array => [$key], [
            'lines.0.unit_price_baisas', 'lines.0.line_total_baisas',
            'subtotal_baisas', 'total_baisas', 'grand_total_baisas',
        ]);
    }

    public function test_classifier_does_not_throw_for_structural_catalogue_exceptions(): void
    {
        $this->seatingBranch();
        $product = $this->seatingProduct();
        $classified = app(LoadQrPricingInputAction::class)->classify(100, 10, [
            ['product_id' => (int) $product->id, 'qty' => 1, 'addon_ids' => [2, 2], 'notes' => null],
            ['product_id' => (int) $product->id, 'qty' => '1', 'addon_ids' => [], 'notes' => null],
        ]);
        $this->assertSame([], $classified['priceable']);
        $this->assertSame(['addon_selection_invalid', 'invalid_catalogue_line'], array_column($classified['held'], 'reason'));
    }

    private function roundFixture(): array
    {
        $device = $this->seatingDevice();
        $seating = $this->seatingRow($this->seatingTable(), ['opened_by_device_id' => $device->id]);
        $product = $this->seatingProduct();

        return [$device, $seating, [
            'seating_key' => $seating->client_request_id, 'table_id' => (int) $seating->table_id,
            'queued_offline' => true, 'client_request_id' => (string) Str::uuid(),
            'submitted_at' => now()->subMinutes(10)->toIso8601String(),
            'lines' => [['product_id' => (int) $product->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
        ]];
    }

    private function push(Device $device, array $payload, string $outcome): array
    {
        $response = $this->withToken($device->device_token)->postJson('/api/v1/device/sync/push', [
            'events' => [[
                'client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.round',
                'client_timestamp' => $payload['submitted_at'], 'payload' => $payload,
            ]],
        ])->assertOk()->assertJsonPath('data.results.0.status', 'processed')
            ->assertJsonPath('data.results.0.result.outcome', $outcome);
        $ack = $response->json('data.results.0.result');
        if ($outcome === 'replayed') {
            $this->assertNull($ack['event_id']);
        } else {
            $this->assertIsInt($ack['event_id']);
            $this->assertGreaterThan(0, $ack['event_id']);
        }

        return $ack;
    }
}
