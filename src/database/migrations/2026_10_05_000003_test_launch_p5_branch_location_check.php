<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P5 add-on — TEST-ONLY mirror of pos_admin's
 * 2026_10_05_100013_add_location_check_to_pos_branches:
 * pos_branches.location_check_enabled (NOT NULL, default true). The `testing`
 * guard keeps it off the real shared database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        Schema::table('pos_branches', function (Blueprint $table): void {
            $table->boolean('location_check_enabled')->default(true);
        });
    }

    public function down(): void
    {
        if (! app()->environment('testing')) {
            return;
        }

        Schema::table('pos_branches', function (Blueprint $table): void {
            $table->dropColumn('location_check_enabled');
        });
    }
};
