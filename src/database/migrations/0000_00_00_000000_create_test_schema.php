<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 8 — TEST-ONLY schema for pos_api.
 *
 * pos_api connects to the SHARED charity Postgres in dev/prod and
 * never migrates it (pos_admin owns the pos_* schema). So this app
 * has no real migrations. For the sqlite :memory: test DB, though,
 * we need the slice of pos_* tables the device API touches.
 *
 * The `testing`-env guard ensures this NEVER runs against the real
 * shared DB — a stray `php artisan migrate` in dev is a no-op.
 *
 * FK columns are plain integers here (no ->constrained) so tests
 * don't need to seed parent rows; the real schema enforces them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        Schema::create('pos_devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('serial_number')->unique();
            $table->string('name')->nullable();
            $table->string('device_type')->default('pos_terminal');
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('status')->default('registered');
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->string('app_version')->nullable();
            $table->string('kiosk_id')->nullable()->unique();
            $table->string('device_token', 80)->nullable()->unique();
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->smallInteger('last_battery')->nullable();
            $table->json('metadata')->nullable();
            $table->string('terminal_id', 64)->nullable();
            $table->unsignedBigInteger('commission_profile_id')->nullable();
            $table->unsignedBigInteger('bank_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_device_activation_tokens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('device_id');
            $table->string('token_hash', 64)->unique();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_sync_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('client_event_id');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->unique(['device_id', 'client_event_id']);
            $table->string('event_type', 64);
            // sqlite mirror — production is jsonb on Postgres.
            $table->text('payload_json');
            $table->timestamp('client_timestamp');
            $table->timestamp('server_received_at')->useCurrent();
            $table->timestamp('processed_at')->nullable();
            $table->string('ack_status', 32)->default('received');
            $table->text('result_json')->nullable();
        });

        // ---- Phase 8.1 catalogue / inventory slice (device config bundle) ----

        Schema::create('pos_branches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('code')->nullable();
            $table->string('manager_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedSmallInteger('geofence_radius_m')->default(500);
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('region_id')->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->json('opening_hours_json')->nullable();
            $table->string('default_order_type', 16)->default('dine_in');
            $table->json('settings')->nullable();
            $table->json('receipt_template')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_floors', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_tables', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('floor_id');
            $table->string('label', 32);
            $table->unsignedSmallInteger('seats')->default(4);
            $table->unsignedSmallInteger('min_party')->nullable();
            $table->unsignedSmallInteger('max_party')->nullable();
            $table->string('shape', 24)->default('square');
            $table->text('notes')->nullable();
            $table->string('qr_token', 64)->nullable();
            $table->string('status', 32)->default('active');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->unsignedSmallInteger('position_x')->nullable();
            $table->unsignedSmallInteger('position_y')->nullable();
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_product_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->text('description')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_products', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('category_id')->nullable();
            $table->string('sku', 64)->nullable();
            $table->string('barcode', 64)->nullable();
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->text('description')->nullable();
            $table->string('image_url', 500)->nullable();
            $table->decimal('base_price', 12, 3);
            $table->decimal('delivery_price', 12, 3)->nullable();
            $table->string('stock_mode', 16)->default('untracked');
            $table->decimal('cost_price', 12, 3)->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_addon_groups', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('selection_mode', 16)->default('single');
            // Phase B — selection constraints (NULL = unbounded).
            $table->unsignedSmallInteger('min_selections')->nullable();
            $table->unsignedSmallInteger('max_selections')->nullable();
            $table->boolean('is_global')->default(false);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_addons', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('add_on_group_id');
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->decimal('price_delta', 12, 3)->default(0);
            // Phase B — pre-selected option in the POS customize sheet.
            $table->boolean('is_default')->default(false);
            $table->unsignedBigInteger('ingredient_id')->nullable();
            $table->decimal('ingredient_qty', 10, 3)->nullable();
            $table->string('ingredient_unit', 16)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_addon_group_products', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('add_on_group_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
        });

        // Phase B — category-level group binding.
        Schema::create('pos_addon_group_categories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('add_on_group_id');
            $table->unsignedBigInteger('category_id');
            $table->unique(['add_on_group_id', 'category_id'], 'pos_addon_group_categories_unique');
        });

        Schema::create('pos_delivery_providers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('name', 64);
            $table->string('color', 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_product_delivery_prices', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('delivery_provider_id');
            $table->unsignedBigInteger('company_id');
            $table->decimal('price', 12, 3);
            $table->timestamps();
        });

        Schema::create('pos_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('unit', 16)->default('piece');
            // Phase A (Additions §2.3) — the piece model.
            $table->string('piece_unit_label', 32)->nullable();
            $table->string('piece_unit_label_ar', 32)->nullable();
            $table->decimal('units_per_piece', 14, 4)->nullable();
            $table->boolean('allow_fractional_pieces')->default(true);
            $table->decimal('default_unit_cost', 12, 3)->default(0);
            $table->decimal('min_stock_threshold', 12, 3)->nullable();
            $table->unsignedBigInteger('primary_supplier_id')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_product_recipes', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('quantity', 12, 3);
            $table->string('unit_at_set', 16);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pos_branch_product', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->boolean('is_available')->default(true);
            $table->decimal('stock_qty', 12, 3)->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'product_id']);
        });

        Schema::create('pos_branch_stock', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('quantity', 12, 3)->default(0);
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();
        });

        // Phase D1 — central product-unit pool + its signed append-only
        // ledger (schema owned by pos_admin 2026_06_25_0101/0200). pos_api
        // only appends sale_consumption rows (branch side); the central
        // balance sums branch_id-NULL rows only and must stay untouched.
        Schema::create('pos_product_stock', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('quantity', 12, 3)->default(0);
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'product_id']);
        });

        Schema::create('pos_product_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->string('movement_type', 32);
            $table->decimal('quantity', 12, 3);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->unsignedBigInteger('recorded_by_pos_staff_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('pos_discounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('scope', 32);
            $table->string('amount_type', 32);
            $table->decimal('amount', 12, 3);
            $table->timestamp('validity_start')->nullable();
            $table->timestamp('validity_end')->nullable();
            $table->unsignedTinyInteger('dayofweek_mask')->nullable();
            $table->string('time_start', 8)->nullable();
            $table->string('time_end', 8)->nullable();
            $table->json('branch_scope_json')->nullable();
            $table->boolean('stackable')->default(false);
            $table->boolean('requires_manager_approval')->default(false);
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_discount_targets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('discount_id');
            $table->string('target_type', 32);
            $table->unsignedBigInteger('target_id');
            $table->timestamps();
        });

        Schema::create('pos_loyalty_rules', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('type', 32);
            $table->json('config_json')->nullable();
            $table->timestamp('validity_start')->nullable();
            $table->timestamp('validity_end')->nullable();
            $table->string('status', 32)->default('active');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_taxes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('name', 64);
            $table->string('name_ar', 64)->nullable();
            $table->decimal('rate_percent', 5, 2);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // v2 #7 — custom expense categories (company-managed).
        Schema::create('pos_expense_categories', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('name', 64);
            $table->string('name_ar', 64)->nullable();
            $table->string('key', 32);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_customers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('phone', 32);
            // Phase D3 — merchant CRM profile fields; mirrored here so
            // the test schema tracks the live table. The device config
            // slice deliberately does NOT emit them.
            $table->date('date_of_birth')->nullable();
            $table->json('tags_json')->nullable();
            $table->decimal('wallet_balance', 12, 3)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        // ---- Phase 8.3 order-lifecycle slice (sync ingestion writes here) ----

        Schema::create('pos_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->unsignedBigInteger('table_id')->nullable();
            $table->string('order_type', 32);
            $table->string('status', 32)->default('open');
            // Phase B — void reason snapshot.
            $table->unsignedBigInteger('void_reason_id')->nullable();
            $table->string('void_reason_label', 64)->nullable();
            $table->string('source', 32);
            $table->string('plate_number', 32)->nullable();
            $table->decimal('subtotal', 12, 3)->default(0);
            $table->decimal('discount_total', 12, 3)->default(0);
            // Phase B — cached sum of pos_order_comps.
            $table->decimal('comp_total', 12, 3)->default(0);
            $table->decimal('tax_total', 12, 3)->default(0);
            $table->decimal('grand_total', 12, 3)->default(0);
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->string('client_event_id', 64)->nullable()->unique();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_order_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_name_snapshot');
            $table->decimal('qty', 12, 3)->default('1.000');
            $table->decimal('unit_price_snapshot', 12, 3);
            $table->decimal('line_discount', 12, 3)->default(0);
            $table->decimal('line_total', 12, 3);
            $table->text('recipe_snapshot_json')->nullable();
            $table->string('status', 32)->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_order_item_addons', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('add_on_id')->nullable();
            $table->string('add_on_name_snapshot');
            $table->decimal('price_delta_snapshot', 12, 3);
            $table->text('ingredient_snapshot_json')->nullable();
            $table->timestamps();
        });

        // ---- Phase 8.10 discount-application records (order.create writes here) ----
        Schema::create('pos_order_discounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_item_id')->nullable(); // null = order-level
            $table->unsignedBigInteger('discount_id')->nullable();   // null = manual / ad-hoc
            $table->string('name_snapshot');
            $table->string('amount_type_snapshot', 32)->nullable();
            $table->decimal('amount', 12, 3)->default(0);
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        // ---- Phase B — void/comp reason masters + order comps ----
        Schema::create('pos_void_reasons', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('code', 32);
            $table->string('name', 64);
            $table->string('name_ar', 64)->nullable();
            $table->boolean('affects_inventory')->default(false);
            $table->boolean('requires_manager')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_comp_reasons', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('code', 32);
            $table->string('name', 64);
            $table->string('name_ar', 64)->nullable();
            $table->decimal('max_amount', 12, 3)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_order_comps', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_item_id')->nullable(); // null = whole-order comp
            $table->unsignedBigInteger('comp_reason_id')->nullable();
            $table->string('reason_code_snapshot', 32);
            $table->string('reason_name_snapshot', 64);
            $table->decimal('amount', 12, 3)->default(0);
            $table->unsignedBigInteger('approved_by_pos_staff_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_payments', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('order_id');
            $table->string('method', 32);
            $table->decimal('amount', 12, 3);
            $table->decimal('change_given', 12, 3)->nullable();
            $table->string('softpos_reference', 64)->nullable();
            $table->string('softpos_auth_code', 32)->nullable();
            $table->string('status', 32)->default('success');
            $table->boolean('pending_reconciliation')->default(false);
            $table->unsignedBigInteger('reconciled_by_admin_id')->nullable();
            $table->timestamp('reconciled_at')->nullable();
            $table->timestamp('captured_at')->useCurrent();
            $table->json('bank_response')->nullable();
            $table->string('terminal_id')->nullable();
            $table->unsignedBigInteger('bank_id')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->decimal('roundup_amount', 12, 3)->nullable();
            $table->unsignedBigInteger('charity_transaction_id')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->string('movement_type', 32);
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost_at_time', 12, 3)->default(0);
            $table->string('reference_type')->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->unsignedBigInteger('recorded_by_pos_staff_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
        });

        // ---- Phase A (Additions §2.8) — day-end stock counts ----

        // Written by the stock.count handler on a shortfall (reason =
        // reconciliation_variance); the merchant portal owns manual waste.
        Schema::create('pos_waste_records', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('quantity', 12, 3);
            $table->string('reason', 32);
            $table->string('unit_at_set', 16);
            $table->decimal('unit_cost_at_time', 12, 3)->default(0);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('pos_stock_counts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->text('note')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->unsignedBigInteger('recorded_by_pos_staff_id')->nullable();
            $table->timestamp('counted_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('pos_stock_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stock_count_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('counted_pieces', 12, 3)->nullable();
            $table->decimal('counted_units', 12, 3);
            $table->decimal('expected_units', 12, 3);
            $table->decimal('variance_units', 12, 3);
            $table->decimal('unit_cost_at_time', 12, 3)->default(0);
            $table->unsignedBigInteger('stock_movement_id')->nullable();
            $table->unique(['stock_count_id', 'ingredient_id'], 'pos_stock_count_lines_count_ingredient_unique');
        });

        // ---- Phase 8.4 loyalty slice (earn-at-sale writes) ----

        Schema::create('pos_loyalty_accounts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('loyalty_rule_id');
            $table->integer('stamp_count')->default(0);
            $table->integer('point_balance')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();
            $table->unique(['customer_id', 'loyalty_rule_id'], 'pos_loyalty_accounts_customer_rule_unique');
        });

        Schema::create('pos_loyalty_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('loyalty_account_id');
            $table->string('type', 32);
            $table->integer('points_delta')->default(0);
            $table->integer('stamps_delta')->default(0);
            $table->integer('balance_after_points')->default(0);
            $table->integer('balance_after_stamps')->default(0);
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('recorded_by_user_id')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();
        });

        // ---- Phase 8.5 shifts slice ----

        Schema::create('pos_shifts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_cash', 12, 3)->default(0);
            $table->decimal('closing_cash', 12, 3)->nullable();
            $table->decimal('expected_cash', 12, 3)->nullable();
            $table->decimal('variance', 12, 3)->nullable();
            $table->string('status', 32)->default('open');
            $table->text('note')->nullable();
            $table->timestamps();
        });

        // ---- Phase 8.6 POS staff (PIN login) ----

        Schema::create('pos_staff', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('name');
            $table->text('phone')->nullable();
            $table->string('staff_code', 64)->nullable();
            $table->string('pin_hash');
            $table->string('position', 32);
            $table->string('status', 32)->default('active');
            $table->date('hired_at')->nullable();
            $table->timestamp('terminated_at')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // ---- Phase 8.7 customer vehicle plates (drive-thru lookup) ----

        Schema::create('pos_customer_vehicle_plates', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('customer_id');
            $table->unsignedBigInteger('company_id');
            $table->string('plate_number', 32);
            $table->timestamps();
            $table->unique(['company_id', 'plate_number'], 'pos_cvp_company_plate_unique');
        });

        // ---- Phase 8.8 expense.log + restock.request sync targets ----

        Schema::create('pos_expenses', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('category', 32);
            $table->decimal('amount', 12, 3);
            $table->text('note')->nullable();
            $table->string('receipt_photo_path')->nullable();
            $table->unsignedBigInteger('logged_by_pos_staff_id')->nullable();
            $table->unsignedBigInteger('logged_by_portal_user_id')->nullable();
            $table->timestamp('logged_at')->useCurrent();
            $table->string('status', 32)->default('recorded');
            $table->unsignedBigInteger('reviewed_by_portal_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_restock_requests', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->string('status', 32)->default('draft');
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_restock_request_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('restock_request_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('quantity_requested', 12, 3);
            $table->decimal('quantity_allocated', 12, 3)->default(0);
            $table->string('unit_at_set', 16);
            $table->text('note')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->unique(['restock_request_id', 'ingredient_id'], 'pos_rrl_request_ingredient_unique');
        });

        // ---- Phase 8 round-up donations (donation.record sync target) ----
        Schema::create('pos_roundup_donations', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('bank_id')->nullable();
            $table->string('terminal_id')->nullable();
            $table->unsignedBigInteger('commission_profile_id')->nullable();
            $table->decimal('amount', 12, 3);
            $table->json('bank_response')->nullable();
            $table->string('status', 30)->default('pending');
            $table->string('source', 30)->default('pos_roundup');
            $table->unsignedBigInteger('country_id')->nullable();
            $table->unsignedBigInteger('region_id')->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->unsignedBigInteger('city_id')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('client_event_id', 64)->nullable()->unique();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
        });

        // ---- Per-merchant commission profiles + per-sale breakdown ----
        Schema::create('pos_commission_profiles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id')->unique();
            $table->boolean('is_active')->default(true);
            $table->decimal('merchant_percent', 5, 2)->default(100);
            $table->timestamps();
        });

        Schema::create('pos_commission_shares', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('commission_profile_id');
            $table->string('party_type', 20);
            $table->string('label', 120);
            $table->decimal('percent', 5, 2);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('pos_sale_commissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('payment_id')->nullable();
            $table->unsignedBigInteger('commission_profile_id')->nullable();
            $table->unsignedBigInteger('payout_id')->nullable();
            $table->string('party_type', 20);
            $table->string('party_label', 120);
            $table->decimal('percent', 5, 2);
            $table->decimal('gross_amount', 12, 3);
            $table->decimal('commission_amount', 12, 3);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('client_event_id', 64)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'sort_order'], 'pos_sale_commissions_order_sort_unique');
        });

        // v2 #14 — per-company merchant POS policy (e.g. order_cancel_positions).
        Schema::create('pos_company_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->string('key', 64);
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'key'], 'pos_company_settings_company_key_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_company_settings');
        Schema::dropIfExists('pos_sale_commissions');
        Schema::dropIfExists('pos_commission_shares');
        Schema::dropIfExists('pos_commission_profiles');
        Schema::dropIfExists('pos_roundup_donations');
        Schema::dropIfExists('pos_restock_request_lines');
        Schema::dropIfExists('pos_restock_requests');
        Schema::dropIfExists('pos_expenses');
        Schema::dropIfExists('pos_customer_vehicle_plates');
        Schema::dropIfExists('pos_staff');
        Schema::dropIfExists('pos_shifts');
        Schema::dropIfExists('pos_loyalty_transactions');
        Schema::dropIfExists('pos_loyalty_accounts');
        Schema::dropIfExists('pos_order_comps');
        Schema::dropIfExists('pos_comp_reasons');
        Schema::dropIfExists('pos_void_reasons');
        Schema::dropIfExists('pos_addon_group_categories');
        Schema::dropIfExists('pos_stock_count_lines');
        Schema::dropIfExists('pos_stock_counts');
        Schema::dropIfExists('pos_waste_records');
        Schema::dropIfExists('pos_stock_movements');
        Schema::dropIfExists('pos_payments');
        Schema::dropIfExists('pos_order_discounts');
        Schema::dropIfExists('pos_order_item_addons');
        Schema::dropIfExists('pos_order_items');
        Schema::dropIfExists('pos_orders');
        Schema::dropIfExists('pos_customers');
        Schema::dropIfExists('pos_loyalty_rules');
        Schema::dropIfExists('pos_discount_targets');
        Schema::dropIfExists('pos_discounts');
        Schema::dropIfExists('pos_branch_stock');
        Schema::dropIfExists('pos_product_stock_movements');
        Schema::dropIfExists('pos_product_stock');
        Schema::dropIfExists('pos_product_recipes');
        Schema::dropIfExists('pos_ingredients');
        Schema::dropIfExists('pos_addon_group_products');
        Schema::dropIfExists('pos_addons');
        Schema::dropIfExists('pos_addon_groups');
        Schema::dropIfExists('pos_products');
        Schema::dropIfExists('pos_product_categories');
        Schema::dropIfExists('pos_tables');
        Schema::dropIfExists('pos_floors');
        Schema::dropIfExists('pos_branches');
        Schema::dropIfExists('pos_sync_events');
        Schema::dropIfExists('pos_device_activation_tokens');
        Schema::dropIfExists('pos_devices');
    }
};
