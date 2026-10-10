<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_kv2_branches', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id');
            $t->unique(['company_id', 'branch_id']);
            $t->string('mode')->default('legacy');
            $t->unsignedBigInteger('coordinator_id')->nullable();
            $t->string('coordinator_assignment')->nullable();
            $t->unsignedInteger('epoch')->default(0);
            $t->unsignedBigInteger('sequence')->default(0);
            $t->unsignedInteger('desired_version')->default(0);
            $t->unsignedInteger('applied_version')->default(0);
            $t->unsignedInteger('staged_version')->nullable();
            $t->uuid('activation_id')->nullable();
            $t->string('activation_state')->default('idle');
            $t->timestamps();
        });
        Schema::create('pos_kv2_policies', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            // Zero denotes company default; otherwise a validated company branch.
            $t->unsignedBigInteger('branch_scope')->default(0);
            $t->string('source');
            $t->string('release_mode');
            $t->unique(['company_id', 'branch_scope', 'source']);
            $t->timestamps();
        });
        Schema::create('pos_kv2_configurations', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedInteger('version');
            $t->json('bundle');
            $t->string('bundle_hash', 64);
            $t->string('actor');
            $t->timestamp('created_at');
            $t->unique(['company_id', 'branch_id', 'version']);
        });
        Schema::create('pos_kv2_devices', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id');
            $t->unsignedBigInteger('device_id')->unique();
            $t->string('assignment');
            $t->string('certificate_thumbprint', 64);
            $t->json('areas');
            $t->unsignedInteger('protocol_version')->default(1);
            $t->boolean('enabled')->default(true);
            $t->timestamps();
        });
        Schema::create('pos_kv2_submissions', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id');
            $t->uuid('uuid');
            $t->unique(['company_id', 'branch_id', 'uuid']);
            $t->string('source_key');
            $t->unique(['company_id', 'branch_id', 'source_key']);
            $t->uuid('order_uuid');
            $t->uuid('round_uuid');
            $t->unique(['company_id', 'branch_id', 'order_uuid', 'round_uuid'], 'kv2_order_round_unique');
            $t->unsignedBigInteger('order_id')->nullable();
            $t->unsignedBigInteger('round_id')->nullable();
            $t->string('link_state')->default('pending');
            $t->string('source');
            $t->unsignedBigInteger('origin_device_id');
            $t->string('origin_assignment');
            $t->unsignedInteger('revision');
            $t->unsignedInteger('policy_version');
            $t->string('release_mode');
            $t->string('state');
            $t->timestamp('released_at')->nullable();
            $t->timestamp('ready_at')->nullable();
            $t->uuid('ready_cycle')->nullable();
            $t->timestamp('handed_over_at')->nullable();
            $t->string('handover_kind')->nullable();
            $t->json('context');
            $t->timestamps();
            $t->index(['company_id', 'branch_id', 'order_uuid']);
        });
        Schema::create('pos_kv2_revisions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('submission_id')->constrained('pos_kv2_submissions');
            $t->unsignedInteger('revision');
            $t->json('snapshot');
            $t->string('payload_hash', 64);
            $t->timestamp('created_at');
            $t->unique(['submission_id', 'revision']);
        });
        Schema::create('pos_kv2_work', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('submission_id')->constrained('pos_kv2_submissions');
            $t->unsignedInteger('revision');
            $t->uuid('line_uuid');
            $t->uuid('area_uuid');
            $t->string('quantity', 32);
            $t->json('line');
            $t->string('state')->default('outstanding');
            $t->timestamp('done_at')->nullable();
            $t->unsignedBigInteger('done_by')->nullable();
            $t->unique(['submission_id', 'revision', 'line_uuid', 'area_uuid'], 'kv2_work_unique');
        });
        Schema::create('pos_kv2_deliveries', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('submission_id')->constrained('pos_kv2_submissions');
            $t->unsignedInteger('revision');
            $t->uuid('destination_uuid');
            $t->string('purpose');
            // Original/change/cancel use fixed identity; reprints use a distinct audited request UUID.
            $t->string('copy_key')->default('automatic');
            $t->json('snapshot');
            $t->string('state')->default('queued');
            $t->uuid('attempt_uuid')->nullable();
            $t->timestamp('created_at');
            $t->timestamp('updated_at');
            $t->unique(['submission_id', 'revision', 'destination_uuid', 'purpose', 'copy_key'], 'kv2_delivery_unique');
        });
        Schema::create('pos_kv2_attempts', function (Blueprint $t): void {
            $t->id();
            $t->uuid('uuid')->unique();
            $t->foreignId('delivery_id')->constrained('pos_kv2_deliveries');
            $t->unsignedBigInteger('device_id');
            $t->unsignedInteger('epoch');
            $t->string('state');
            $t->json('result')->nullable();
            $t->timestamp('created_at');
            $t->timestamp('updated_at');
        });
        Schema::create('pos_kv2_events', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id');
            $t->uuid('uuid');
            $t->unsignedBigInteger('sequence');
            $t->unsignedBigInteger('device_id');
            $t->unsignedBigInteger('staff_id')->nullable();
            $t->unsignedInteger('epoch');
            $t->string('action');
            $t->string('payload_hash', 64);
            $t->json('payload');
            $t->json('result');
            $t->timestamp('occurred_at');
            $t->timestamp('recorded_at');
            $t->unique(['company_id', 'branch_id', 'uuid']);
            $t->unique(['company_id', 'branch_id', 'sequence']);
        });
        Schema::create('pos_kv2_audit', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('actor');
            $t->string('action');
            $t->json('detail');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        // Roll back executors through the controlled opt-out, not by erasing evidence.
        foreach (['events', 'submissions', 'attempts', 'audit'] as $name) {
            if (DB::table('pos_kv2_'.$name)->exists()) {
                throw new RuntimeException('Kitchen journal retained: suspend and reconcile before any schema removal.');
            }
        }
        foreach (['audit', 'events', 'attempts', 'deliveries', 'work', 'revisions', 'submissions', 'devices', 'configurations', 'policies', 'branches'] as $name) {
            Schema::dropIfExists('pos_kv2_'.$name);
        }
    }
};
