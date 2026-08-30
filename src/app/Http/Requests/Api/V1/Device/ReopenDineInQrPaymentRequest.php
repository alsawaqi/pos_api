<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

final class ReopenDineInQrPaymentRequest extends DeviceQrChargeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['order_uuid' => ['required', 'uuid']];
    }
}
