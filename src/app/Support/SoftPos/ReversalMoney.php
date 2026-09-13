<?php

declare(strict_types=1);

namespace App\Support\SoftPos;

/** Positive rational allocation without floating money or overflowing a*b. */
final class ReversalMoney
{
    public static function fraction(int $amount, int $part, int $whole): array
    {
        if ($amount < 0 || $part < 0 || $whole <= 0 || $part > $whole) {
            throw new \InvalidArgumentException('Invalid allocation ratio.');
        }
        $quotient = 0;
        $remainder = 0;
        foreach (str_split(decbin($amount)) as $bit) {
            $quotient *= 2;
            if ($remainder >= $whole - $remainder) {
                $remainder -= $whole - $remainder;
                $quotient++;
            } else {
                $remainder *= 2;
            }
            if ($bit === '1') {
                if ($remainder >= $whole - $part) {
                    $remainder -= $whole - $part;
                    $quotient++;
                } else {
                    $remainder += $part;
                }
            }
        }

        return [$quotient, $remainder];
    }

    /** Stable largest-remainder allocation, tie broken by order-item id. */
    public static function allocate(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        if ($total <= 0) {
            return array_fill_keys(array_keys($weights), 0);
        }
        ksort($weights, SORT_NUMERIC);
        $result = [];
        $remainders = [];
        foreach ($weights as $id => $weight) {
            [$result[$id], $remainders[$id]] = self::fraction($amount, $weight, $total);
        }
        arsort($remainders, SORT_NUMERIC);
        $left = $amount - array_sum($result);
        foreach ($remainders as $id => $remainder) {
            if ($left-- <= 0) {
                break;
            }
            $result[$id]++;
        }

        return $result;
    }
}
