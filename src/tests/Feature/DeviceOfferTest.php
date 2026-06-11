<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\OrderDiscount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * P-F9 — merchant offers / promotions, server half.
 *
 * Two surfaces:
 *
 *   1. CONFIG: /device/config emits a top-level `offers` slice in THE
 *      canonical device shape (config verbatim, money inside it integer
 *      baisas), delta-tracked by updated_at like discounts, with
 *      soft-deleted ids in `deleted.offers`.
 *
 *   2. ORDER ATTRIBUTION: order.create discounts[] entries may carry
 *      `offer_id`; the handler tenant-validates it HARD (foreign /
 *      unknown → the event fails) and persists it on the
 *      pos_order_discounts row with name_snapshot = the offer's name.
 */
class DeviceOfferTest extends TestCase
{
    use RefreshDatabase;

    private string $old;

    protected function setUp(): void
    {
        parent::setUp();
        $this->old = now()->subDay()->toDateTimeString();
    }

    private function pairedDevice(): Device
    {
        return Device::factory()->paired('mdev_offer')->create(['company_id' => 100, 'branch_id' => 10]);
    }

    /**
     * Three offers: a bogo for company 100 (full shape exercised), a
     * bundle for company 100, and a company-200 offer that must never
     * leak into the device's slice.
     */
    private function seedOffers(): void
    {
        $t = ['created_at' => $this->old, 'updated_at' => $this->old];

        DB::table('pos_offers')->insert([
            [
                'id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
                'name' => 'Coffee BOGO', 'name_ar' => 'اشترِ واحصل',
                'type' => 'bogo',
                'config' => json_encode([
                    'buy' => ['product_ids' => [1], 'category_ids' => [], 'qty' => 1],
                    'get' => ['same_as_buy' => true, 'product_ids' => [], 'category_ids' => [], 'qty' => 1, 'percent_off' => 100],
                ]),
                'auto_apply' => true,
                'validity_start' => null, 'validity_end' => null,
                'dayofweek_mask' => 65, // Sun|Sat
                'time_start' => '14:00:00', 'time_end' => '17:00:00',
                'branch_scope_json' => json_encode([10]),
                'max_per_order' => 2,
                'status' => 'active',
            ] + $t,
            [
                'id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
                'name' => 'Meal Deal', 'name_ar' => null,
                'type' => 'bundle',
                'config' => json_encode([
                    'price_baisas' => 2500,
                    'groups' => [
                        ['label' => 'Main', 'label_ar' => null, 'product_ids' => [1, 2], 'qty' => 1],
                        ['label' => 'Drink', 'label_ar' => 'مشروب', 'product_ids' => [2], 'qty' => 1],
                    ],
                ]),
                'auto_apply' => false, // bundle: always cashier-picked
                'validity_start' => null, 'validity_end' => null,
                'dayofweek_mask' => null,
                'time_start' => null, 'time_end' => null,
                'branch_scope_json' => null,
                'max_per_order' => null,
                'status' => 'paused',
            ] + $t,
            [
                'id' => 99, 'uuid' => (string) Str::uuid(), 'company_id' => 200,
                'name' => 'OtherCoOffer', 'name_ar' => null,
                'type' => 'spend_get',
                'config' => json_encode(['min_subtotal_baisas' => 5000, 'reward_type' => 'percent_off', 'reward_value' => 10, 'reward_product_id' => null]),
                'auto_apply' => true,
                'validity_start' => null, 'validity_end' => null,
                'dayofweek_mask' => null, 'time_start' => null, 'time_end' => null,
                'branch_scope_json' => null, 'max_per_order' => null,
                'status' => 'active',
            ] + $t,
        ]);
    }

    // =================== CONFIG SLICE ===================

    public function test_full_config_emits_offers_in_the_canonical_shape_tenant_scoped(): void
    {
        $this->seedOffers();
        $this->pairedDevice();

        $res = $this->withToken('mdev_offer')->getJson('/api/v1/device/config')->assertOk();
        $data = $res->json('data');

        // Only company 100's two offers — never company 200's.
        $this->assertCount(2, $data['offers']);
        $this->assertSame([1, 2], collect($data['offers'])->pluck('id')->all());

        // The bogo row carries EXACTLY the canonical shape, config verbatim.
        $bogo = collect($data['offers'])->firstWhere('id', 1);
        $this->assertSame([
            'id' => 1,
            'name' => 'Coffee BOGO',
            'name_ar' => 'اشترِ واحصل',
            'type' => 'bogo',
            'status' => 'active',
            'auto_apply' => true,
            'validity_start' => null,
            'validity_end' => null,
            'dayofweek_mask' => 65,
            'time_start' => '14:00:00',
            'time_end' => '17:00:00',
            'branch_scope_json' => [10],
            'max_per_order' => 2,
            'config' => [
                'buy' => ['product_ids' => [1], 'category_ids' => [], 'qty' => 1],
                'get' => ['same_as_buy' => true, 'product_ids' => [], 'category_ids' => [], 'qty' => 1, 'percent_off' => 100],
            ],
        ], $bogo);

        // The paused bundle still rides along (the device gates on status)
        // with its cashier-picked flag and baisas bundle price intact.
        $bundle = collect($data['offers'])->firstWhere('id', 2);
        $this->assertSame('bundle', $bundle['type']);
        $this->assertSame('paused', $bundle['status']);
        $this->assertFalse($bundle['auto_apply']);
        $this->assertNull($bundle['branch_scope_json']);
        $this->assertNull($bundle['max_per_order']);
        $this->assertSame(2500, $bundle['config']['price_baisas']);
        $this->assertCount(2, $bundle['config']['groups']);

        // Full mode: nothing to purge.
        $this->assertSame([], $res->json('data.deleted.offers'));
    }

    public function test_delta_returns_changed_offers_and_soft_deleted_offer_ids(): void
    {
        $this->seedOffers();
        $this->pairedDevice();

        $since = now()->subHour()->toIso8601String();
        $now = now()->toDateTimeString();

        // Offer 1 edited after `since`; offer 2 soft-deleted after `since`.
        DB::table('pos_offers')->where('id', 1)->update(['name' => 'Coffee BOGO v2', 'updated_at' => $now]);
        DB::table('pos_offers')->where('id', 2)->update(['deleted_at' => $now, 'updated_at' => $now]);

        $res = $this->withToken('mdev_offer')
            ->getJson('/api/v1/device/config/delta?since='.urlencode($since))
            ->assertOk();
        $data = $res->json('data');

        // Only the edited offer is "changed"; the soft-deleted one is not.
        $this->assertCount(1, $data['offers']);
        $this->assertSame(1, $data['offers'][0]['id']);
        $this->assertSame('Coffee BOGO v2', $data['offers'][0]['name']);

        // The soft-deleted offer surfaces in the purge list (only it; the
        // other company's untouched rows never appear).
        $this->assertSame([2], $data['deleted']['offers']);
    }

    public function test_delta_excludes_offers_untouched_since(): void
    {
        $this->seedOffers();
        $this->pairedDevice();

        // Everything was seeded a day ago; a since of one hour ago sees
        // no changes and no deletions.
        $res = $this->withToken('mdev_offer')
            ->getJson('/api/v1/device/config/delta?since='.urlencode(now()->subHour()->toIso8601String()))
            ->assertOk();

        $this->assertSame([], $res->json('data.offers'));
        $this->assertSame([], $res->json('data.deleted.offers'));
    }

    // =================== ORDER ATTRIBUTION ===================

    /**
     * @param  list<array<string, mixed>>  $discounts
     * @return array<string, mixed>
     */
    private function createEvent(string $uuid, array $discounts): array
    {
        return [
            'client_event_id' => (string) Str::uuid(),
            'event_type' => 'order.create',
            'client_timestamp' => now()->toIso8601String(),
            'payload' => ['order' => [
                'uuid' => $uuid,
                'order_type' => 'quick',
                'source' => 'main_pos',
                'opened_at' => now()->toIso8601String(),
                'subtotal_baisas' => 3000,
                'discount_total_baisas' => 800,
                'tax_total_baisas' => 0,
                'grand_total_baisas' => 2200,
                'lines' => [['product_id' => 1, 'qty' => 1, 'unit_price_baisas' => 3000, 'line_discount_baisas' => 0, 'line_total_baisas' => 3000]],
                'discounts' => $discounts,
            ]],
        ];
    }

    private function push(array $event): TestResponse
    {
        return $this->withToken('mdev_offer')->postJson('/api/v1/device/sync/push', ['events' => [$event]]);
    }

    private function seedOrderWorld(): void
    {
        DB::table('pos_products')->insert([
            'id' => 1, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Item', 'base_price' => 3.000, 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_order_create_persists_offer_id_with_the_offer_name_snapshot(): void
    {
        $this->seedOffers();
        $this->seedOrderWorld();
        $this->pairedDevice();
        $uuid = (string) Str::uuid();

        $res = $this->push($this->createEvent($uuid, [
            // The offer's catalogue name wins over the device-sent label.
            ['offer_id' => 1, 'name' => 'sent label', 'amount_baisas' => 800, 'line_index' => 0],
        ]))->assertOk();

        $this->assertSame('processed', $res->json('data.results.0.status'));
        $this->assertSame(1, $res->json('data.results.0.result.discounts'));

        $row = OrderDiscount::firstOrFail();
        $this->assertSame(1, (int) $row->offer_id);
        $this->assertNull($row->discount_id);
        $this->assertSame('Coffee BOGO', $row->name_snapshot);
        $this->assertSame('0.800', $row->amount);
    }

    public function test_cross_tenant_offer_id_fails_the_event(): void
    {
        $this->seedOffers();
        $this->seedOrderWorld();
        $this->pairedDevice();

        // Offer 99 belongs to company 200 — the event must FAIL, leaving
        // no order and no discount rows.
        $res = $this->push($this->createEvent((string) Str::uuid(), [
            ['offer_id' => 99, 'name' => 'sneaky', 'amount_baisas' => 800],
        ]))->assertOk();

        $r = $res->json('data.results.0');
        $this->assertSame('failed', $r['status']);
        $this->assertStringContainsString('offer outside the device tenant', $r['result']['error']);
        $this->assertSame(0, OrderDiscount::count());
        $this->assertDatabaseCount('pos_orders', 0);
    }

    public function test_unknown_offer_id_fails_the_event(): void
    {
        $this->seedOffers();
        $this->seedOrderWorld();
        $this->pairedDevice();

        $res = $this->push($this->createEvent((string) Str::uuid(), [
            ['offer_id' => 12345, 'name' => 'ghost', 'amount_baisas' => 800],
        ]))->assertOk();

        $this->assertSame('failed', $res->json('data.results.0.status'));
        $this->assertSame(0, OrderDiscount::count());
    }

    public function test_a_soft_deleted_offer_still_resolves_for_offline_queued_orders(): void
    {
        $this->seedOffers();
        $this->seedOrderWorld();
        $this->pairedDevice();
        DB::table('pos_offers')->where('id', 1)->update(['deleted_at' => now()->toDateTimeString()]);

        $res = $this->push($this->createEvent((string) Str::uuid(), [
            ['offer_id' => 1, 'name' => 'x', 'amount_baisas' => 800],
        ]))->assertOk();

        $this->assertSame('processed', $res->json('data.results.0.status'));
        $row = OrderDiscount::firstOrFail();
        $this->assertSame(1, (int) $row->offer_id);
        $this->assertSame('Coffee BOGO', $row->name_snapshot);
    }
}
