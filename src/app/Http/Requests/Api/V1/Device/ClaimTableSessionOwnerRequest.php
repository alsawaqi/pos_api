<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

final class ClaimTableSessionOwnerRequest extends DeviceQrChargeRequest
{
    public function rules(): array
    {
        return [];
    }
}
