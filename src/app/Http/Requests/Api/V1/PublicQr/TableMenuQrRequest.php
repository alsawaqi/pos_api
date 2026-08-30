<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\PublicQr;

final class TableMenuQrRequest extends PublicQrRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            't' => ['required', 'string', 'min:1', 'max:255'],
        ];
    }
}
