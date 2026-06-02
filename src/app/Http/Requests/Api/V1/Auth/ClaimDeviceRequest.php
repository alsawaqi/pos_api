<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/v1/auth/device/claim.
 *
 * The device sends the bank terminal_id the admin set at device→branch
 * assignment. Business validation (assigned, unambiguous, not blocked) lives
 * in ClaimDeviceAction.
 */
class ClaimDeviceRequest extends FormRequest
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
            'terminal_id' => ['required', 'string', 'max:64'],
        ];
    }
}
