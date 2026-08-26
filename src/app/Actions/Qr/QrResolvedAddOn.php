<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\AddOn;

final readonly class QrResolvedAddOn
{
    public function __construct(
        public AddOn $addon,
        public int $priceDeltaBaisas,
    ) {}
}
