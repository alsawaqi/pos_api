<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/v1/auth/pos/login.
 *
 * The device sends the operator's numeric PIN (4–6 digits per §5.4.2;
 * the merchant portal mints 6). String, not integer, so a leading-zero
 * PIN ("004210") isn't mangled.
 */
class StaffLoginRequest extends FormRequest
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
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
        ];
    }
}
