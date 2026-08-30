<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

final class ClaimQrSettlementRequest extends DeviceQrChargeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'order_uuid' => ['required', 'uuid'],
            'gps' => ['sometimes', 'nullable', 'array:lat,lng'],
            'gps.lat' => ['required_with:gps', 'numeric', 'between:-90,90'],
            'gps.lng' => ['required_with:gps', 'numeric', 'between:-180,180'],
        ];
    }
}
