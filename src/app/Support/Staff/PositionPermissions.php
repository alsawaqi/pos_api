<?php

declare(strict_types=1);

namespace App\Support\Staff;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * LAUNCH-P5 data contract — the tick list per position (owner decision 1),
 * the ONE resolver pos_api uses (pos_merchant has its own, both tested
 * against the shared fixture).
 *
 * Stored as the pos_company_settings key `position_permissions`:
 *
 *   { "<position>": { "actions": { "<key>": true|false, ... },
 *                     "discount_max_percent": 0..100 }, ... }
 *
 * Resolution: every one of the 5 positions × 19 actions and every limit
 * that is missing or malformed resolves to the fixed defaults
 * (resources/staff/position_permissions_defaults.json, a byte copy of the
 * shared fixture). The kitchen position always has kitchen.screen.
 *
 * A company with no `position_permissions` row at all (written before the
 * LAUNCH-P5 data migration ran, or a test) resolves exactly like that
 * migration would have written it: the defaults plus today's
 * manager_approval_positions → approvals.give, reports_positions →
 * reports.view and kitchen_positions → kitchen.screen. order_cancel_positions
 * is never mapped.
 */
final class PositionPermissions
{
    public const KEY = 'position_permissions';

    /** Old list key => the action it maps to (order_cancel_positions is not mapped). */
    public const OLD_KEYS = [
        'manager_approval_positions' => 'approvals.give',
        'reports_positions' => 'reports.view',
        'kitchen_positions' => 'kitchen.screen',
    ];

    /** @var array<string, mixed>|null */
    private static ?array $fixture = null;

    /** @return array<string, mixed> the decoded defaults file (immutable, safe to keep per process) */
    public static function fixture(): array
    {
        if (self::$fixture === null) {
            $raw = file_get_contents(resource_path('staff/position_permissions_defaults.json'));
            if ($raw === false) {
                throw new RuntimeException('position_permissions_defaults.json is missing');
            }
            self::$fixture = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        }

        return self::$fixture;
    }

    /** @return list<string> */
    public static function positions(): array
    {
        return self::fixture()['positions'];
    }

    /** @return list<string> */
    public static function actions(): array
    {
        return self::fixture()['actions'];
    }

    /** @return array<string, array{actions: array<string, bool>, discount_max_percent: int}> */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::positions() as $position) {
            $row = self::fixture()['defaults'][$position];
            $actions = [];
            foreach (self::actions() as $action) {
                $actions[$action] = (bool) $row['actions'][$action];
            }
            $out[$position] = ['actions' => $actions, 'discount_max_percent' => (int) $row['discount_max_percent']];
        }

        return $out;
    }

    /**
     * The resolved matrix of one company (all positions and actions, in the
     * fixture's order).
     *
     * @return array<string, array{actions: array<string, bool>, discount_max_percent: int}>
     */
    public function forCompany(int $companyId): array
    {
        $rows = DB::table('pos_company_settings')
            ->where('company_id', $companyId)
            ->whereIn('key', [self::KEY, ...array_keys(self::OLD_KEYS)])
            ->pluck('value', 'key')
            ->all();

        if (array_key_exists(self::KEY, $rows)) {
            return self::resolve($rows[self::KEY]);
        }

        return self::fromOldLists(array_intersect_key($rows, self::OLD_KEYS));
    }

    public function allows(int $companyId, ?string $position, string $action): bool
    {
        $matrix = $this->forCompany($companyId);

        return $position !== null && isset($matrix[$position]) && ($matrix[$position]['actions'][$action] ?? false) === true;
    }

    public function discountMaxPercent(int $companyId, ?string $position): int
    {
        $matrix = $this->forCompany($companyId);

        return $position !== null && isset($matrix[$position]) ? $matrix[$position]['discount_max_percent'] : 0;
    }

    /** @return list<string> the positions holding the action, in the fixture's order */
    public function positionsWith(int $companyId, string $action): array
    {
        return self::holders($this->forCompany($companyId), $action);
    }

    /**
     * @param  array<string, array{actions: array<string, bool>, discount_max_percent: int}>  $matrix
     * @return list<string>
     */
    public static function holders(array $matrix, string $action): array
    {
        $out = [];
        foreach ($matrix as $position => $row) {
            if (($row['actions'][$action] ?? false) === true) {
                $out[] = $position;
            }
        }

        return $out;
    }

    /**
     * Resolve a stored value (JSON text or a decoded array) against the
     * defaults: a known position/action/limit with a valid value wins,
     * anything else keeps its default.
     *
     * @return array<string, array{actions: array<string, bool>, discount_max_percent: int}>
     */
    public static function resolve(mixed $stored): array
    {
        $value = is_string($stored) ? json_decode($stored, true) : $stored;
        $matrix = self::defaults();
        if (! is_array($value)) {
            return $matrix;
        }

        foreach (self::positions() as $position) {
            $row = $value[$position] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $actions = $row['actions'] ?? null;
            if (is_array($actions)) {
                foreach (self::actions() as $action) {
                    if (is_bool($actions[$action] ?? null)) {
                        $matrix[$position]['actions'][$action] = $actions[$action];
                    }
                }
            }
            $max = $row['discount_max_percent'] ?? null;
            if ((is_int($max) || is_float($max)) && $max >= 0 && $max <= 100) {
                $matrix[$position]['discount_max_percent'] = (int) floor($max);
            }
        }
        $matrix['kitchen']['actions']['kitchen.screen'] = true;

        return $matrix;
    }

    /**
     * The matrix the LAUNCH-P5 data migration writes for a company from its
     * old lists (pos_admin 2026_10_04_100007): a listed position gets the
     * action, an unlisted one loses it; a missing, malformed or
     * known-position-free list keeps the default ("managers only" today); a
     * present but empty kitchen list means "kitchen only".
     *
     * @param  array<string, mixed>  $lists  old key => raw value
     * @return array<string, array{actions: array<string, bool>, discount_max_percent: int}>
     */
    public static function fromOldLists(array $lists): array
    {
        $matrix = self::defaults();
        foreach (self::OLD_KEYS as $key => $action) {
            if (! array_key_exists($key, $lists)) {
                continue;
            }
            $raw = $lists[$key];
            $value = is_string($raw) ? json_decode($raw, true) : $raw;
            if (! is_array($value)) {
                continue;
            }
            $listed = array_values(array_intersect(self::positions(), array_map(
                static fn ($p): string => is_string($p) ? trim($p) : '',
                $value,
            )));
            if ($listed === [] && $action !== 'kitchen.screen') {
                continue;
            }
            foreach (self::positions() as $position) {
                $matrix[$position]['actions'][$action] = in_array($position, $listed, true)
                    || ($action === 'kitchen.screen' && $position === 'kitchen');
            }
        }

        return $matrix;
    }
}
