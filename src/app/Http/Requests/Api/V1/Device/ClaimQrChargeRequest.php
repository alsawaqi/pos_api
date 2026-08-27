<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

use App\Support\QrApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

final class ClaimQrChargeRequest extends DeviceQrChargeRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'order_uuid' => ['required', 'uuid'],
            'roundup_amount_baisas' => ['sometimes', 'integer', 'between:0,999'],
            'gps' => ['sometimes', 'nullable', 'array:lat,lng'],
            'gps.lat' => ['required_with:gps', 'numeric', 'between:-90,90'],
            'gps.lng' => ['required_with:gps', 'numeric', 'between:-180,180'],
        ];
    }

    protected function failedValidation(Validator $validator): never
    {
        if ($validator->errors()->has('roundup_amount_baisas')) {
            throw new HttpResponseException(QrApiResponse::failure(
                'roundup_out_of_range',
                'Round-up must be an integer from 0 through 999 baisas.',
                422,
            ));
        }

        parent::failedValidation($validator);
    }
}
