<?php

declare(strict_types=1);

namespace App\Support\Catalogue;

use App\Models\Product;
use App\Support\BusinessClock;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * LAUNCH review add-on (owner decision D10, tester call 13) — a product's or
 * combo's limited-time dates: pos_products.on_sale_from / on_sale_until,
 * inclusive calendar dates in the merchant's time zone (Asia/Muscat). NULL
 * means no bound. They are separate from the daily hours (available_from /
 * available_until, 'HH:MM:SS').
 *
 * Out-of-range products are left out of the device config and the QR menu;
 * the QR pricer refuses them (reason 'outside_dates'). A paid device sale of
 * one is never refused.
 */
final class SaleDates
{
    /** The merchant's calendar date of $at (default now): 'YYYY-MM-DD'. */
    public static function day(?DateTimeInterface $at = null): string
    {
        return BusinessClock::local($at)->format('Y-m-d');
    }

    /** A stored date as 'YYYY-MM-DD', or null. */
    public static function format(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return substr((string) $value, 0, 10);
    }

    /** Whether a product with these dates is on sale on $day ('YYYY-MM-DD'). */
    public static function covers(mixed $from, mixed $until, string $day): bool
    {
        $from = self::format($from);
        $until = self::format($until);

        return ($from === null || $from <= $day) && ($until === null || $until >= $day);
    }

    public static function isOnSale(Product $product, ?DateTimeInterface $at = null): bool
    {
        return self::covers($product->on_sale_from ?? null, $product->on_sale_until ?? null, self::day($at));
    }

    /**
     * Restrict a pos_products query to the products on sale on $day.
     *
     * @template TQuery of Builder<Product>|QueryBuilder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function onSale(Builder|QueryBuilder $query, string $day): Builder|QueryBuilder
    {
        return $query
            ->where(fn ($from) => $from->whereNull('pos_products.on_sale_from')->orWhere('pos_products.on_sale_from', '<=', $day))
            ->where(fn ($until) => $until->whereNull('pos_products.on_sale_until')->orWhere('pos_products.on_sale_until', '>=', $day));
    }

    /**
     * The device delta's boundary rule (menu audit §5.3b): a product whose
     * first day came after the cursor's day (and is today or before), or
     * whose last day was on or after the cursor's day and before today, has
     * entered or left the menu without any row moving. OR-ed into the
     * delta's change detection, so it arrives, or leaves through
     * deleted.products.
     *
     * @param  Builder<Product>|QueryBuilder  $query  inside a where() group
     */
    public static function orCrossedSince(Builder|QueryBuilder $query, string $sinceDay, string $today): void
    {
        if ($sinceDay >= $today) {
            return;
        }
        $query->orWhere(fn ($started) => $started->where('pos_products.on_sale_from', '>', $sinceDay)
            ->where('pos_products.on_sale_from', '<=', $today))
            ->orWhere(fn ($ended) => $ended->where('pos_products.on_sale_until', '>=', $sinceDay)
                ->where('pos_products.on_sale_until', '<', $today));
    }
}
