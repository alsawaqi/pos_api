<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * LAUNCH-P6 (tester call 15) — what a device build declares it understands.
 * Old till and handheld builds break their whole list on one unknown row,
 * so customer tablet rows (attention keys, pending rounds on the table
 * board, the accepted-round feed, the tablet list) are sent only to a device
 * whose request carries
 *
 *   X-Pos-Capabilities: tablet-orders
 *
 * (a comma- or space-separated list of tokens, case-insensitive).
 */
final class DeviceCapabilities
{
    public const HEADER = 'X-Pos-Capabilities';

    public const TABLET_ORDERS = 'tablet-orders';

    public static function has(?Request $request, string $capability): bool
    {
        if ($request === null) {
            return false;
        }
        $raw = $request->header(self::HEADER);
        if (! is_string($raw) || $raw === '' || strlen($raw) > 512) {
            return false;
        }
        $tokens = preg_split('/[\s,]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return in_array(strtolower($capability), $tokens, true);
    }

    public static function tabletOrders(?Request $request): bool
    {
        return self::has($request, self::TABLET_ORDERS);
    }
}
