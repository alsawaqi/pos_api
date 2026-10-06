<?php

declare(strict_types=1);

namespace App\Support\Recipes;

use Illuminate\Support\Facades\Schema;

/**
 * Fix order PK-A1 (L2) — whether the packaging add-on schema (pos_admin
 * 2026_10_06_110003 / 110004) is on this database.
 *
 * pos_admin migrates first, but a pos_api deployed before those migrations
 * must still settle every paid sale (selling rule): without the columns it
 * takes stock exactly as before this release — every line, no stamp, no
 * per-order packaging — and logs a warning, instead of failing the pay or
 * hand-off transaction on a missing column.
 *
 * A positive answer is cached for the life of the application instance (a
 * request, or a worker until it restarts); a negative one is re-checked on
 * every use, so the add-on switches on as soon as the migrations land.
 */
final class PackagingSchema
{
    private const CACHE_KEY = 'launch.packaging.schema_ready';

    public static function ready(): bool
    {
        $app = app();
        if ($app->bound(self::CACHE_KEY)) {
            return true;
        }

        $ready = Schema::hasColumns('pos_orders', ['stock_order_type', 'packaging_snapshot_json'])
            && Schema::hasTable('pos_order_packaging_lines');
        if ($ready) {
            $app->instance(self::CACHE_KEY, true);

            return true;
        }

        try {
            logger()->warning('LAUNCH packaging add-on: pos_orders.stock_order_type / pos_order_packaging_lines missing (pos_admin 2026_10_06_1100* not migrated); stock is taken for every line, with no per-order packaging');
        } catch (\Throwable) {
            // Best-effort; a sale never fails over logging.
        }

        return false;
    }
}
