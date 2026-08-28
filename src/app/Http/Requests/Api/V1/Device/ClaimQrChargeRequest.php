<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

final class ClaimQrChargeRequest extends DeviceQrChargeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'order_uuid' => ['required', 'uuid'],
            'roundup_amount_baisas' => ['missing'],
            'gps' => ['sometimes', 'nullable', 'array:lat,lng'],
            'gps.lat' => ['required_with:gps', 'numeric', 'between:-90,90'],
            'gps.lng' => ['required_with:gps', 'numeric', 'between:-180,180'],
        ];
    }
}
