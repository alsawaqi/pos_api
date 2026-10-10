<?php

declare(strict_types=1);

namespace App\Kitchen;

use Root23\JsonCanonicalizer\JsonCanonicalizer;

final class Wire
{
    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function read(?string $value): array
    {
        return $value === null ? [] : json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }

    public static function hash(array $value): string
    {
        return hash('sha256', (new JsonCanonicalizer)->canonicalize($value));
    }
}
