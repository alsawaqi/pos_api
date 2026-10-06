<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Tablet;

/**
 * LAUNCH-P6 fix order 1 (F-8) — `PUT device/tablet-orders/{uuid}/lines`:
 * { client_request_id, lines: [QrQuickLine] } — the full new list (at least
 * one line; to remove everything, cancel instead), server-priced with the
 * tablet submit's rules: no client price, no free-text note.
 */
final class TabletEditLinesRequest extends TabletLinesRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $rules = self::tabletLineRules();
        unset($rules['order_type']);

        return $rules + ['client_request_id' => ['required', 'uuid']];
    }
}
