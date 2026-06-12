<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

use Illuminate\Foundation\Http\FormRequest;

/**
 * P-G1 — validate POST /device/productions/{uuid}/finish.
 *
 * P-G1.5: expires_at is the CHEF's per-batch expiry (the Finish dialog
 * prefills it from the product's shelf_life_days; this field arrives when
 * the chef confirmed or changed it). Absent = the server derives the
 * default itself; explicit null = "this batch never expires".
 */
class FinishProductionRequest extends FormRequest
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
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:2000-01-01', 'before:2100-01-01'],
        ];
    }
}
