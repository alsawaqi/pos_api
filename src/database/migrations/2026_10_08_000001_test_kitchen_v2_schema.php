<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            (require base_path('tests/Fixtures/migrations/2026_10_08_100001_create_kitchen_v2_schema.php'))->up();
        }
    }

    public function down(): void
    {
        if (app()->environment('testing')) {
            (require base_path('tests/Fixtures/migrations/2026_10_08_100001_create_kitchen_v2_schema.php'))->down();
        }
    }
};
