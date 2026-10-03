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
 * rows (standard products, combos with slots and options, add-ons), a paired
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
     * A combo with its slots. Each slot: [name, min, max, options] with
     * options as [product_id => extra price (OMR string)]; the first option
     * of a slot is its default.
     *
     * @param  list<array{0: string, 1: int, 2: int, 3: array<int, string>}>  $slots
     * @return array{id: int, slots: list<int>}
     */
    protected function p4Combo(string $name, string $price, array $slots, array $overrides = []): array
    {
        $comboId = $this->p4Product($name, $price, $overrides + ['product_type' => 'combo']);
        $slotIds = [];
        foreach ($slots as $order => [$slotName, $min, $max, $options]) {
            $slotId = (int) DB::table('pos_combo_slots')->insertGetId([
                'uuid' => (string) Str::uuid(), 'company_id' => (int) ($overrides['company_id'] ?? 100), 'combo_product_id' => $comboId,
                'name' => $slotName, 'name_ar' => $slotName.' (ع)', 'min_choices' => $min, 'max_choices' => $max,
                'sort_order' => $order, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $optionOrder = 0;
            foreach ($options as $productId => $extra) {
                DB::table('pos_combo_slot_options')->insert([
                    'company_id' => (int) ($overrides['company_id'] ?? 100), 'slot_id' => $slotId, 'product_id' => $productId,
                    'extra_price' => $extra, 'is_default' => $optionOrder === 0, 'sort_order' => $optionOrder++,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $slotIds[] = $slotId;
        }

        return ['id' => $comboId, 'slots' => $slotIds];
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
