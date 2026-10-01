<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates POST /api/v1/auth/device/activate.
 *
 * The device sends the single one-time activation code the admin generated for
 * it, plus (LAUNCH-P1 decision 1a) what it knows about itself: the hardware
 * serial, which app it is (till | handheld | station | customer_tablet) and
 * the manufacturer/model. They are optional here on purpose: whether a missing
 * serial is refused depends on config('pos.device_serial_binding'), and an
 * unknown app name is refused by the action as an app mismatch (with the
 * activation_app_mismatch code) rather than as a generic validation error.
 * Business validation (token usable, device assigned, serial/app lock) lives
 * in ActivateDeviceAction.
 */
class ActivateDeviceRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:128'],
            ...self::deviceClaimRules(),
        ];
    }

    /** @return array<string, list<string>> */
    public static function deviceClaimRules(): array
    {
        return [
            'serial' => ['sometimes', 'nullable', 'string', 'max:128'],
            'app' => ['sometimes', 'nullable', 'string', 'max:32'],
            'manufacturer' => ['sometimes', 'nullable', 'string', 'max:128'],
            'model' => ['sometimes', 'nullable', 'string', 'max:128'],
        ];
    }
}
