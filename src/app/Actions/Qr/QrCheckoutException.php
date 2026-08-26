<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use RuntimeException;

final class QrCheckoutException extends RuntimeException
{
    public function __construct(
        public readonly string $codeName,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }
}
