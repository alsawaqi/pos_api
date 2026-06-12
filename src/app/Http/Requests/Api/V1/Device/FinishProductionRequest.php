<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

use Illuminate\Foundation\Http\FormRequest;

/**
 * P-G1 — validate POST /device/productions/{uuid}/finish.
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
        ];
    }
}
