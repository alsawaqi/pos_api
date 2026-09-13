<?php

declare(strict_types=1);

namespace App\Support\SoftPos;

final readonly class SoftPosProfileDto
{
    public function __construct(
        public int $bankId,
        public string $provider,
        public ?string $package,
        public string $currency,
        public bool $refundNeedsTransactionId,
        public bool $voidNeedsSessionId,
    ) {}
}
