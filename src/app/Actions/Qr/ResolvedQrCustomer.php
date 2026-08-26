<?php

declare(strict_types=1);

namespace App\Actions\Qr;

final readonly class ResolvedQrCustomer
{
    public function __construct(
        public int $customerId,
        public ?string $plateNumber,
    ) {}
}
