<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

use Illuminate\Foundation\Http\FormRequest;

/**
 * P-G1 — validate POST /device/productions/{uuid}/cancel.
 *
 * The manager PIN rides the payload and is verified SERVER-SIDE
 * (VerifyManagerPinAction) — same 4-8 digit shape as the verify endpoint.
 * The route shares the pos-login throttle bucket against brute force.
 */
class CancelProductionRequest extends FormRequest
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
            'staff_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
