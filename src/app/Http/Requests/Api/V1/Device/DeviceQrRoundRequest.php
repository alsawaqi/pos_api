<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

final class DeviceQrRoundRequest extends DeviceQrChargeRequest
{
    protected function prepareForValidation(): void
    {
        $routeRoundId = $this->route('round_id');
        if ($routeRoundId !== null) {
            $this->merge(['round_id' => $routeRoundId]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'round_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
