<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Tablet;

/** LAUNCH-P6 — `POST device/tablet/quote`: { order_type, lines[] }. */
final class TabletQuoteRequest extends TabletLinesRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::tabletLineRules();
    }
}
