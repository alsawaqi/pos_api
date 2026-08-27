<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

use App\Support\QrApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

abstract class DeviceQrChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): never
    {
        throw new HttpResponseException(QrApiResponse::failure(
            'validation_failed',
            'The request was invalid.',
            422,
        ));
    }
}
