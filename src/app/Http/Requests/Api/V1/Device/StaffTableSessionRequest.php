<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

use App\Actions\Tables\ResolveStaffSeatingAction;

final class StaffTableSessionRequest extends DeviceQrChargeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ResolveStaffSeatingAction::rules((string) $this->route('table_operation'))
            + ['client_timestamp' => ['sometimes', 'date']];
    }
}
