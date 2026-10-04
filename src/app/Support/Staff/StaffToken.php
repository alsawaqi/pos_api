<?php

declare(strict_types=1);

namespace App\Support\Staff;

use App\Models\Device;
use JsonException;

/**
 * LAUNCH-P5 fix order 1 (F1, review H1) — the signed staff token that binds
 * an event's actor to a login on that device.
 *
 *   staff_token = base64url(json{v:1, d:<device_id>, s:<staff_id>, iat:<unix>})
 *                 "." base64url(HMAC-SHA256(key, <first part>))
 *   key         = HMAC-SHA256(data "pos-staff-token-v1", key config('app.key')), raw
 *
 * base64url = RFC 4648 §5 without "=" padding. A successful staff login
 * returns it; devices keep it with the logged-in person and send it in every
 * P5 sync event payload (`staff_token`, next to `auth_v`; the token of the
 * person who made that event) and as the `X-Staff-Token` header on every
 * online call made while someone is logged in. No server-side session state
 * and no expiry: the server checks the signature, that `d` is the
 * authenticated device and that `s` is the event's own staff member.
 *
 * Accepted residual: a modified app can replay an earlier token of someone
 * who logged in on that same device.
 */
final class StaffToken
{
    public const HEADER = 'X-Staff-Token';

    public const VERSION = 1;

    /** Why a token was not accepted (none = accepted). */
    public const MISSING = 'token_missing';

    public const INVALID = 'token_invalid';

    public const OTHER_DEVICE = 'token_other_device';

    public const OTHER_STAFF = 'token_other_staff';

    /** A token is a few dozen bytes; anything longer is not one. */
    private const MAX_LENGTH = 512;

    public static function issue(int $deviceId, int $staffId, ?int $issuedAt = null): string
    {
        $body = self::encode(json_encode(['v' => self::VERSION, 'd' => $deviceId, 's' => $staffId, 'iat' => $issuedAt ?? time()],
            JSON_THROW_ON_ERROR));

        return $body.'.'.self::encode(hash_hmac('sha256', $body, self::key(), true));
    }

    /**
     * The signed claims of a token, or null when it is not a valid one.
     *
     * @return array{d: int, s: int, iat: int}|null
     */
    public static function read(mixed $token): ?array
    {
        if (! is_string($token) || $token === '' || strlen($token) > self::MAX_LENGTH) {
            return null;
        }
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            return null;
        }
        if (! hash_equals(self::encode(hash_hmac('sha256', $parts[0], self::key(), true)), $parts[1])) {
            return null;
        }
        $json = self::decode($parts[0]);
        try {
            $claims = $json === null ? null : json_decode($json, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (! is_array($claims) || ($claims['v'] ?? null) !== self::VERSION
            || ! is_int($claims['d'] ?? null) || ! is_int($claims['s'] ?? null) || ! is_int($claims['iat'] ?? null)) {
            return null;
        }

        return ['d' => $claims['d'], 's' => $claims['s'], 'iat' => $claims['iat']];
    }

    /**
     * Check a token for a device and, when the event names one, its own staff
     * member. Returns the token's staff id, or the reason it is not accepted.
     *
     * @return array{staff_id: ?int, failure: ?string}
     */
    public static function check(Device $device, mixed $token, ?int $eventStaffId): array
    {
        if ($token === null || $token === '') {
            return ['staff_id' => null, 'failure' => self::MISSING];
        }
        $claims = self::read($token);
        if ($claims === null) {
            return ['staff_id' => null, 'failure' => self::INVALID];
        }
        if ($claims['d'] !== (int) $device->getKey()) {
            return ['staff_id' => null, 'failure' => self::OTHER_DEVICE];
        }
        if ($eventStaffId !== null && $claims['s'] !== $eventStaffId) {
            return ['staff_id' => null, 'failure' => self::OTHER_STAFF];
        }

        return ['staff_id' => $claims['s'], 'failure' => null];
    }

    private static function key(): string
    {
        return hash_hmac('sha256', 'pos-staff-token-v1', (string) config('app.key'), true);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function decode(string $text): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $text) !== 1) {
            return null;
        }
        $bytes = base64_decode(strtr($text, '-_', '+/').str_repeat('=', (4 - strlen($text) % 4) % 4), true);

        return $bytes === false ? null : $bytes;
    }
}
