<?php

declare(strict_types=1);

namespace App\Support\Pricing;

final class Split
{
    public static function equalShareBaisas(int $grandTotalBaisas, int $splitCount): int
    {
        return $splitCount <= 1 ? $grandTotalBaisas : (int) round($grandTotalBaisas / $splitCount);
    }

    public static function remainderShareBaisas(int $grandTotalBaisas, int $paidBaseBaisas): int
    {
        return max(0, $grandTotalBaisas - $paidBaseBaisas);
    }

    /**
     * @param  list<int>  $sharesBaisas
     * @return list<int>|null
     */
    public static function validateSplitPlan(array $sharesBaisas, int $grandTotalBaisas): ?array
    {
        if (count($sharesBaisas) < 2) {
            return null;
        }
        $shares = array_values($sharesBaisas);
        $head = array_slice($shares, 0, -1);
        if (array_any($head, static fn (int $amount): bool => $amount < 1)) {
            return null;
        }
        $remainder = $grandTotalBaisas - array_sum($head);
        if ($remainder < 1) {
            return null;
        }
        $shares[array_key_last($shares)] = $remainder;

        return $shares;
    }

    /** @param list<int> $planBaisas */
    public static function splitPlanMatchesTotal(array $planBaisas, int $grandTotalBaisas): bool
    {
        return array_sum($planBaisas) === $grandTotalBaisas;
    }
}
