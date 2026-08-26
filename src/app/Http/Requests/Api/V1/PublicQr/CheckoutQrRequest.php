<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\PublicQr;

use App\Http\Requests\Api\V1\PublicQr\Concerns\RejectsClientPricing;
use Illuminate\Validation\Rule;

class CheckoutQrRequest extends PublicQrRequest
{
    use RejectsClientPricing;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_request_id' => ['required', 'string', 'min:1', 'max:64'],
            'checkout_choice' => ['required', 'string', Rule::in(['machine', 'counter'])],
            'phone' => ['required', 'string', 'min:1', 'max:32'],
            'plate_number' => ['sometimes', 'nullable', 'string', 'max:32'],
        ] + QuoteQrRequest::lineRules();
    }
}
