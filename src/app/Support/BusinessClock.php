<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * LAUNCH-P4 H9 — the merchant's wall clock. The app runs in UTC, but every
 * daily product window ("07:00–11:00"), offer and discount window and
 * weekday is the merchant's local time: pos.business_timezone (Asia/Muscat).
 * Same instant, local wall-clock fields.
 */
final class BusinessClock
{
    public static function zone(): DateTimeZone
    {
        return new DateTimeZone((string) config('pos.business_timezone', 'Asia/Muscat'));
    }

    /** $at (default now) as the merchant's local time. */
    public static function local(?DateTimeInterface $at = null): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($at ?? now())->setTimezone(self::zone());
    }
}
