<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\BuildQrBranchMenuAction;
use App\Actions\Qr\LoadQrPricingInputAction;
use App\Actions\Qr\QrCatalogueException;
use App\Support\Pricing\Totals;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class QrCataloguePricingTest extends TestCase
{
    use RefreshDatabase;

    private int $nextProductId = 1;

    public function test_menu_keeps_unavailable_products_visible_but_hides_non_customer_products(): void
    {
        $this->category(10);
        $normal = $this->product(['category_id' => 10, 'name' => 'Latte', 'base_price' => '2.500']);
        $branchUnavailable = $this->product(['category_id' => 10, 'name' => 'Paused here']);
        $outOfWindow = $this->product([
            'category_id' => 10,
            'name' => 'Breakfast',
            'available_from' => '06:00:00',
            'available_until' => '10:00:00',
        ]);
        $zeroStock = $this->product(['category_id' => 10, 'name' => 'Bottle', 'stock_mode' => 'unit']);
        $overnight = $this->product([
            'category_id' => 10,
            'name' => 'Night menu',
            'available_from' => '22:00:00',
            'available_until' => '06:00:00',
        ]);
        $internal = $this->product(['category_id' => 10, 'is_internal' => true]);
        $tabletHidden = $this->product(['category_id' => 10, 'show_on_customer_tablet' => false]);
        $softDeleted = $this->product(['category_id' => 10, 'deleted_at' => now()]);
        $otherBranch = $this->product(['category_id' => 10]);
        $inactive = $this->product(['category_id' => 10, 'status' => 'inactive']);

        $this->branchProduct($branchUnavailable, false, null);
        $this->branchProduct($outOfWindow, true, null);
        $this->branchProduct($zeroStock, true, '0.000');
        $this->branchProduct($overnight, true, null);
        $this->branchProduct($otherBranch, true, null, 20);

        $this->addonGroup(50, ['name' => 'Milk', 'max_selections' => 1]);
        DB::table('pos_addon_group_products')->insert([
            'add_on_group_id' => 50,
            'product_id' => $normal,
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->addon(60, 50, ['name' => 'Oat', 'price_delta' => '0.250']);

        $menu = app(BuildQrBranchMenuAction::class)->handle(
            100,
            10,
            new DateTimeImmutable('2026-08-26 12:00:00'),
        );
        $products = collect($menu['products'])->keyBy('id');

        $this->assertTrue($products->has($normal));
        $this->assertFalse($products[$branchUnavailable]['available']);
        $this->assertSame('branch_unavailable', $products[$branchUnavailable]['unavailable_reason']);
        $this->assertFalse($products[$outOfWindow]['available']);
        $this->assertSame('outside_availability_window', $products[$outOfWindow]['unavailable_reason']);
        // LAUNCH-P2 P2-7 — sell, but warn: a zero shelf count no longer hides it.
        $this->assertTrue($products[$zeroStock]['available']);
        $this->assertNull($products[$zeroStock]['unavailable_reason']);
        $this->assertFalse($products[$inactive]['available']);
        $this->assertSame('inactive', $products[$inactive]['unavailable_reason']);
        $this->assertFalse($products->has($internal));
        $this->assertFalse($products->has($tabletHidden));
        $this->assertFalse($products->has($softDeleted));
        $this->assertFalse($products->has($otherBranch));
        $this->assertSame(2500, $products[$normal]['base_price_baisas']);
        $this->assertSame('2.500', $products[$normal]['base_price_display']);
        $this->assertSame([50], $products[$normal]['addon_group_ids']);
        $this->assertSame('0.250', $menu['addon_groups'][0]['addons'][0]['price_delta_display']);
        $this->assertCount(1, $menu['categories']);

        $nightMenu = app(BuildQrBranchMenuAction::class)->handle(
            100,
            10,
            new DateTimeImmutable('2026-08-26 23:00:00'),
        );
        $nightProduct = collect($nightMenu['products'])->firstWhere('id', $overnight);
        $this->assertTrue($nightProduct['available']);
        $this->assertNull($nightProduct['unavailable_reason']);
    }

    public function test_loader_refuses_unavailable_foreign_branch_and_unbound_addons(): void
    {
        $unavailable = $this->product();
        $otherBranch = $this->product();
        $foreign = $this->product(['company_id' => 200]);
        $visible = $this->product();
        $this->branchProduct($unavailable, false, null);
        $this->branchProduct($otherBranch, true, null, 20);

        foreach ([$unavailable, $otherBranch, $foreign] as $productId) {
            $this->assertCatalogueCode(
                QrCatalogueException::PRODUCT_UNAVAILABLE,
                fn () => $this->loader()->handle(100, 10, [$this->line($productId)]),
            );
        }

        $this->addonGroup(70);
        $this->addon(71, 70);
        $this->assertCatalogueCode(
            QrCatalogueException::ADDON_UNAVAILABLE,
            fn () => $this->loader()->handle(100, 10, [$this->line($visible, [71])]),
        );

        DB::table('pos_addon_group_products')->insert([
            'add_on_group_id' => 70,
            'product_id' => $visible,
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('pos_addons')->where('id', 71)->update(['status' => 'inactive']);
        $this->assertCatalogueCode(
            QrCatalogueException::ADDON_UNAVAILABLE,
            fn () => $this->loader()->handle(100, 10, [$this->line($visible, [71])]),
        );
    }

    public function test_loader_builds_authoritative_prices_rules_auto_offers_and_taxes(): void
    {
        $this->category(10);
        $product = $this->product(['category_id' => 10, 'base_price' => '2.000']);
        $this->addonGroup(50, ['max_selections' => 2]);
        $this->addon(60, 50, ['price_delta' => '0.250']);
        DB::table('pos_addon_group_products')->insert([
            'add_on_group_id' => 50,
            'product_id' => $product,
            'display_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->discount(1, [
            'name' => 'Product 100 baisas',
            'scope' => 'product',
            'amount_type' => 'fixed',
            'amount' => '0.100',
            'auto_apply' => true,
        ]);
        DB::table('pos_discount_targets')->insert([
            'discount_id' => 1,
            'target_type' => 'product',
            'target_id' => $product,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->discount(2, [
            'name' => 'Automatic ten percent',
            'scope' => 'order',
            'amount_type' => 'percent',
            'amount' => '10.000',
            'auto_apply' => true,
        ]);
        $this->discount(3, ['status' => 'inactive', 'scope' => 'order', 'auto_apply' => true]);
        $this->discount(4, [
            'name' => 'Manager-only product discount',
            'scope' => 'product',
            'amount_type' => 'fixed',
            'amount' => '4.000',
            'auto_apply' => true,
            'requires_manager_approval' => true,
        ]);
        DB::table('pos_discount_targets')->insert([
            'discount_id' => 4,
            'target_type' => 'product',
            'target_id' => $product,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->offer(1, true, 'active');
        $this->offer(2, false, 'active');
        $this->offer(3, true, 'inactive');
        // LAUNCH-P4 — taxes apply to a VAT-registered merchant (priced on top here).
        $this->registerVat();
        DB::table('pos_taxes')->insert([
            [
                'id' => 1,
                'uuid' => (string) Str::uuid(),
                'company_id' => 100,
                'name' => 'VAT',
                'rate_percent' => '5.00',
                'is_active' => true,
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 2,
                'uuid' => (string) Str::uuid(),
                'company_id' => 100,
                'name' => 'Disabled',
                'rate_percent' => '99.00',
                'is_active' => false,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $loaded = $this->loader()->handle(
            100,
            10,
            [$this->line($product, [60], 2)],
            new DateTimeImmutable('2026-08-26 12:00:00'),
        );

        $this->assertSame(2250, $loaded->pricingInput->lines[0]->unitPriceBaisas);
        $this->assertSame(2250, $loaded->resolvedLines[0]->unitPriceBaisas);
        $this->assertSame(2000, $loaded->resolvedLines[0]->basePriceBaisas);
        $this->assertSame([60], $loaded->resolvedLines[0]->addonIds());
        $this->assertSame('No ice', $loaded->resolvedLines[0]->notes);
        $this->assertCount(2, $loaded->pricingInput->discountRules);
        $this->assertSame([1, 2], array_column($loaded->pricingInput->discountRules, 'id'));
        $this->assertSame('product', $loaded->pricingInput->discountRules[0]->targets[0]->targetType);
        $this->assertSame(2, $loaded->autoOrderDiscount?->id);
        $this->assertCount(1, $loaded->pricingInput->offers);
        $this->assertSame(1, $loaded->pricingInput->offers[0]->id);
        $this->assertCount(1, $loaded->pricingInput->taxes);

        $priced = Totals::priceOrder($loaded->pricingInput);
        $this->assertSame(4500, $priced->rawSubtotalBaisas);
        $this->assertSame(550, $priced->discountTotalBaisas);
        $this->assertSame(198, $priced->taxTotalBaisas);
        $this->assertSame(4148, $priced->grandTotalBaisas);
    }

    private function loader(): LoadQrPricingInputAction
    {
        return app(LoadQrPricingInputAction::class);
    }

    /** @return array{product_id: int, qty: int, addon_ids: list<int>, notes: string} */
    private function line(int $productId, array $addonIds = [], int $qty = 1): array
    {
        return ['product_id' => $productId, 'qty' => $qty, 'addon_ids' => $addonIds, 'notes' => 'No ice'];
    }

    private function assertCatalogueCode(string $code, callable $callback): void
    {
        try {
            $callback();
            $this->fail('Expected a QR catalogue exception.');
        } catch (QrCatalogueException $exception) {
            $this->assertSame($code, $exception->codeName);
        }
    }

    private function category(int $id): void
    {
        DB::table('pos_product_categories')->insert([
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Coffee',
            'display_order' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function product(array $overrides = []): int
    {
        $id = (int) ($overrides['id'] ?? $this->nextProductId++);
        DB::table('pos_products')->insert($overrides + [
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'category_id' => null,
            'name' => 'Product '.$id,
            'base_price' => '1.000',
            'stock_mode' => 'untracked',
            'display_order' => $id,
            'status' => 'active',
            'show_on_customer_tablet' => true,
            'is_internal' => false,
            'available_from' => null,
            'available_until' => null,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);

        return $id;
    }

    private function branchProduct(int $productId, bool $available, ?string $stock, int $branchId = 10): void
    {
        DB::table('pos_branch_product')->insert([
            'branch_id' => $branchId,
            'product_id' => $productId,
            'is_available' => $available,
            'stock_qty' => $stock,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addonGroup(int $id, array $overrides = []): void
    {
        DB::table('pos_addon_groups')->insert($overrides + [
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Options '.$id,
            'selection_mode' => 'multiple',
            'min_selections' => null,
            'max_selections' => null,
            'is_global' => false,
            'display_order' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function addon(int $id, int $groupId, array $overrides = []): void
    {
        DB::table('pos_addons')->insert($overrides + [
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'add_on_group_id' => $groupId,
            'name' => 'Option '.$id,
            'price_delta' => '0.000',
            'display_order' => 0,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function discount(int $id, array $overrides = []): void
    {
        DB::table('pos_discounts')->insert($overrides + [
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Discount '.$id,
            'scope' => 'product',
            'amount_type' => 'fixed',
            'amount' => '0.100',
            'validity_start' => null,
            'validity_end' => null,
            'dayofweek_mask' => null,
            'time_start' => null,
            'time_end' => null,
            'branch_scope_json' => null,
            'stackable' => false,
            'requires_manager_approval' => false,
            'auto_apply' => false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function offer(int $id, bool $autoApply, string $status): void
    {
        DB::table('pos_offers')->insert([
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'name' => 'Offer '.$id,
            'type' => 'spend_get',
            'config' => json_encode([
                'min_subtotal_baisas' => 999999,
                'reward_type' => 'fixed_off',
                'reward_value' => 100,
            ], JSON_THROW_ON_ERROR),
            'auto_apply' => $autoApply,
            'validity_start' => null,
            'validity_end' => null,
            'dayofweek_mask' => null,
            'time_start' => null,
            'time_end' => null,
            'branch_scope_json' => null,
            'max_per_order' => null,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
