<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * LAUNCH-P6 fix order 1 (F-1) — whether pos_payments.staff_id (pos_admin
 * 2026_10_06_120002) is on this database. pos_admin migrates first, but a
 * pos_api deployed before it must still settle every paid sale and close every
 * shift: without the column the payer is simply not recorded (and the
 * shared-shift fallback by payer is skipped), never a failed pay.
 *
 * A positive answer is cached for the life of the application instance; a
 * negative one is re-checked on every use.
 */
final class PaymentStaffSchema
{
    private const CACHE_KEY = 'launch.p6.payment_staff_ready';

    public static function ready(): bool
    {
        $app = app();
        if ($app->bound(self::CACHE_KEY)) {
            return true;
        }
        if (Schema::hasColumn('pos_payments', 'staff_id')) {
            $app->instance(self::CACHE_KEY, true);

            return true;
        }

        return false;
    }
}
