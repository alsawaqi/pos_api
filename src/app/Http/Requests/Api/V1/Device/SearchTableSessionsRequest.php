<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

final class SearchTableSessionsRequest extends DeviceQrChargeRequest
{
    public function rules(): array
    {
        return ['q' => ['required', 'string', 'min:2', 'max:32']];
    }
}
