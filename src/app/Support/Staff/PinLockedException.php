<?php

declare(strict_types=1);

namespace App\Support\Staff;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * PHASE-1A D-3 / D-5 / D-6 — a device's PIN entry is locked after repeated
 * wrong PINs. Rendered as HTTP 423 with the standard envelope:
 *
 *   { data: null, errors: [{ code: "pin_locked", message,
 *     retry_after_seconds: <int> }] }  + a Retry-After header
 *
 * Deliberately NOT a RuntimeException: every PIN-checked action maps a
 * RuntimeException to its own "invalid PIN" error, and a lock must never be
 * reported as a wrong PIN.
 */
final class PinLockedException extends Exception
{
    public function __construct(public readonly int $retryAfterSeconds, public readonly string $scope)
    {
        parent::__construct('PIN entry is locked on this device.');
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'data' => null,
            'errors' => [[
                'code' => 'pin_locked',
                'message' => $this->scope === PinLockout::MANAGER
                    ? 'Too many wrong manager PINs on this device. Try again later.'
                    : 'Too many wrong PINs on this device. Try again later, or ask a manager to unlock it.',
                'retry_after_seconds' => $this->retryAfterSeconds,
            ]],
        ], 423, ['Retry-After' => (string) $this->retryAfterSeconds]);
    }

    /** An expected outcome of PIN guessing, not an application error. */
    public function report(): bool
    {
        return true;
    }
}
