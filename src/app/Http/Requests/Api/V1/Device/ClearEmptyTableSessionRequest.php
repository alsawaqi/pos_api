<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

final class ClearEmptyTableSessionRequest extends DeviceQrChargeRequest
{
    public function rules(): array
    {
        return ['table_id' => ['required', 'integer', 'min:1'], 'seating_uuid' => ['required', 'uuid']];
    }
}
