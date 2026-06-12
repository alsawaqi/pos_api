<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

use Illuminate\Foundation\Http\FormRequest;

/**
 * P-G1 — validate POST /device/productions (start a batch).
 *
 * quantity is whole pieces (the chef makes 10 cakes, not 10.5). Extras are
 * the explicitly declared beyond-recipe lines; ownership + balance checks
 * live in StartProductionAction (online-only, against fresh locked rows).
 */
class StartProductionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'between:1,9999'],
            'staff_id' => ['nullable', 'integer', 'min:1'],
            'extras' => ['sometimes', 'array', 'max:50'],
            'extras.*.ingredient_id' => ['required', 'integer', 'min:1'],
            'extras.*.quantity' => ['required', 'numeric', 'gt:0', 'max:999999.999'],
        ];
    }
}
