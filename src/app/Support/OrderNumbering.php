<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * P-F8 — merchant-defined order numbering: the shared read/normalise layer.
 *
 * The merchant portal writes the policy to pos_company_settings under key
 * `order_numbering` as JSON:
 *
 *   {enabled: bool, prefix: string(<=8, may be ''), pad: int 3..6,
 *    scope: 'branch'|'company', daily_reset: bool}
 *
 * Two consumers:
 *   - {@see \App\Actions\Device\BuildDeviceConfigAction} emits the
 *     normalised block as `settings.order_numbering` (full + delta) so the
 *     device knows whether/how to number (and how to format its OFFLINE
 *     local-counter fallback);
 *   - {@see \App\Actions\Device\AllocateOrderNumberAction} reads it to pick
 *     the scope row and format the allocated number.
 *
 * normalise() always returns the FULL five-key shape (missing/invalid keys
 * fall back to the defaults below) so the device parses one stable schema —
 * a company with no row gets {enabled: false, prefix: '', pad: 4,
 * scope: 'branch', daily_reset: false}.
 */
final class OrderNumbering
{
    public const KEY = 'order_numbering';

    public const SCOPE_BRANCH = 'branch';

    public const SCOPE_COMPANY = 'company';

    public const DEFAULT_PAD = 4;

    /**
     * @return array{enabled: bool, prefix: string, pad: int, scope: string, daily_reset: bool}
     */
    public static function forCompany(int $companyId): array
    {
        $raw = DB::table('pos_company_settings')
            ->where('company_id', $companyId)
            ->where('key', self::KEY)
            ->value('value');

        $value = is_string($raw) ? json_decode($raw, true) : $raw;

        return self::normalise(is_array($value) ? $value : []);
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array{enabled: bool, prefix: string, pad: int, scope: string, daily_reset: bool}
     */
    public static function normalise(array $value): array
    {
        $pad = (int) ($value['pad'] ?? self::DEFAULT_PAD);

        return [
            'enabled' => (bool) ($value['enabled'] ?? false),
            'prefix' => mb_substr(trim((string) ($value['prefix'] ?? '')), 0, 8),
            'pad' => max(3, min(6, $pad)),
            'scope' => ($value['scope'] ?? self::SCOPE_BRANCH) === self::SCOPE_COMPANY
                ? self::SCOPE_COMPANY
                : self::SCOPE_BRANCH,
            'daily_reset' => (bool) ($value['daily_reset'] ?? false),
        ];
    }

    /**
     * prefix + zero-padded counter, e.g. prefix "KLD-", pad 4, 42 → "KLD-0042".
     * A counter that outgrows the pad keeps its full digits (pad 3, 1234 →
     * "1234") — numbers are never truncated.
     *
     * @param  array{prefix: string, pad: int}  $setting
     */
    public static function format(array $setting, int $number): string
    {
        return $setting['prefix'].str_pad((string) $number, $setting['pad'], '0', STR_PAD_LEFT);
    }
}
