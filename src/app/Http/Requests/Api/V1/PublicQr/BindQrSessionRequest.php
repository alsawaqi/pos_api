<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\PublicQr;

class BindQrSessionRequest extends PublicQrRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // Deliberately do not require exactly 64 characters here: a
            // well-formed but unknown token must reach the same generic bind
            // refusal as every other credential failure.
            'token' => ['required', 'string', 'max:255'],
            'client_secret' => ['required', 'string', 'max:512'],
        ];
    }
}
