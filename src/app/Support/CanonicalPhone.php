<?php

declare(strict_types=1);

namespace App\Support;

final class CanonicalPhone
{
    public static function of(?string $phone): ?string
    {
        $digits = strtr($phone ?? '', array_combine(
            preg_split('//u', '٠١٢٣٤٥٦٧٨٩۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY),
            str_split('01234567890123456789'),
        ));
        $digits = (string) preg_replace('/[^0-9]/', '', $digits);
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        if (strlen($digits) < 8) {
            return null;
        }

        return strlen($digits) === 8 ? '968'.$digits : $digits;
    }

    public static function same(string $a, string $b): bool
    {
        $canonical = self::of($a);

        return $canonical !== null ? $canonical === self::of($b) : $a === $b;
    }

    public static function isPhoneQuery(string $query): bool
    {
        return preg_match('/^[0-9٠-٩۰-۹ +()\-]+$/u', $query) === 1 && self::of($query) !== null;
    }
}
