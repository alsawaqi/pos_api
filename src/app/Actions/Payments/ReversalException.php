<?php

declare(strict_types=1);

namespace App\Actions\Payments;

use RuntimeException;

final class ReversalException extends RuntimeException
{
    public function __construct(public readonly string $codeName, public readonly int $httpStatus = 409)
    {
        parent::__construct(str_replace('_', ' ', $codeName));
    }
}
