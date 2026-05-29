<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\DeviceActivationToken;
use App\Models\Device;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<DeviceActivationToken>
 *
 * Default: a usable token (unused, unrevoked, 30-min TTL) for a
 * freshly-made device. Pass ->for($device) to attach to a specific
 * device, and forPlaintext($plain) to pin a known plaintext you can
 * POST to the pair endpoint.
 */
class DeviceActivationTokenFactory extends Factory
{
    protected $model = DeviceActivationToken::class;

    public function definition(): array
    {
        return [
            'device_id' => Device::factory(),
            'token_hash' => DeviceActivationToken::hash('mithqal_'.Str::random(64)),
            'expires_at' => now()->addMinutes(30),
            'used_at' => null,
            'revoked_at' => null,
        ];
    }

    /** Pin the hash to a known plaintext (so a test can POST it). */
    public function forPlaintext(string $plain): static
    {
        return $this->state(fn (): array => ['token_hash' => DeviceActivationToken::hash($plain)]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }

    public function used(): static
    {
        return $this->state(fn (): array => ['used_at' => now()]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['revoked_at' => now()]);
    }
}
