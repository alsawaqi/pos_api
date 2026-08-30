<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\PublicQr;

use App\Http\Requests\Api\V1\PublicQr\Concerns\RejectsClientPricing;
use Illuminate\Validation\Rule;

final class FinishDineInQrRequest extends PublicQrRequest
{
    use RejectsClientPricing;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'payment_choice' => ['required', 'string', Rule::in(['counter', 'station'])],
            'roundup_amount_baisas' => ['missing'],
            'roundup_amount' => ['missing'],
        ];
    }
}
