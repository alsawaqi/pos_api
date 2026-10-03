<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\PublicQr;

use App\Http\Requests\Api\V1\PublicQr\Concerns\RejectsClientPricing;
use App\Rules\DistinctLineAddons;

class QuoteQrRequest extends PublicQrRequest
{
    use RejectsClientPricing;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return self::lineRules();
    }

    /** @return array<string, mixed> */
    public static function lineRules(): array
    {
        return [
            'lines' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'lines.*' => ['required', 'array:product_id,qty,addon_ids,notes,combo'],
            'lines.*.product_id' => ['required', 'integer', 'min:1'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
            'lines.*.addon_ids' => ['present', 'array', 'list', 'max:30', new DistinctLineAddons(true)],
            'lines.*.addon_ids.*' => ['integer', 'min:1'],
            'lines.*.notes' => ['present', 'nullable', 'string', 'max:500'],
            // LAUNCH-P4 — a combo line's choices, per ONE combo; never priced by the client.
            'lines.*.combo' => ['sometimes', 'nullable', 'array', 'list', 'max:50'],
            'lines.*.combo.*' => ['required', 'array:slot_id,product_id,qty,addon_ids,notes'],
            'lines.*.combo.*.slot_id' => ['required', 'integer', 'min:1'],
            'lines.*.combo.*.product_id' => ['required', 'integer', 'min:1'],
            'lines.*.combo.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
            'lines.*.combo.*.addon_ids' => ['present', 'array', 'list', 'max:30', new DistinctLineAddons(true)],
            'lines.*.combo.*.addon_ids.*' => ['integer', 'min:1'],
            'lines.*.combo.*.notes' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
