<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates GET /api/v1/device/config/delta.
 *
 * `since` is the ISO-8601 timestamp the device last synced at; the bundle
 * returns only rows changed/deleted after it.
 */
class DeviceConfigDeltaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'since' => ['required', 'date'],
        ];
    }
}
