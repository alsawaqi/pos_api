<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Device;
use App\Models\QrSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * LAUNCH-P4 — shared fixtures for the products-and-menu suites: catalogue
 * rows (standard products, combos and meals with lines, add-ons), a paired
 * device and its sync push, and a live QR session. Company 100, branch 10.
 */
trait LaunchP4Fixtures
{
    private int $p4NextId = 500;

    private const P4_QR_SECRET = 'launch-p4-qr-secret';

    /** @param array<string, mixed> $overrides */
    protected function p4Product(string $name, string $price = '1.000', array $overrides = []): int
    {
        $id = (int) ($overrides['id'] ?? $this->p4NextId++);
        DB::table('pos_products')->insert($overrides + [
            'id' => $id, 'uuid' => (string) Str::uuid(), 'company_id' => 100, 'category_id' => null,
            'name' => $name, 'base_price' => $price, 'stock_mode' => 'untracked', 'display_order' => $id,
            'status' => 'active', 'show_on_customer_tablet' => true, 'is_internal' => false,
            'created_at' => '2026-09-01 08:00:00', 'updated_at' => '2026-09-01 08:00:00',
        ]);

        return $id;
    }

    /**
     * A combo built from "slots" (the LAUNCH-P4 shape, kept for the older
     * suites). LAUNCH combo add-on: each slot [name, min, max, options]
     * becomes a LINE — one option with min = max >= 1 is a FIXED line (that
     * item × min); anything else a CHOICE line "pick max(min, 1)" over a
     * fresh category holding its option products (a product with no category
     * joins it), each option's extra price kept as the item's extra price.
     * 'slots' returns the line ids in slot order.
     *
     * @param  list<array{0: string, 1: int, 2: int, 3: array<int, string>}>  $slots
     * @return array{id: int, slots: list<int>}
     */
    protected function p4Combo(string $name, string $price, array $slots, array $overrides = []): array
    {
        $companyId = (int) ($overrides['company_id'] ?? 100);
        $comboId = $this->p4Product($name, $price, $overrides + ['product_type' => 'combo']);
        $lineIds = [];
        foreach ($slots as $order => [$slotName, $min, $max, $options]) {
            if (count($options) === 1 && $min === $max && $min >= 1) {
                $lineIds[] = $this->p4FixedLine(['combo_product_id' => $comboId], (int) array_key_first($options), $min, [], $order, $companyId);

                continue;
            }
            $category = $this->p4Category($slotName.' '.$comboId, $companyId);
            foreach (array_keys($options) as $productId) {
                DB::table('pos_products')->where('id', $productId)->whereNull('category_id')->update(['category_id' => $category]);
            }
            $lineIds[] = $this->p4ChoiceLine(['combo_product_id' => $comboId], $category, max($min, 1),
                array_filter($options, static fn (string $extra): bool => (float) $extra > 0), [], $order, $companyId, $slotName);
        }

        return ['id' => $comboId, 'slots' => $lineIds];
    }

    protected function p4Category(string $name, int $companyId = 100): int
    {
        return (int) DB::table('pos_product_categories')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'name' => $name, 'name_ar' => $name.' (ع)',
            'status' => 'active', 'display_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * LAUNCH combo add-on — a fixed line (product × quantity) with its
     * upgrades [product_id => upgrade price].
     *
     * @param  array{combo_product_id?: int, meal_id?: int}  $owner
     * @param  array<int, string>  $upgrades
     */
    protected function p4FixedLine(array $owner, int $productId, int $quantity = 1, array $upgrades = [], int $sort = 0, int $companyId = 100): int
    {
        $lineId = (int) DB::table('pos_combo_lines')->insertGetId($owner + [
            'company_id' => $companyId, 'kind' => 'fixed', 'product_id' => $productId, 'quantity' => $quantity,
            'sort_order' => $sort, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = 0;
        foreach ($upgrades as $upgradeId => $upgradePrice) {
            DB::table('pos_combo_line_upgrades')->insert([
                'company_id' => $companyId, 'line_id' => $lineId, 'product_id' => $upgradeId, 'upgrade_price' => $upgradePrice,
                'sort_order' => $order++, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $lineId;
    }

    /**
     * LAUNCH combo add-on — a choice line "pick $pick from $categoryId" with
     * per-item extra prices [product_id => price] and unticked products.
     *
     * @param  array{combo_product_id?: int, meal_id?: int}  $owner
     * @param  array<int, string>  $extras
     * @param  list<int>  $excluded
     */
    protected function p4ChoiceLine(array $owner, int $categoryId, int $pick = 1, array $extras = [], array $excluded = [], int $sort = 0, int $companyId = 100, string $name = 'Drink'): int
    {
        $lineId = (int) DB::table('pos_combo_lines')->insertGetId($owner + [
            'company_id' => $companyId, 'kind' => 'choice', 'category_id' => $categoryId, 'pick_count' => $pick,
            'name' => $name, 'name_ar' => $name.' (ع)', 'sort_order' => $sort, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($extras as $productId => $extra) {
            DB::table('pos_combo_line_items')->insert(['company_id' => $companyId, 'line_id' => $lineId, 'product_id' => $productId,
                'excluded' => false, 'extra_price' => $extra, 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($excluded as $productId) {
            DB::table('pos_combo_line_items')->insert(['company_id' => $companyId, 'line_id' => $lineId, 'product_id' => $productId,
                'excluded' => true, 'extra_price' => '0.000', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $lineId;
    }

    /**
     * LAUNCH combo add-on — a meal ("Make it a meal? +price") on the mains of
     * $categoryIds minus $excluded.
     *
     * @param  list<int>  $categoryIds
     * @param  list<int>  $excluded
     */
    protected function p4Meal(string $name, string $mealPrice, array $categoryIds, array $excluded = [], array $overrides = []): int
    {
        $companyId = (int) ($overrides['company_id'] ?? 100);
        $mealId = (int) DB::table('pos_meals')->insertGetId($overrides + [
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'name' => $name, 'name_ar' => $name.' (ع)',
            'meal_price' => $mealPrice, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($categoryIds as $categoryId) {
            DB::table('pos_meal_categories')->insert(['company_id' => $companyId, 'meal_id' => $mealId, 'category_id' => $categoryId,
                'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($excluded as $productId) {
            DB::table('pos_meal_excluded_products')->insert(['company_id' => $companyId, 'meal_id' => $mealId, 'product_id' => $productId,
                'created_at' => now(), 'updated_at' => now()]);
        }

        return $mealId;
    }

    /** An add-on group bound to $productId with one option per [name => price delta]. @return array<string, int> option ids by name */
    protected function p4Addons(int $productId, array $options, array $group = []): array
    {
        $groupId = (int) DB::table('pos_addon_groups')->insertGetId($group + [
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Extras '.$productId,
            'selection_mode' => 'multi', 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('pos_addon_group_products')->insert(['add_on_group_id' => $groupId, 'product_id' => $productId, 'created_at' => now(), 'updated_at' => now()]);
        $ids = [];
        foreach ($options as $optionName => $delta) {
            $ids[$optionName] = (int) DB::table('pos_addons')->insertGetId([
                'uuid' => (string) Str::uuid(), 'company_id' => 100, 'add_on_group_id' => $groupId, 'name' => $optionName,
                'price_delta' => $delta, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $ids;
    }

    protected function p4Tax(string $name = 'VAT', string $rate = '5.00', ?string $nameAr = 'ضريبة القيمة المضافة', bool $active = true, int $companyId = 100): int
    {
        return (int) DB::table('pos_taxes')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'name' => $name, 'name_ar' => $nameAr,
            'rate_percent' => $rate, 'is_active' => $active, 'sort_order' => 0,
            'created_at' => '2026-08-15 00:00:00', 'updated_at' => '2026-08-15 00:00:00',
        ]);
    }

    protected function p4Device(string $token, string $type = 'pos_terminal'): Device
    {
        return Device::factory()->paired($token)->create([
            'company_id' => 100, 'branch_id' => 10, 'name' => 'P4 '.$type, 'device_type' => $type,
        ]);
    }

    /** @param list<array<string, mixed>> $events */
    protected function p4Push(string $token, array $events): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/device/sync/push', ['events' => $events])->assertOk();
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    protected function p4Event(string $type, array $order, array $payloadExtra = []): array
    {
        return [
            'client_event_id' => (string) Str::uuid(), 'event_type' => $type,
            'client_timestamp' => now()->toIso8601String(), 'payload' => $payloadExtra + ['order' => $order],
        ];
    }

    /**
     * An order.create body (pricing_engine flagged) for the given lines and
     * money; prices_include_tax only when set.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, mixed>
     */
    protected function p4Order(array $lines, int $tax = 0, ?bool $inclusive = null, array $overrides = []): array
    {
        $subtotal = array_sum(array_column($lines, 'line_total_baisas'));

        return array_replace([
            'pricing_engine' => 1, 'uuid' => (string) Str::uuid(), 'order_type' => 'quick', 'source' => 'main_pos',
            'staff_id' => 7, 'opened_at' => now()->subMinute()->toIso8601String(),
            'subtotal_baisas' => $subtotal, 'discount_total_baisas' => 0, 'comp_total_baisas' => 0,
            'tax_total_baisas' => $tax, 'grand_total_baisas' => $inclusive === true ? $subtotal : $subtotal + $tax,
            'lines' => $lines, 'discounts' => [], 'comps' => [],
        ] + ($inclusive === null ? [] : ['prices_include_tax' => $inclusive]), $overrides);
    }

    protected function p4QrSession(): QrSession
    {
        $device = Device::factory()->paired('mdev_p4_qr_'.Str::random(24))->create([
            'company_id' => 100, 'branch_id' => 10, 'device_type' => 'payment_station', 'status' => 'active',
        ]);

        return QrSession::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'device_id' => $device->id,
            'token' => Str::random(64), 'token_expires_at' => now()->addMinute(), 'status' => QrSession::STATUS_ACTIVE,
            'client_secret_hash' => QrSession::hashClientSecret(self::P4_QR_SECRET), 'bound_at' => now(),
            'last_seen_at' => now(), 'expires_at' => now()->addMinutes(30),
        ]);
    }

    protected function p4QrGet(QrSession $session, string $uri): TestResponse
    {
        return $this->withHeaders(['X-QR-Session' => (string) $session->uuid, 'X-QR-Client-Secret' => self::P4_QR_SECRET])->getJson($uri);
    }

    /** @param array<string, mixed> $payload */
    protected function p4QrPost(QrSession $session, string $uri, array $payload): TestResponse
    {
        return $this->withHeaders(['X-QR-Session' => (string) $session->uuid, 'X-QR-Client-Secret' => self::P4_QR_SECRET])->postJson($uri, $payload);
    }

    /** @return array<string, mixed> */
    protected function p4QrCheckout(array $lines, string $requestId = 'p4-checkout'): array
    {
        return ['client_request_id' => $requestId, 'checkout_choice' => 'machine', 'phone' => '+96890000001', 'lines' => $lines];
    }
}
