<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

final class OpenDineInTableRequest extends DeviceQrChargeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'table_id' => ['required', 'integer', 'min:1'],
            'pin' => ['missing'],
        ];
    }
}
