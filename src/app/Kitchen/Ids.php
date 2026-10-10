<?php

declare(strict_types=1);

namespace App\Kitchen;

use Ramsey\Uuid\Uuid;

final class Ids
{
    public static function stable(string $name): string
    {
        return Uuid::uuid5('817bcdb3-3bf0-5cba-8275-34ae3abf2d12', $name)->toString();
    }
}
