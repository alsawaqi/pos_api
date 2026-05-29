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
            $table->uuid('client_event_id')->unique();
            $table->unsignedBigInteger('device_id')->nullable();
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
            $table->json('opening_hours_json')->nullable();
            $table->string('default_order_type', 16)->default('dine_in');
            $table->json('settings')->nullable();
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

        Schema::create('pos_ingredients', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('unit', 16)->default('piece');
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

        Schema::create('pos_branch_stock', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('ingredient_id');
            $table->decimal('quantity', 12, 3)->default(0);
            $table->timestamp('last_movement_at')->nullable();
            $table->timestamps();
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

        Schema::create('pos_customers', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->string('phone', 32);
            $table->decimal('wallet_balance', 12, 3)->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_customers');
        Schema::dropIfExists('pos_loyalty_rules');
        Schema::dropIfExists('pos_discount_targets');
        Schema::dropIfExists('pos_discounts');
        Schema::dropIfExists('pos_branch_stock');
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
