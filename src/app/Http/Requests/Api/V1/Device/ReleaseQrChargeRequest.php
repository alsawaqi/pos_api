<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

use App\Models\Order;
use Illuminate\Validation\Rule;

final class ReleaseQrChargeRequest extends DeviceQrChargeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'order_uuid' => ['required', 'uuid'],
            'outcome' => ['required', 'string', Rule::in([
                Order::CHARGE_OUTCOME_DECLINED,
                Order::CHARGE_OUTCOME_CANCELLED,
                Order::CHARGE_OUTCOME_UNCERTAIN,
            ])],
            'softpos_reference' => ['sometimes', 'nullable', 'string', 'max:64'],
            'softpos_auth_code' => ['sometimes', 'nullable', 'string', 'max:32'],
            'bank_response' => ['sometimes', 'nullable', 'array'],
        ];
    }
}
