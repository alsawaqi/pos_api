<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
 * FK columns are generally plain integers here so tests don't need to seed
 * parent rows. The QR session/round delete actions used by QR2 are deliberately
 * enforced because session, order, and round lifetime correctness depends on
 * their exact behaviour.
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
            $table->string('terminal_pin', 32)->nullable();
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
            // Phase D2 — §5.5.1 branch availability: NULL = all branches,
            // else a JSON array of pos_branches ids.
            $table->json('branch_availability_json')->nullable();
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
            // Phase D2 — unit-mode LOW STOCK badge threshold (NULL = no badge).
            $table->decimal('low_stock_threshold', 12, 3)->nullable();
            // P-G1.5 — default shelf life in days (NULL = keeps indefinitely).
            $table->unsignedSmallInteger('shelf_life_days')->nullable();
            $table->decimal('cost_price', 12, 3)->nullable();
            $table->decimal('tax_rate', 5, 2)->nullable();
            // Phase D2 — §5.5.3 tax-inclusive flag (display-only for now).
            $table->boolean('tax_inclusive')->default(false);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->string('status', 32)->default('active');
            // Phase D2 — §5.5.3 "Show on Customer Tablet menu yes/no".
            $table->boolean('show_on_customer_tablet')->default(true);
            // P-G2 — internal items (cups/lids): never on the POS menu or
            // tablet, full stock participation.
            $table->boolean('is_internal')->default(false);
            // PD3a — physical-item kind: 'packaging' | 'general' | NULL.
            // The device never reads it (internal items are excluded from
            // /device/config); mirrored for schema parity only.
            $table->string('internal_purpose', 16)->nullable();
            // G1 — menu time-window ('HH:MM:SS', both NULL = always
            // available, start > end wraps midnight).
            $table->string('available_from', 8)->nullable();
            $table->string('available_until', 8)->nullable();
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
            // P-G3 — the add-on IS this product (consumes its real stock).
            $table->unsignedBigInteger('linked_product_id')->nullable();
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
            // P-G7 — mirrors pos_admin's 2026_07_20_010000 migration.
            $table->decimal('commission_percent', 5, 2)->default(0);
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

        // P-G2 — physical-item components: per ONE unit sold of product_id,
        // consume quantity of each component (unit-mode products: cups,
        // lids...). Mirrors pos_admin's 2026_07_16_010000 migration.
        Schema::create('pos_product_components', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('component_product_id');
            $table->decimal('quantity', 12, 3);
            $table->timestamps();
            $table->unique(['product_id', 'component_product_id'], 'pos_product_components_pair_unique');
        });

        // PD3b — per-option consumption lines: ingredient XOR
        // component-product (app-enforced), direction add|remove,
        // quantity per ONE parent line unit. Mirrors pos_admin's
        // 2026_07_23_010000 migration.
        Schema::create('pos_addon_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('add_on_id');
            $table->unsignedBigInteger('ingredient_id')->nullable();
            $table->unsignedBigInteger('component_product_id')->nullable();
            $table->string('direction', 8)->default('add');
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 16)->nullable();
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
            $table->unique(['add_on_id', 'ingredient_id', 'direction'], 'pos_addon_consumptions_ing_dir_unique');
            $table->unique(['add_on_id', 'component_product_id', 'direction'], 'pos_addon_consumptions_prod_dir_unique');
        });

        Schema::create('pos_branch_stock', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('quantity', 12, 3)->default(0);
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();
        });

        // P-G4 — the merchant's central ingredient warehouse balance (schema
        // owned by pos_admin 2026_07_18_000000). pos_api never touches it;
        // mirrored only so the schemas stay in lock-step.
        Schema::create('pos_ingredient_stock', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('quantity', 12, 3)->default(0);
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'ingredient_id']);
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
            // Product wastage (folds in admin 2026_07_27_010000): reason + frozen cost.
            $table->string('reason', 32)->nullable();
            $table->decimal('quantity', 12, 3);
            $table->decimal('unit_cost', 12, 3)->nullable();
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
            // P-F4: order-scope rules only — true = the device applies the
            // rule by itself to every qualifying order. Always true for
            // product/category scopes (merchant-side forced; the device
            // ignores it there — targeted rules stay automatic per line).
            $table->boolean('auto_apply')->default(false);
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

        // P-F9 — merchant offers / promotions. type + type-specific config
        // JSON (the pos_loyalty_rules pattern); shared applicability axes
        // mirror pos_discounts. Emitted verbatim in the device config.
        Schema::create('pos_offers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('name', 120);
            $table->string('name_ar', 120)->nullable();
            $table->string('type', 24);
            $table->json('config');
            // Bundle is ALWAYS cashier-picked (forced false merchant-side).
            $table->boolean('auto_apply')->default(true);
            $table->timestamp('validity_start')->nullable();
            $table->timestamp('validity_end')->nullable();
            $table->smallInteger('dayofweek_mask')->nullable();
            $table->string('time_start', 8)->nullable();
            $table->string('time_end', 8)->nullable();
            $table->json('branch_scope_json')->nullable();
            $table->smallInteger('max_per_order')->nullable();
            $table->string('status', 16)->default('active');
            $table->timestamps();
            $table->softDeletes();
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
            $table->string('phone_canonical', 32)->nullable();
            $table->foreignId('merged_into_customer_id')->nullable()->index()->constrained('pos_customers');
            $table->index(['company_id', 'phone_canonical'], 'pos_customers_company_canonical_idx');
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
            // Mirrors live: a soft-deleted row still OCCUPIES the phone slot,
            // which is exactly what the device store's revive path handles.
            $table->unique(['company_id', 'phone'], 'pos_customers_company_phone_unique');
        });

        Schema::create('pos_qr_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->foreignId('device_id')
                ->nullable()
                ->constrained('pos_devices')
                ->cascadeOnDelete();
            $table->foreignId('table_id')
                ->nullable()
                ->constrained('pos_tables')
                ->nullOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('token_expires_at');
            $table->string('status', 16)->default('pending');
            $table->string('client_secret_hash', 64)->nullable();
            $table->timestamp('bound_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('secret_rotated_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->index(['device_id', 'status'], 'pos_qr_sessions_device_status_idx');
            $table->index(['status', 'expires_at'], 'pos_qr_sessions_status_expires_idx');

            $table->foreignId('table_session_id')->nullable()->constrained('pos_table_sessions')->nullOnDelete();
            $table->string('origin', 24)->nullable(); // station | table_card (T9)
            $table->string('scan_fingerprint_hash', 64)->nullable();
            $table->string('scan_ip_hash', 64)->nullable();
            $table->string('scan_geofence_verdict', 16)->nullable();
            $table->index(['table_session_id'], 'pos_qr_sessions_table_session_idx');
            $table->timestamp('released_at')->nullable();
            $table->foreignId('handover_from_id')->nullable()->constrained('pos_qr_sessions')->nullOnDelete();
            $table->index('released_at', 'pos_qr_sessions_released_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX pos_qr_sessions_table_live_unique ON pos_qr_sessions (table_id) '.
            "WHERE table_id IS NOT NULL AND status IN ('pending', 'active', 'ordered')"
        );

        // QR-003 T2 — seating schema owned by pos_admin (5a985d9), with no
        // readers or writers until T3+. Company parents are absent in this
        // mirror; all other seating foreign keys retain their delete actions.
        Schema::create('pos_table_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('table_id')->constrained('pos_tables')->cascadeOnDelete();
            // open | billing | closing | closed | merged | expired (T3 enum)
            $table->string('status', 16)->default('open');
            // staff_till | staff_handheld | station | table_card
            $table->string('origin', 24);
            // T3: killing a payment station must leave the seating alive.
            $table->foreignId('opened_by_device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->foreignId('closed_by_device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            // The live bill is NULL until the first round / staff line lands.
            $table->foreignId('order_id')->nullable()->constrained('pos_orders')->nullOnDelete();
            // The winner this seating was folded into; NULL unless merged.
            $table->foreignId('merged_into_id')->nullable()->constrained('pos_table_sessions')->nullOnDelete();
            // T-MMDD-NNN from T1's pos_temp_reference_sequences, allocated in T3.
            $table->string('temp_reference', 32)->nullable();
            // T4 table.session.open replay key; NULL for server-originated opens.
            $table->string('client_request_id', 64)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('expires_at'); // T3 sets opened_at + 6 hours.
            $table->timestamp('billing_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            // paid | staff_close | cleared | merged | expired (T3)
            $table->string('close_reason', 24)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status'], 'pos_table_sessions_branch_status_idx');
            $table->index(['branch_id', 'temp_reference'], 'pos_table_sessions_branch_temp_reference_idx');
            $table->index(['order_id'], 'pos_table_sessions_order_idx');
            $table->index(['status', 'expires_at'], 'pos_table_sessions_status_expires_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX pos_table_sessions_table_live_unique ON pos_table_sessions (table_id) '.
            "WHERE status IN ('open', 'billing', 'closing')"
        );
        DB::statement(
            'CREATE UNIQUE INDEX pos_table_sessions_branch_request_unique ON pos_table_sessions (branch_id, client_request_id) '.
            'WHERE client_request_id IS NOT NULL'
        );

        // ---- Phase 8.3 order-lifecycle slice (sync ingestion writes here) ----

        Schema::create('pos_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->foreignId('qr_session_id')
                ->nullable()
                ->constrained('pos_qr_sessions')
                ->nullOnDelete();
            $table->string('client_request_id', 64)->nullable();
            $table->unsignedBigInteger('charge_device_id')->nullable();
            $table->unsignedInteger('charge_amount_baisas')->nullable();
            $table->unsignedInteger('charge_roundup_amount_baisas')->nullable();
            $table->timestamp('charge_claimed_at')->nullable();
            $table->timestamp('charge_deadline_at')->nullable();
            $table->string('charge_outcome', 16)->nullable();
            $table->json('qr_recovery_request')->nullable();
            // Device-to-device order transfer (mirrors pos_admin's
            // 2026_07_31_010000 migration). A held order addressed to
            // transferred_to_device_id waits in that device's inbox until it
            // claims (clears the target) — see TransferOrderHandler.
            $table->unsignedBigInteger('transferred_to_device_id')->nullable();
            $table->unsignedBigInteger('transferred_from_device_id')->nullable();
            $table->timestamp('transferred_at')->nullable();
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
            // P-F8 — the printed receipt number (prefix + zero-padded
            // counter, e.g. "KLD-0042"); NULL when numbering is off or the
            // order was queued offline without a server allocation.
            $table->string('receipt_number', 24)->nullable();
            $table->string('temp_reference', 32)->nullable();
            // P-G7 — delivery-provider lifecycle (mirrors pos_admin's
            // 2026_07_20_010000 migration): provider linkage + the
            // Proceed-popup fields + the punch/confirm money snapshot.
            $table->unsignedBigInteger('delivery_provider_id')->nullable();
            $table->string('delivery_provider_name', 64)->nullable();
            $table->string('delivery_reference', 64)->nullable();
            $table->string('delivery_customer_phone', 32)->nullable();
            $table->string('delivery_driver_phone', 32)->nullable();
            $table->decimal('delivery_commission_percent', 5, 2)->nullable();
            $table->decimal('delivery_expected_payout', 12, 3)->nullable();
            $table->decimal('delivery_received_amount', 12, 3)->nullable();
            $table->decimal('delivery_variance', 12, 3)->nullable();
            $table->timestamp('delivery_punched_at')->nullable();
            $table->timestamp('delivery_confirmed_at')->nullable();
            $table->unsignedBigInteger('delivery_confirmed_by_user_id')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'receipt_number'], 'pos_orders_company_receipt_idx');
            $table->index(['branch_id', 'temp_reference'], 'pos_orders_branch_temp_reference_idx');
            $table->index(['company_id', 'delivery_provider_id'], 'pos_orders_company_provider_idx');
            $table->index(['status', 'charge_deadline_at'], 'pos_orders_status_charge_deadline_idx');
            $table->unique(['qr_session_id', 'client_request_id'], 'pos_orders_qr_session_request_unique');

            $table->foreignId('table_session_id')->nullable()->constrained('pos_table_sessions')->nullOnDelete();
            $table->index(['table_session_id'], 'pos_orders_table_session_idx');
        });

        DB::statement(
            'CREATE UNIQUE INDEX pos_orders_qr_session_live_unique ON pos_orders (qr_session_id) '.
            "WHERE qr_session_id IS NOT NULL AND status NOT IN ('paid', 'pending_verification', 'void', 'refunded')"
        );
        DB::statement(
            'CREATE INDEX pos_orders_qr_session_idx ON pos_orders (qr_session_id) '.
            'WHERE qr_session_id IS NOT NULL'
        );

        Schema::create('pos_qr_order_rounds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('qr_session_id')
                ->nullable()
                ->constrained('pos_qr_sessions')
                ->cascadeOnDelete();
            $table->foreignId('order_id')
                ->nullable()
                ->constrained('pos_orders')
                ->nullOnDelete();
            $table->unsignedInteger('round_no');
            $table->string('status', 24);
            $table->string('client_request_id', 64);
            $table->json('priced_lines');
            // Private append-ready inventory/discount snapshot. NULL for
            // kitchen-direct and every resolved staff-confirm round.
            $table->json('confirm_payload')->nullable();
            // SQLite has no independent sequence; allocation uses MAX+1
            // inside the five-retry acceptance transaction.
            $table->unsignedBigInteger('accepted_seq')->nullable()->unique();
            $table->unsignedInteger('subtotal_baisas');
            $table->unsignedInteger('tax_baisas');
            $table->unsignedInteger('total_baisas');
            $table->timestamp('submitted_at');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by_device_id')
                ->nullable()
                ->constrained('pos_devices')
                ->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['qr_session_id', 'client_request_id'],
                'pos_qr_rounds_session_request_unique',
            );
            $table->index(
                ['order_id', 'status'],
                'pos_qr_rounds_order_status_idx',
            );

            $table->foreignId('table_session_id')->nullable()->constrained('pos_table_sessions')->nullOnDelete();
            // Seating folded in FROM on a merge; NULL for a normal round.
            $table->foreignId('origin_table_session_id')->nullable()->constrained('pos_table_sessions')->nullOnDelete();
            $table->timestamp('kitchen_printed_at')->nullable();
            $table->boolean('needs_review')->default(false);
            // NULL seating ids leave existing rows unaffected. The shipped
            // pos_qr_rounds_session_request_unique remains unchanged.
            $table->unique(['table_session_id', 'client_request_id'], 'pos_qr_rounds_table_session_request_unique');
        });

        Schema::create('pos_table_session_events', function (Blueprint $table): void {
            $table->id(); // The append-only feed cursor: devices poll ?after=<id>.
            $table->unsignedBigInteger('company_id');
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('table_session_id')->constrained('pos_table_sessions')->cascadeOnDelete();
            $table->foreignId('table_id')->constrained('pos_tables')->cascadeOnDelete();
            // opened | round_appended | moved | joined | billing | closed | merged |
            // expired | needs_review | customer_order_arrived | sent_to_counter (T4)
            $table->string('event_type', 32);
            $table->foreignId('device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->json('payload')->nullable();
            $table->timestamp('created_at'); // No updated_at: append-only journal.
            $table->index(['branch_id', 'id'], 'pos_table_session_events_branch_cursor_idx');
            $table->index(['table_session_id', 'id'], 'pos_table_session_events_session_idx');
        });

        Schema::create('pos_qr_session_scans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->foreignId('table_id')->nullable()->constrained('pos_tables')->nullOnDelete();
            $table->foreignId('qr_session_id')->nullable()->constrained('pos_qr_sessions')->nullOnDelete();
            $table->foreignId('table_session_id')->nullable()->constrained('pos_table_sessions')->nullOnDelete();
            // owner | viewer | refused: first scanner owns, second is read-only.
            $table->string('role', 16);
            $table->string('device_fingerprint_hash', 64)->nullable(); // SHA-256 only.
            $table->string('ip_hash', 64)->nullable(); // App-key HMAC, never a raw IP.
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            // inside | outside | unknown | refused (T9)
            $table->string('geofence_verdict', 16)->nullable();
            $table->timestamp('scanned_at');
            $table->timestamp('created_at');
            $table->string('outcome', 24)->nullable();
            $table->unsignedInteger('accuracy_m')->nullable();
            $table->unsignedInteger('distance_m')->nullable();
            $table->index(['branch_id', 'scanned_at'], 'pos_qr_session_scans_branch_scanned_idx');
            $table->index(['table_id', 'scanned_at'], 'pos_qr_session_scans_table_scanned_idx');
        });

        Schema::create('pos_kitchen_tickets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            // e.g. round:<round id>; T4 defines the opaque key format.
            $table->string('ticket_key', 96);
            $table->foreignId('round_id')->nullable()->constrained('pos_qr_order_rounds')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('pos_orders')->nullOnDelete();
            $table->foreignId('claimed_by_device_id')->nullable()->constrained('pos_devices')->nullOnDelete();
            $table->timestamp('claimed_at');
            $table->timestamp('printed_at')->nullable();
            // printed | failed: the result, never the attempt (R7).
            $table->string('print_result', 16)->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'ticket_key'], 'pos_kitchen_tickets_branch_key_unique');
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
            // P-G2 hardening — frozen parent-line components (mirrors
            // pos_admin 2026_08_05_010000). NULL = legacy row → live read.
            $table->text('component_snapshot_json')->nullable();
            $table->string('status', 32)->default('open');
            $table->text('notes')->nullable();
            $table->timestamps();

            // prepared | not_prepared (T11); no wastage arithmetic in T2.
            $table->string('cancel_disposition', 16)->nullable();
            $table->timestamp('cancelled_at')->nullable();
        });

        Schema::create('pos_order_item_addons', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_item_id');
            $table->unsignedBigInteger('add_on_id')->nullable();
            $table->string('add_on_name_snapshot');
            $table->decimal('price_delta_snapshot', 12, 3);
            $table->text('ingredient_snapshot_json')->nullable();
            // P-G3 — product-as-add-on freeze (consumption + reporting).
            $table->unsignedBigInteger('linked_product_id')->nullable()->index();
            $table->text('product_snapshot_json')->nullable();
            // PD3b — per-option consumption lines frozen at order create.
            $table->text('consumption_snapshot_json')->nullable();
            $table->timestamps();
        });

        // Joined dine-in tables (v2) — the EXTRA tables a shared order covered
        // (primary stays on pos_orders.table_id). FK-less per the api test
        // convention. Mirrors pos_admin 2026_07_30_010000_create_pos_order_tables.
        Schema::create('pos_order_tables', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('table_id')->nullable();
            $table->timestamps();
            $table->unique(['order_id', 'table_id'], 'pos_order_tables_order_table_unique');
            $table->index(['table_id'], 'pos_order_tables_table_idx');
        });

        // ---- Phase 8.10 discount-application records (order.create writes here) ----
        Schema::create('pos_order_discounts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('order_item_id')->nullable(); // null = order-level
            $table->unsignedBigInteger('discount_id')->nullable();   // null = manual / ad-hoc
            // P-F9: which pos_offers promotion granted this amount
            // (null = a plain discount application).
            $table->unsignedBigInteger('offer_id')->nullable();
            $table->string('name_snapshot');
            $table->string('amount_type_snapshot', 32)->nullable();
            $table->decimal('amount', 12, 3)->default(0);
            // P-F4: cashier's free-text reason for a manual / custom
            // discount (trimmed + capped to 160 by writeDiscounts).
            $table->string('reason', 160)->nullable();
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
            // P-F5 — a gifted line: 100% write-off, NO reason, NO cap.
            $table->boolean('is_gift')->default(false);
            $table->decimal('amount', 12, 3)->default(0);
            $table->decimal('qty', 12, 3)->nullable();
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
            $table->index('softpos_reference', 'pos_payments_softpos_ref_idx');
        });

        Schema::create('pos_stock_movements', function (Blueprint $table): void {
            $table->id();
            // P-G4 — NULL = the merchant's central warehouse pool. pos_api
            // never writes NULL (devices are branch-scoped) and every device
            // read filters branch_id = the device's branch.
            $table->unsignedBigInteger('branch_id')->nullable();
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

        // Merchant resolution trail for API-001 shortfall markers. Production
        // is owned by pos_admin; this is only the isolated test mirror.
        Schema::create('pos_loyalty_shortfall_reviews', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('loyalty_transaction_id');
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->text('resolution_note');
            $table->timestamp('resolved_at');
            $table->timestamp('created_at')->useCurrent();
            $table->unique('loyalty_transaction_id', 'pos_loyalty_shortfall_reviews_txn_unique');
            $table->index(
                ['company_id', 'resolved_at'],
                'pos_loyalty_shortfall_reviews_company_resolved_idx',
            );
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
            // HH-2 — staff-shared shift (open once a day, any terminal).
            $table->boolean('is_shared')->default(false);
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
            // P-F2 — many-to-many: one row per customer↔plate LINK
            // (a family car shared by several loyalty members), plus a
            // plain index serving the "plate → customer(s)" hot path.
            $table->unique(['company_id', 'customer_id', 'plate_number'], 'pos_cvp_company_customer_plate_unique');
            $table->index(['company_id', 'plate_number'], 'pos_cvp_company_plate_index');
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
            // Mirrors pos_admin 2026_08_05_010100 (restock resolution).
            $table->string('resolution', 16)->nullable();
            $table->string('resolution_note', 255)->nullable();
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
            $table->string('branch_name')->nullable();
            $table->unsignedBigInteger('device_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('payment_id');
            $table->unsignedBigInteger('bank_id')->nullable();
            $table->string('terminal_id')->nullable();
            $table->unsignedBigInteger('commission_profile_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
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
            // P-F7 — when the round-up was actually forwarded to the charity
            // app (NULL = pending reconciliation approval / forward failed).
            // Mirrors pos_admin's 2026_07_11_010000 migration.
            $table->timestamp('forwarded_at')->nullable();
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
            // Channel scope: all | card | cash_bank (admin-written; the recorder
            // picks each line's base from it — bank lines are card-only anyway).
            $table->string('applies_to', 20)->default('all');
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
            // Mixed-tender apportionment — money channel of the row:
            // card (platform holds) | cash_bank (merchant holds) | all (legacy).
            $table->string('channel', 16)->default('all');
            $table->decimal('percent', 5, 2);
            $table->decimal('gross_amount', 12, 3);
            $table->decimal('commission_amount', 12, 3);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('client_event_id', 64)->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();
            // Commission settlement (admin-written; mirrored so the shared
            // pos_sale_commissions shape matches prod). pos_api only writes the
            // estimate (commission_amount); these stay NULL/false on its writes.
            $table->decimal('settled_amount', 12, 3)->nullable();
            $table->boolean('is_settled')->default(false);
            $table->timestamp('settled_at')->nullable();
            $table->unsignedBigInteger('settlement_id')->nullable();
            // Phase B commission-invoice claim (admin-written; mirrored so the
            // shared shape matches prod). The void-reversal guard reads it so an
            // invoiced sale's rows survive a later void.
            $table->unsignedBigInteger('invoice_id')->nullable();
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

        // QR-003 T0 — pos_admin-owned schema, merchant-written branch policy.
        // This API mirror has no pos_companies table, so company_id stays a
        // plain indexed integer; the branch foreign key mirrors production.
        Schema::create('pos_branch_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id')->index();
            $table->foreignId('branch_id')->constrained('pos_branches')->cascadeOnDelete();
            $table->string('key', 64);
            $table->json('value')->nullable();
            $table->timestamps();
            $table->unique(['branch_id', 'key'], 'pos_branch_settings_branch_key_unique');
            $table->index(['company_id', 'key'], 'pos_branch_settings_company_key_idx');
        });

        // P-F8 — server-owned order-number counters (mirrors pos_admin's
        // 2026_07_12_010000 migration). branch_id NULL = company scope;
        // seq_date NULL = continuous counter (set = that day's row when
        // daily reset is on). next_number = what the NEXT allocation returns.
        Schema::create('pos_order_sequences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->date('seq_date')->nullable();
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
            $table->index(['company_id', 'branch_id', 'seq_date'], 'pos_order_sequences_lookup_idx');
        });
        // The same COALESCE functional unique index as live Postgres — NULL
        // branch/date coalesce to impossible sentinels so "one row per
        // scope" is enforceable and the allocator's insertOrIgnore dedupes
        // a concurrent first allocation.
        DB::statement(
            'CREATE UNIQUE INDEX pos_order_sequences_scope_unique ON pos_order_sequences '.
            "(company_id, COALESCE(branch_id, 0), COALESCE(seq_date, '1970-01-01'))"
        );

        // QR-003 T1 — branch/day temporary-reference counters. Like the
        // existing order sequences mirror, these scope ids deliberately omit FKs.
        Schema::create('pos_temp_reference_sequences', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->date('seq_date');
            $table->unsignedInteger('next_number')->default(1);
            $table->timestamps();
            $table->unique(['company_id', 'branch_id', 'seq_date'], 'pos_temp_reference_sequences_scope_unique');
        });

        // P-G1 — kitchen production batches + their ingredient lines
        // (mirrors pos_admin's 2026_07_14_010000 migration). Written by
        // the device production endpoints; std (locked recipe x qty) and
        // declared-extra lines stored separately.
        Schema::create('pos_productions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->decimal('quantity', 12, 3);
            $table->string('status', 16)->default('in_progress');
            $table->unsignedBigInteger('started_by_staff_id')->nullable();
            $table->unsignedBigInteger('finished_by_staff_id')->nullable();
            $table->unsignedBigInteger('cancelled_by_staff_id')->nullable();
            $table->unsignedBigInteger('cancel_approved_by_staff_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            // P-G1.5 — the chef's per-batch expiry (NULL = never expires).
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'branch_id', 'started_at'], 'pos_productions_company_branch_idx');
            $table->index(['branch_id', 'status'], 'pos_productions_branch_status_idx');
        });

        Schema::create('pos_production_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('production_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('quantity', 12, 3);
            $table->string('unit_at_time', 16);
            $table->boolean('is_extra')->default(false);
            $table->timestamps();
            $table->index(['production_id'], 'pos_production_lines_production_idx');
            $table->index(['ingredient_id'], 'pos_production_lines_ingredient_idx');
        });

        // P-G6 — staff announcements (portal -> devices) + read receipts
        // (mirrors pos_admin's 2026_07_19_000000 migration; the
        // portal-inbox pair lives merchant-side only — pos_users isn't in
        // this schema). Written by pos_merchant; this app serves them in
        // /device/config and writes the receipts. created_by_name is the
        // sender snapshot so devices render it without a users join.
        Schema::create('pos_staff_messages', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('target_type', 16);
            $table->unsignedBigInteger('target_branch_id')->nullable();
            $table->unsignedBigInteger('target_staff_id')->nullable();
            $table->string('title')->nullable();
            $table->text('body');
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->string('created_by_name')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['company_id', 'created_at'], 'pos_staff_messages_company_created_idx');
            $table->index(['target_branch_id'], 'pos_staff_messages_branch_idx');
        });

        Schema::create('pos_staff_message_reads', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('staff_message_id');
            $table->unsignedBigInteger('staff_id');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->timestamp('read_at');
            $table->timestamps();
            $table->unique(['staff_message_id', 'staff_id'], 'pos_staff_message_reads_unique');
        });

        // ---- Phase 3 — marketing sliders (advertising on the customer screen).
        // content_assets is marketing-api-owned; the sliders/items/targets are
        // pos_admin-owned; pos_api READS all four for the device-config slider
        // slice. pos_marketing_impressions is pos_api-OWNED (display telemetry).

        Schema::create('content_assets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('advertiser_id')->nullable();
            $table->string('type', 16)->default('image'); // image | video
            $table->string('title')->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('path')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('url')->nullable();
            $table->string('thumbnail_url')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_marketing_sliders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('name');
            $table->unsignedInteger('loop_interval_seconds')->default(6);
            $table->string('status', 16)->default('draft');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pos_marketing_slider_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('slider_id');
            $table->unsignedBigInteger('content_asset_id');
            $table->unsignedBigInteger('advertiser_id')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_marketing_slider_targets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('slider_id');
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->timestamps();
        });

        Schema::create('pos_marketing_impressions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('device_id')->nullable();
            $table->unsignedBigInteger('company_id')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->unsignedBigInteger('slider_id')->nullable();
            $table->unsignedBigInteger('slider_item_id')->nullable();
            $table->unsignedBigInteger('content_asset_id')->nullable();
            $table->unsignedBigInteger('advertiser_id')->nullable();
            $table->unsignedInteger('play_duration_ms')->default(0);
            // Anonymous audience measurement (null = not measured on this play).
            $table->unsignedInteger('viewers_peak')->nullable();
            $table->unsignedInteger('viewers_avg')->nullable();
            $table->unsignedInteger('viewers_distinct')->nullable();
            $table->unsignedInteger('attention_ms')->nullable();
            $table->uuid('client_event_id')->nullable();
            $table->timestamp('played_at')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'client_event_id']);
        });
        $this->createPay002Schema();
    }

    private function createPay002Schema(): void
    {
        // Bank reference fixture only; the charity table is never migrated by this API.
        Schema::create('banks', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('short_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('pos_bank_softpos_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('bank_id')->unique()->constrained('banks')->restrictOnDelete();
            $table->string('softpos_provider', 32);
            $table->string('softpos_package', 128)->nullable();
            $table->char('currency_code', 4)->default('0512');
            $table->boolean('refund_needs_transaction_id')->default(false);
            $table->boolean('void_needs_session_id')->default(false);
            $table->string('min_app_version', 32)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->unsignedBigInteger('updated_by_user_id')->nullable();
            $table->timestamp('provider_changed_at')->nullable();
            $table->timestamps();
        });

        Schema::table('pos_payments', function (Blueprint $table): void {
            $table->string('softpos_provider', 32)->nullable();
            $table->string('softpos_package', 128)->nullable();
            $table->string('softpos_reported_provider', 32)->nullable();
            $table->boolean('softpos_mismatch')->default(false)->index();
            $table->string('softpos_mismatch_note', 255)->nullable();
            $table->string('softpos_transaction_id', 64)->nullable()->index();
            $table->string('softpos_rrn', 32)->nullable()->index();
            $table->string('softpos_batch_number', 16)->nullable();
            $table->string('softpos_card_masked', 24)->nullable();
            $table->string('softpos_card_type', 16)->nullable();
            $table->timestamp('softpos_receipt_at')->nullable();
            $table->decimal('refunded_total', 12, 3)->default(0);
            $table->timestamp('voided_at')->nullable();
            $table->unsignedBigInteger('reversal_id')->nullable();
            // Sale amounts are positive; reversal ledger rows carry negative amounts.
            $table->string('direction', 8)->default('sale');
        });
        Schema::table('pos_devices', function (Blueprint $table): void {
            $table->string('card_tenders_blocked_reason', 64)->nullable();
            $table->timestamp('card_tenders_blocked_at')->nullable();
            $table->timestamp('card_tenders_unblocked_at')->nullable();
        });
        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->decimal('refunded_total', 12, 3)->default(0);
        });

        Schema::create('pos_payment_reversals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->foreignId('order_id')->constrained('pos_orders')->restrictOnDelete();
            $table->foreignId('payment_id')->constrained('pos_payments')->restrictOnDelete();
            $table->string('kind', 8);
            $table->decimal('amount', 12, 3);
            $table->integer('amount_baisas');
            $table->char('currency_code', 4);
            $table->string('status', 16)->default('pending');
            $table->string('softpos_provider', 32);
            $table->string('softpos_package', 128);
            $table->unsignedBigInteger('bank_id');
            $table->string('terminal_id', 64)->nullable();
            $table->string('original_softpos_transaction_id', 64)->nullable();
            $table->string('reversal_softpos_transaction_id', 64)->nullable();
            $table->string('reversal_rrn', 32)->nullable();
            $table->string('reversal_auth_code', 32)->nullable();
            $table->string('response_code', 16)->nullable();
            $table->string('response_description', 255)->nullable();
            $table->jsonb('receipt_json')->nullable();
            $table->string('reason_code', 32)->nullable();
            $table->string('reason_note', 255)->nullable();
            $table->foreignId('void_reason_id')->nullable()->constrained('pos_void_reasons')->restrictOnDelete();
            $table->unsignedBigInteger('requested_by_staff_id')->nullable();
            $table->foreignId('approved_by_staff_id')->constrained('pos_staff')->restrictOnDelete();
            $table->foreignId('device_id')->constrained('pos_devices')->restrictOnDelete();
            $table->string('client_request_id', 64);
            $table->string('request_fingerprint', 64);
            $table->timestamp('attempted_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedBigInteger('resolved_by_user_id')->nullable();
            $table->string('resolved_note', 255)->nullable();
            $table->unsignedBigInteger('ledger_payment_id')->nullable();
            $table->timestamps();
            $table->unique(['device_id', 'client_request_id'], 'pos_reversals_device_request_unique');
            $table->index(['device_id', 'status'], 'pos_reversals_device_status_idx');
            $table->index(['payment_id', 'status'], 'pos_reversals_payment_status_idx');
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("CREATE UNIQUE INDEX pos_reversals_one_pending ON pos_payment_reversals (payment_id) WHERE status = 'pending'");
        }

        Schema::table('pos_payment_reversals', function (Blueprint $table): void {
            $table->boolean('refund_needs_transaction_id')->default(false);
            $table->boolean('void_needs_session_id')->default(false);
        });
        Schema::create('pos_payment_reversal_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reversal_id')->constrained('pos_payment_reversals')->restrictOnDelete();
            $table->foreignId('device_id')->constrained('pos_devices')->restrictOnDelete();
            $table->string('client_request_id', 64);
            $table->string('request_fingerprint', 64);
            $table->jsonb('response_json');
            $table->timestamp('created_at');
            $table->unique(['device_id', 'client_request_id'], 'pos_reversal_results_device_request_unique');
        });

        Schema::create('pos_payment_reversal_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reversal_id')->constrained('pos_payment_reversals')->restrictOnDelete();
            $table->foreignId('order_item_id')->constrained('pos_order_items')->restrictOnDelete();
            $table->decimal('qty', 10, 3);
            $table->decimal('amount', 12, 3);
            $table->integer('amount_baisas');
            $table->string('stock_mode_at_refund', 16);
            $table->boolean('returned_to_stock')->default(false);
            $table->unsignedBigInteger('product_stock_movement_id')->nullable();
            $table->unique(['reversal_id', 'order_item_id'], 'pos_reversal_lines_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_payment_reversal_results');
        Schema::dropIfExists('pos_payment_reversal_lines');
        Schema::dropIfExists('pos_payment_reversals');
        Schema::dropIfExists('pos_bank_softpos_profiles');
        Schema::dropIfExists('banks');
        Schema::dropIfExists('pos_marketing_impressions');
        Schema::dropIfExists('pos_marketing_slider_targets');
        Schema::dropIfExists('pos_marketing_slider_items');
        Schema::dropIfExists('pos_marketing_sliders');
        Schema::dropIfExists('content_assets');
        Schema::dropIfExists('pos_staff_message_reads');
        Schema::dropIfExists('pos_staff_messages');
        Schema::dropIfExists('pos_production_lines');
        Schema::dropIfExists('pos_productions');
        Schema::dropIfExists('pos_temp_reference_sequences');
        Schema::dropIfExists('pos_order_sequences');
        Schema::dropIfExists('pos_branch_settings');
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
        Schema::dropIfExists('pos_loyalty_shortfall_reviews');
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
        Schema::dropIfExists('pos_order_tables');
        Schema::dropIfExists('pos_order_discounts');
        Schema::dropIfExists('pos_order_item_addons');
        Schema::dropIfExists('pos_order_items');
        Schema::dropIfExists('pos_kitchen_tickets');
        Schema::dropIfExists('pos_qr_session_scans');
        Schema::dropIfExists('pos_table_session_events');
        Schema::dropIfExists('pos_qr_order_rounds');
        Schema::dropIfExists('pos_orders');
        Schema::dropIfExists('pos_table_sessions');
        Schema::dropIfExists('pos_qr_sessions');
        Schema::dropIfExists('pos_customers');
        Schema::dropIfExists('pos_loyalty_rules');
        Schema::dropIfExists('pos_offers');
        Schema::dropIfExists('pos_discount_targets');
        Schema::dropIfExists('pos_discounts');
        Schema::dropIfExists('pos_branch_stock');
        Schema::dropIfExists('pos_ingredient_stock');
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
