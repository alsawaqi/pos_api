<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

use App\Http\Requests\Api\V1\PublicQr\Concerns\RejectsClientPricing;
use App\Http\Requests\Api\V1\PublicQr\QuoteQrRequest;

final class AppendQuickQrOrderItemsRequest extends DeviceQrChargeRequest
{
    use RejectsClientPricing;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['client_request_id' => ['required', 'uuid']] + QuoteQrRequest::lineRules();
    }
}
