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
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_sync_events');
        Schema::dropIfExists('pos_device_activation_tokens');
        Schema::dropIfExists('pos_devices');
    }
};
