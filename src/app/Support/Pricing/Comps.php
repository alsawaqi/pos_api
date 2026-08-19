<?php

declare(strict_types=1);

namespace App\Support\Pricing;

final class Comps
{
    public static function giftAmountBaisas(PricingLine $line, int $lineDiscountBaisas): int
    {
        return $line->gifted ? max(0, $line->lineTotalBaisas() - $lineDiscountBaisas) : 0;
    }

    /**
     * @param  list<PricingLine>  $lines
     * @param  array<int, int>  $lineDiscountByIndex
     * @return array<int, int>
     */
    public static function giftAmountsBaisasFor(array $lines, array $lineDiscountByIndex): array
    {
        $gifts = [];
        foreach ($lines as $index => $line) {
            $amount = self::giftAmountBaisas($line, $lineDiscountByIndex[$index] ?? 0);
            if ($amount > 0) {
                $gifts[$index] = $amount;
            }
        }

        return $gifts;
    }

    /**
     * @param  list<PricingLine>  $lines
     * @param  array<int, int>  $lineDiscountByIndex
     */
    public static function compTotalBaisasFor(
        array $lines,
        array $lineDiscountByIndex,
        int $giftedTotalBaisas,
        int $subtotalBaisas,
        ?CompSelection $comp,
    ): int {
        $gifts = $giftedTotalBaisas;
        if ($comp === null) {
            return min(max($gifts, 0), $subtotalBaisas);
        }

        $lineIndex = $comp->lineIndex;
        if ($lineIndex === null) {
            $managerPart = max(0, $subtotalBaisas - $gifts);
        } elseif ($lineIndex < 0 || $lineIndex >= count($lines)) {
            $managerPart = 0;
        } else {
            $line = $lines[$lineIndex];
            if ($line->gifted) {
                $managerPart = 0;
            } else {
                $net = max(0, $line->lineTotalBaisas() - ($lineDiscountByIndex[$lineIndex] ?? 0));
                $lineQty = $line->qty;
                if ($net === 0 || $lineQty <= 0) {
                    $managerPart = 0;
                } else {
                    $compQty = $comp->qty === null ? $lineQty : min(max($comp->qty, 1), $lineQty);
                    $managerPart = $compQty === $lineQty
                        ? $net
                        : intdiv((2 * $net * $compQty) + $lineQty, 2 * $lineQty);
                }
            }
        }

        return min(max($managerPart + $gifts, 0), $subtotalBaisas);
    }

    public static function managerCompBaisasFor(int $compTotalBaisas, int $giftedTotalBaisas, int $subtotalBaisas): int
    {
        return min(max($compTotalBaisas - $giftedTotalBaisas, 0), $subtotalBaisas);
    }

    /**
     * @param  array<int, int>  $giftAmountsBaisas
     * @return list<CompWireRow>
     */
    public static function compWireRowsFor(array $giftAmountsBaisas, int $compTotalBaisas, ?CompSelection $comp = null): array
    {
        $rows = [];
        $remaining = $compTotalBaisas;
        ksort($giftAmountsBaisas, SORT_NUMERIC);
        foreach ($giftAmountsBaisas as $lineIndex => $faceAmount) {
            $take = min(max($faceAmount, 0), $remaining);
            if ($take > 0) {
                $rows[] = new CompWireRow($take, true, (int) $lineIndex);
                $remaining -= $take;
            }
        }
        if ($remaining > 0) {
            $rows[] = new CompWireRow($remaining, false, $comp?->lineIndex, $comp?->reasonId);
        }

        return $rows;
    }
}
