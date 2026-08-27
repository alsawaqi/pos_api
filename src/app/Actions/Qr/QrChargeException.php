<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use RuntimeException;

/** A stable device-facing charge-protocol refusal. */
final class QrChargeException extends RuntimeException
{
    public function __construct(
        public readonly string $codeName,
        public readonly int $httpStatus,
        string $message,
    ) {
        parent::__construct($message);
    }
}
