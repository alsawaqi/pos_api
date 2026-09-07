<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\PublicQr;

use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class BindQrTableSessionRequest extends PublicQrRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'table_token' => ['required', 'string', 'min:1', 'max:255'],
            'client_secret' => ['required', 'string', 'max:512'],
            'location_state' => ['sometimes', 'string', Rule::in(['granted', 'denied', 'unavailable', 'not_requested'])],
            'location' => ['required_if:location_state,granted', 'array:lat,lng,accuracy_m'],
            'location.lat' => ['required_with:location', 'numeric', 'between:-90,90'],
            'location.lng' => ['required_with:location', 'numeric', 'between:-180,180'],
            'location.accuracy_m' => ['required_with:location', 'integer', 'between:0,100000'],
            'fingerprint_hash' => ['sometimes', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (array_diff(array_keys($this->all()), ['table_token', 'client_secret', 'location_state', 'location', 'fingerprint_hash']) !== []) {
                $validator->errors()->add('request', 'Unknown fields are not accepted.');
            }
        });
    }
}
