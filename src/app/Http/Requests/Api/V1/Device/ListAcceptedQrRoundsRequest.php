<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

final class ListAcceptedQrRoundsRequest extends DeviceQrChargeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'after' => ['sometimes', 'nullable', 'string', 'max:512'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
