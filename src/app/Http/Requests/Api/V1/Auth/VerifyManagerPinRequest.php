<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/v1/device/auth/verify-manager-pin (P-F1).
 *
 * The device sends the approving staff member's numeric PIN. String, not
 * integer, so a leading-zero PIN ("004210") isn't mangled. 4–8 digits —
 * a superset of the 4–6 login range, future-proofing longer manager PINs.
 */
class VerifyManagerPinRequest extends FormRequest
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
            'pin' => ['required', 'string', 'regex:/^\d{4,8}$/'],
        ];
    }
}
