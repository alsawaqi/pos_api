<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\PublicQr;

use App\Http\Requests\Api\V1\PublicQr\Concerns\RejectsClientPricing;
use App\Rules\DistinctLineAddons;
use App\Support\Orders\OneLineNote;

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
            'lines.*' => ['required', 'array:product_id,qty,addon_ids,notes,combo,meal_id'],
            'lines.*.product_id' => ['required', 'integer', 'min:1'],
            'lines.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
            'lines.*.addon_ids' => ['present', 'array', 'list', 'max:30', new DistinctLineAddons(true)],
            'lines.*.addon_ids.*' => ['integer', 'min:1'],
            'lines.*.notes' => ['present', 'nullable', 'string', 'max:'.OneLineNote::MAX],
            // LAUNCH combo add-on — "Make it a meal?" on this (main) product.
            'lines.*.meal_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            // LAUNCH combo add-on — a combo or meal line's items, per ONE combo /
            // meal ({line_id, product_id, qty, addon_ids, notes}); never priced
            // by the client. A fixed line may be left out (served as is).
            'lines.*.combo' => ['sometimes', 'nullable', 'array', 'list', 'max:50'],
            'lines.*.combo.*' => ['required', 'array:line_id,product_id,qty,addon_ids,notes'],
            'lines.*.combo.*.line_id' => ['required', 'integer', 'min:1'],
            'lines.*.combo.*.product_id' => ['required', 'integer', 'min:1'],
            'lines.*.combo.*.qty' => ['required', 'integer', 'min:1', 'max:99'],
            'lines.*.combo.*.addon_ids' => ['present', 'array', 'list', 'max:30', new DistinctLineAddons(true)],
            'lines.*.combo.*.addon_ids.*' => ['integer', 'min:1'],
            'lines.*.combo.*.notes' => ['sometimes', 'nullable', 'string', 'max:'.OneLineNote::MAX],
        ];
    }
}
