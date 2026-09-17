<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

use App\Http\Requests\Api\V1\PublicQr\Concerns\RejectsClientPricing;
use App\Http\Requests\Api\V1\PublicQr\QuoteQrRequest;

final class QuickWorkspaceRequest extends DeviceQrChargeRequest
{
    use RejectsClientPricing;

    public function rules(): array
    {
        return [
            'client_request_id' => ['required', 'uuid'], 'revision' => ['required', 'string', 'size:64'],
            'operation' => ['required', 'in:clear,quantity,replace,transfer'],
            'item_id' => ['required_if:operation,quantity,replace', 'integer', 'min:1'],
            'item_ids' => ['sometimes', 'array', 'min:1', 'max:100'],
            'item_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'qty' => ['required_if:operation,quantity', 'integer', 'between:0,99'],
            'target_device_id' => ['required_if:operation,transfer', 'integer', 'min:1'],
        ] + ($this->input('operation') === 'replace' ? QuoteQrRequest::lineRules() : []);
    }
}
