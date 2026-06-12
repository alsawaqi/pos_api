<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

use Illuminate\Foundation\Http\FormRequest;

/**
 * P-G1.5 — validate POST /device/disposition (day-end handling of expired
 * cooked pieces). Per item the remainder splits across waste / give-away /
 * carry-over; the give-away comment + the manager PIN rules are enforced in
 * ApplyDispositionAction (they depend on which quantities are present).
 */
class ApplyDispositionRequest extends FormRequest
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
            'staff_id' => ['nullable', 'integer', 'min:1'],
            'pin' => ['sometimes', 'nullable', 'string', 'regex:/^\d{4,8}$/'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', 'integer', 'min:1'],
            'items.*.waste_qty' => ['sometimes', 'numeric', 'min:0', 'max:999999.999'],
            'items.*.give_away_qty' => ['sometimes', 'numeric', 'min:0', 'max:999999.999'],
            'items.*.carry_over_qty' => ['sometimes', 'numeric', 'min:0', 'max:999999.999'],
            'items.*.comment' => ['sometimes', 'nullable', 'string', 'max:500'],
            'items.*.give_away_comment' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
