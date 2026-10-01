<?php

declare(strict_types=1);

namespace App\Actions\Device;

use RuntimeException;

/**
 * An activation the serial/app lock refused. The code was NOT consumed and the
 * device's live credential was NOT touched; a refusal row was recorded.
 */
final class DeviceActivationRefused extends RuntimeException
{
    public const SERIAL_MISSING = 'activation_serial_missing';

    public const DEVICE_MISMATCH = 'activation_device_mismatch';

    public const APP_MISMATCH = 'activation_app_mismatch';

    private const MESSAGES = [
        self::SERIAL_MISSING => 'This app did not send the device serial number. Update the app, then activate again.',
        self::DEVICE_MISMATCH => 'This activation code was made for a different device. Use the code made for this device.',
        self::APP_MISMATCH => 'This activation code is for a different kind of device. Open the matching app on the matching device.',
    ];

    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason] ?? 'Activation refused.');
    }
}
