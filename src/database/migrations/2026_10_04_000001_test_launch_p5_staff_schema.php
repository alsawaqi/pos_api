<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P5 — TEST-ONLY mirror of pos_admin's 2026_10_04_100001..100006
 * (staff verifier columns, pos_staff_branches, pos_approvals, the order void
 * staff, the shift close / pay-out columns, pos_expenses.paid_from_drawer and
 * pos_staff_attendance). Plain integer references, like the rest of this
 * test schema. The `testing` guard keeps it off the real shared database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        Schema::table('pos_staff', function (Blueprint $table): void {
            $table->text('pin_offline_key')->nullable();
            $table->string('pin_offline_salt', 64)->nullable();
            $table->integer('pin_offline_iterations')->nullable();
        });

        Schema::create('pos_staff_branches', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('staff_id');
            $table->unsignedBigInteger('branch_id');
            $table->timestamps();
            $table->unique(['staff_id', 'branch_id'], 'pos_staff_branches_staff_branch_unique');
            $table->index(['company_id', 'branch_id'], 'pos_staff_branches_company_branch_idx');
        });

        Schema::create('pos_approvals', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->string('client_event_id', 64)->nullable();
            $table->string('action', 32);
            $table->string('subject_type', 32);
            $table->string('subject_uuid', 64)->nullable();
            $table->decimal('amount', 12, 3)->nullable();
            $table->string('ref', 64)->nullable();
            $table->unsignedBigInteger('actor_staff_id')->nullable();
            $table->unsignedBigInteger('approver_staff_id')->nullable();
            $table->string('mode', 16);
            $table->string('method', 16)->nullable();
            $table->timestamp('approved_at', 3)->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('result', 16);
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['device_id', 'client_event_id'], 'pos_approvals_device_event_idx');
        });

        Schema::table('pos_orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('voided_by_staff_id')->nullable();
            $table->unsignedBigInteger('void_approved_by_staff_id')->nullable();
        });

        Schema::table('pos_shifts', function (Blueprint $table): void {
            $table->unsignedBigInteger('closed_by_staff_id')->nullable();
            $table->unsignedBigInteger('close_device_id')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->bigInteger('late_sales_baisas')->default(0);
            $table->bigInteger('payouts_baisas')->default(0);
        });

        Schema::table('pos_expenses', function (Blueprint $table): void {
            $table->boolean('paid_from_drawer')->default(false);
        });

        Schema::create('pos_staff_attendance', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('branch_id');
            $table->unsignedBigInteger('staff_id');
            $table->unsignedBigInteger('device_id')->nullable();
            $table->timestamp('clock_in_at');
            $table->timestamp('clock_out_at')->nullable();
            $table->string('source', 16)->default('device');
            $table->unsignedBigInteger('edited_by_user_id')->nullable();
            $table->text('edit_reason')->nullable();
            $table->json('flags')->nullable();
            $table->timestamps();
            $table->index(['company_id', 'staff_id', 'clock_in_at'], 'pos_staff_attendance_company_staff_in_idx');
        });
    }

    public function down(): void
    { /* Test-only schema; the test database is discarded. */
    }
};
