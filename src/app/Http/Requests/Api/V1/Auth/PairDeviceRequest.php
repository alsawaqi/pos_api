<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/v1/auth/device/pair.
 *
 * The device sends its scalefusion kiosk_id + the one-time
 * activation token the admin generated for it. Business validation
 * (token usable, device assigned) lives in PairDeviceAction.
 */
class PairDeviceRequest extends FormRequest
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
            'kiosk_id' => ['required', 'string', 'max:255'],
            'activation_token' => ['required', 'string', 'max:255'],
        ];
    }
}
