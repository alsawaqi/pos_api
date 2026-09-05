<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

final class RecordKitchenPrintResultRequest extends DeviceQrChargeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'ticket_key' => ['required', 'string', 'max:96', 'regex:/\Around:[1-9][0-9]*\z/'],
            'print_result' => ['required', 'in:printed,failed'],
            'printed_at' => ['required_if:print_result,printed', 'nullable', 'date'],
        ];
    }
}
