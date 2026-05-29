<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/v1/device/customers (register a customer at the POS).
 *
 * Phone is the natural key (find-or-create), so it's required. An optional
 * plate_number registers the customer's car for drive-thru lookup (§5.7.3).
 */
class CreateCustomerRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'plate_number' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }
}
