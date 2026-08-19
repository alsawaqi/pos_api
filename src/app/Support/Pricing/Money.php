<?php

declare(strict_types=1);

namespace App\Support\Pricing;

/** Integer-baisa boundaries and the device-compatible 3-decimal OMR math. */
final class Money
{
    public static function roundOmr(float $value): float
    {
        return round($value, 3);
    }

    public static function omrToBaisas(float $omr): int
    {
        return (int) round($omr * 1000);
    }

    public static function baisasToOmr(int $baisas): float
    {
        return $baisas / 1000.0;
    }

    /**
     * @param  list<float|int>  $weights
     * @return list<int>
     */
    public static function allocateBaisas(array $weights, int $totalBaisas): array
    {
        $parts = array_fill(0, count($weights), 0);
        $weightSum = array_sum($weights);
        if ($weightSum <= 0) {
            return $parts;
        }

        $allocated = 0;
        $shares = [];
        foreach ($weights as $index => $weight) {
            $exact = $totalBaisas * ((float) $weight / $weightSum);
            $floor = (int) floor($exact);
            $allocated += $floor;
            $shares[] = [
                'index' => $index,
                'floor' => $floor,
                'remainder' => $exact - $floor,
            ];
        }

        $leftover = $totalBaisas - $allocated;
        usort(
            $shares,
            static fn (array $a, array $b): int => $b['remainder'] <=> $a['remainder'],
        );
        foreach ($shares as $share) {
            $baisas = $share['floor'];
            if ($leftover > 0) {
                $baisas++;
                $leftover--;
            }
            $parts[$share['index']] = $baisas;
        }

        return $parts;
    }
}
