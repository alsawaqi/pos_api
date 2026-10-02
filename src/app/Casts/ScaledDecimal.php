<?php

declare(strict_types=1);

namespace App\Casts;

use App\Support\StockDecimal;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * LAUNCH-P2 P2-1 — a decimal attribute read with at least $min and at most
 * $max decimals ("6.000", "0.0003"), written at the column's full scale.
 * Usage: ScaledDecimal::class.':3,4' (quantities), ':3,6' (unit costs).
 *
 * @implements CastsAttributes<string|null, string|float|int|null>
 */
final class ScaledDecimal implements CastsAttributes
{
    private readonly int $min;

    private readonly int $max;

    public function __construct(int|string $min = 3, int|string $max = 4)
    {
        $this->min = (int) $min;
        $this->max = (int) $max;
    }

    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return StockDecimal::format($value, $this->min, $this->max);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return StockDecimal::format($value, $this->max, $this->max);
    }
}
