<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('testing')) {
            return;
        }
        Schema::create('pos_audit_logs', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('actor_user_id')->nullable();
            $t->unsignedBigInteger('company_id')->nullable();
            $t->unsignedBigInteger('branch_id')->nullable();
            $t->string('event');
            $t->string('auditable_type')->nullable();
            $t->unsignedBigInteger('auditable_id')->nullable();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent')->nullable();
            $t->json('old_values')->nullable();
            $t->json('new_values')->nullable();
            $t->json('metadata')->nullable();
            // Exactly like the real pos_audit_logs (pos_admin): created_at
            // only. A writer that also sends updated_at fails on Postgres
            // (found on the T3, 2026-10-03), so the test table must refuse it.
            $t->timestamp('created_at')->nullable();
        });
        Schema::create('pos_sync_event_reviews', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('sync_event_id')->unique();
            $t->unsignedBigInteger('actor_user_id');
            $t->unsignedBigInteger('company_id');
            $t->unsignedBigInteger('branch_id');
            $t->string('fingerprint', 64);
            $t->text('reason');
            $t->jsonb('device_snapshot');
            $t->string('status')->default('attributed');
            $t->timestamps();
        });
    }

    public function down(): void
    { /* Preserve attribution and audit evidence. */
    }
};
