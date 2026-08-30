<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\PublicQr;

final class BindQrTableSessionRequest extends PublicQrRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'table_token' => ['required', 'string', 'min:1', 'max:255'],
            'client_secret' => ['required', 'string', 'max:512'],
        ];
    }
}
