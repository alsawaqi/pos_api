<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/v1/auth/device/activate.
 *
 * The device sends the single one-time activation code the admin generated for
 * it. Business validation (token usable, device assigned) lives in
 * ActivateDeviceAction.
 */
class ActivateDeviceRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:128'],
        ];
    }
}
