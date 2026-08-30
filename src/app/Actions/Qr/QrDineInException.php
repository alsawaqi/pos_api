<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use RuntimeException;

/** A stable refusal from an authenticated dine-in QR operation. */
final class QrDineInException extends RuntimeException
{
    public function __construct(
        public readonly string $codeName,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }
}
