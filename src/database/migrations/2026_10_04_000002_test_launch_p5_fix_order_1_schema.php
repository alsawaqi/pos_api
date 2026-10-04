<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P5 fix order 1 — TEST-ONLY mirror of pos_admin's
 * 2026_10_04_100010..100012: pos_devices.auth_v_seen_at (F2, the sticky P5
 * marker), pos_expenses.shift_id (F6, a pay-out's drawer shift) and
 * pos_shifts.late_payouts_baisas (F7). Plain integer references, like the
 * rest of this test schema. The `testing` guard keeps it off the real shared
 * database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        Schema::table('pos_devices', function (Blueprint $table): void {
            $table->timestamp('auth_v_seen_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        Schema::table('pos_devices', function (Blueprint $table): void {
            $table->dropColumn('auth_v_seen_at');
        });
    }
};
