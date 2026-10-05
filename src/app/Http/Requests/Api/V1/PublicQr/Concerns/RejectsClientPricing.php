<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\PublicQr\Concerns;

use App\Http\Requests\Api\V1\PublicQr\PublicQrRequest;
use App\Support\Orders\OneLineNote;
use App\Support\QrApiResponse;
use Illuminate\Http\Exceptions\HttpResponseException;

trait RejectsClientPricing
{
    /** @var list<string> */
    private const PRICE_BEARING_KEYS = [
        'price',
        'price_baisas',
        'price_display',
        'base_price',
        'base_price_baisas',
        'base_price_display',
        'unit_price',
        'unit_price_baisas',
        'unit_price_display',
        'price_delta',
        'price_delta_baisas',
        'price_delta_display',
        'addon_total',
        'addon_total_baisas',
        'addon_total_display',
        'line_total',
        'line_total_baisas',
        'line_total_display',
        'line_discount',
        'line_discount_baisas',
        'line_discount_display',
        'subtotal',
        'subtotal_display',
        'discount_amount',
        'discount_amount_baisas',
        'discount_amount_display',
        'discount_total',
        'discount_total_display',
        'tax_amount',
        'tax_amount_baisas',
        'tax_amount_display',
        'tax_total',
        'tax_total_display',
        'comp_amount',
        'comp_amount_baisas',
        'comp_amount_display',
        'comp_total',
        'comp_total_display',
        'grand_total',
        'grand_total_display',
        'subtotal_baisas',
        'discount_total_baisas',
        'tax_total_baisas',
        'grand_total_baisas',
        'comp_total_baisas',
        // LAUNCH-P4 — a combo choice's extra price is the catalogue's, never the client's.
        'extra_price',
        'extra_price_baisas',
        'extra_price_display',
    ];

    protected function prepareForValidation(): void
    {
        if ($this->containsPriceBearingKey($this->all())) {
            throw new HttpResponseException(QrApiResponse::failure(
                'client_priced_payload_rejected',
                'Client-supplied prices are not accepted.',
                422,
            ));
        }
        // Fix order A-1 (H1) — line and combo-pick notes are one line. A
        // customer's (public QR) cleaned note longer than 140 is refused by the
        // rules (max:140); a staff device's is cut instead.
        if ($this->has('lines')) {
            $this->merge(['lines' => OneLineNote::inLines($this->input('lines'), ! $this instanceof PublicQrRequest)]);
        }
    }

    /** @param array<array-key, mixed> $value */
    private function containsPriceBearingKey(array $value): bool
    {
        foreach ($value as $key => $nested) {
            if (is_string($key) && in_array($key, self::PRICE_BEARING_KEYS, true)) {
                return true;
            }
            if (is_array($nested) && $this->containsPriceBearingKey($nested)) {
                return true;
            }
        }

        return false;
    }
}
