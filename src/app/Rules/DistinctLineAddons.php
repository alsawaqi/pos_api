<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Distinct belongs to one line, not Laravel's flattened nested wildcard. */
final class DistinctLineAddons implements ValidationRule
{
    public function __construct(private readonly bool $strict = false) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return; // The existing array rule owns malformed input.
        }
        $seen = [];
        foreach ($value as $id) {
            if (in_array($id, $seen, $this->strict)) {
                $fail('validation.distinct')->translate();

                return;
            }
            $seen[] = $id;
        }
    }
}
