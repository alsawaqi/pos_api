<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The one serial-number normalisation rule (LAUNCH-P1 decision 1a), applied
 * to BOTH sides of every comparison: trim, remove all whitespace, upper-case.
 * TWIN of pos_admin app/Support/DeviceSerial.php — keep the rule identical.
 */
final class DeviceSerial
{
    public static function normalize(?string $serial): ?string
    {
        if ($serial === null) {
            return null;
        }
        $normal = mb_strtoupper((string) preg_replace('/\s+/u', '', trim($serial)));

        return $normal === '' ? null : $normal;
    }

    /** Last four characters only, for refusal records and admin display. */
    public static function mask(?string $serial): ?string
    {
        $normal = self::normalize($serial);
        if ($normal === null) {
            return null;
        }
        $length = mb_strlen($normal);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', min($length - 4, 8)).mb_substr($normal, -4);
    }

    public static function hash(?string $serial): ?string
    {
        $normal = self::normalize($serial);

        return $normal === null ? null : hash('sha256', $normal);
    }
}
