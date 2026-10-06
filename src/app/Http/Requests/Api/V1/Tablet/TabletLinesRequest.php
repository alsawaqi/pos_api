<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Tablet;

use App\Http\Requests\Api\V1\PublicQr\Concerns\RejectsClientPricing;
use App\Http\Requests\Api\V1\PublicQr\PublicQrRequest;
use App\Http\Requests\Api\V1\PublicQr\QuoteQrRequest;
use Illuminate\Validation\Rule;

/**
 * LAUNCH-P6 — the customer tablet's cart lines: the QR line shape
 * (`QrQuickLine`: product_id, qty, addon_ids — extras, remove and
 * instruction tap lists alike — and combo picks), server-priced only (any
 * price-bearing key is refused, 422 client_priced_payload_rejected), and
 * with no free-text note (tester call 5: the note is the tap lists only; a
 * non-empty `notes` is a 422 validation_failed, null or absent is fine).
 */
abstract class TabletLinesRequest extends PublicQrRequest
{
    use RejectsClientPricing;

    /** @return array<string, mixed> */
    protected static function tabletLineRules(): array
    {
        return array_replace(QuoteQrRequest::lineRules(), [
            'order_type' => ['required', 'string', Rule::in(['dine_in', 'quick', 'to_go'])],
            'lines.*.notes' => ['sometimes', 'nullable', 'string', 'max:0'],
            'lines.*.combo.*.notes' => ['sometimes', 'nullable', 'string', 'max:0'],
        ]);
    }
}
