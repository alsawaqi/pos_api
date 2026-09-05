<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

final class ListTableSessionFeedRequest extends DeviceQrChargeRequest
{
    public function rules(): array
    {
        return [
            'after' => ['sometimes', 'integer', 'min:0'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
