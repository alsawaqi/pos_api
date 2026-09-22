<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\PublicQr;

use App\Http\Requests\Api\V1\PublicQr\Concerns\RejectsClientPricing;

final class SubmitDineInQrRoundRequest extends PublicQrRequest
{
    use RejectsClientPricing;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        // Identity is optional; the action owns the accepted-identity and replay guards.
        return [
            'client_request_id' => ['required', 'string', 'min:1', 'max:64'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'plate_number' => ['sometimes', 'nullable', 'string', 'max:32'],
            'roundup_amount_baisas' => ['missing'],
            'roundup_amount' => ['missing'],
        ] + QuoteQrRequest::lineRules();
    }
}
