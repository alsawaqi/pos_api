<?php

declare(strict_types=1);

namespace App\Actions\Device;

/**
 * What the device says about itself when it presents an activation code
 * (POST /auth/device/activate). All optional on the wire; whether a missing
 * value is acceptable depends on config('pos.device_serial_binding').
 */
final readonly class DeviceActivationClaim
{
    public function __construct(
        public ?string $serial = null,
        public ?string $app = null,
        public ?string $manufacturer = null,
        public ?string $model = null,
        public ?string $ip = null,
    ) {}

    /** @param  array<string, mixed>  $input */
    public static function fromInput(array $input, ?string $ip): self
    {
        $text = static fn (string $key): ?string => isset($input[$key]) && is_string($input[$key]) && trim($input[$key]) !== ''
            ? trim($input[$key]) : null;

        return new self($text('serial'), $text('app'), $text('manufacturer'), $text('model'), $ip);
    }
}
