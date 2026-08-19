<?php

declare(strict_types=1);

namespace App\Support\Pricing;

use DateTimeImmutable;

final class Applicability
{
    public static function ruleAppliesAt(DiscountRule $rule, DateTimeImmutable $now, int $branchId): bool
    {
        return self::matchesWindow(
            $now,
            $rule->isActive,
            $rule->validityStart,
            $rule->validityEnd,
            $rule->dayOfWeekMask,
            $rule->timeStart,
            $rule->timeEnd,
            $rule->branchScope,
            $branchId,
        );
    }

    public static function offerAppliesAt(OfferSpec $offer, DateTimeImmutable $now, int $branchId): bool
    {
        return self::matchesWindow(
            $now,
            $offer->isActive,
            $offer->validityStart,
            $offer->validityEnd,
            $offer->dayOfWeekMask,
            $offer->timeStart,
            $offer->timeEnd,
            $offer->branchScope,
            $branchId,
        );
    }

    /** @param list<int> $branchScope */
    private static function matchesWindow(
        DateTimeImmutable $now,
        bool $isActive,
        ?DateTimeImmutable $validityStart,
        ?DateTimeImmutable $validityEnd,
        ?int $dayOfWeekMask,
        ?string $timeStart,
        ?string $timeEnd,
        array $branchScope,
        int $branchId,
    ): bool {
        if (! $isActive) {
            return false;
        }
        if ($validityStart !== null && $now < $validityStart) {
            return false;
        }
        if ($validityEnd !== null && $now > $validityEnd) {
            return false;
        }

        $mask = $dayOfWeekMask ?? 127;
        if (($mask & (1 << (int) $now->format('w'))) === 0) {
            return false;
        }

        if ($timeStart !== null || $timeEnd !== null) {
            $hhmmss = $now->format('H:i:s');
            $start = $timeStart ?? '00:00:00';
            $end = $timeEnd ?? '23:59:59';
            $inWindow = strcmp($start, $end) <= 0
                ? strcmp($hhmmss, $start) >= 0 && strcmp($hhmmss, $end) <= 0
                : strcmp($hhmmss, $start) >= 0 || strcmp($hhmmss, $end) <= 0;
            if (! $inWindow) {
                return false;
            }
        }

        return $branchScope === [] || in_array($branchId, $branchScope, true);
    }
}
