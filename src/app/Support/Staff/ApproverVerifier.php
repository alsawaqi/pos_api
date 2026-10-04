<?php

declare(strict_types=1);

namespace App\Support\Staff;

use App\Models\PosStaff;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * LAUNCH-P5 data contract — the approval proof (shared by api, till and
 * handheld; golden vectors in tests/Fixtures/approval_proof_goldens.json).
 *
 *   salt       16 random bytes, hex
 *   iterations config pos.approver_kdf_iterations (stored per staff row)
 *   K          PBKDF2-HMAC-SHA256(PIN as UTF-8, salt bytes, iterations, 32)
 *              → pos_staff.pin_offline_key (64 lowercase hex characters)
 *   check      hex(SHA-256("mithqal-approver-check-v1" ‖ K))
 *   canonical  "v1|" action "|" device_uuid "|" approver_staff_id "|"
 *              approved_at (UTC ISO-8601, ms, Z) "|" subject_uuid_or_empty
 *              "|" amount_baisas_or_empty "|" ref_or_empty
 *   proof      hex(HMAC-SHA256(K, canonical))
 *
 * K never leaves the server and is never logged; devices get salt,
 * iterations and check only.
 */
final class ApproverVerifier
{
    public const CHECK_PREFIX = 'mithqal-approver-check-v1';

    public const DEFAULT_ITERATIONS = 100000;

    public static function iterations(): int
    {
        $value = (int) config('pos.approver_kdf_iterations', self::DEFAULT_ITERATIONS);

        return $value >= 1 ? $value : self::DEFAULT_ITERATIONS;
    }

    /** K as raw bytes. */
    public static function deriveKey(string $pin, string $saltHex, int $iterations): string
    {
        return hash_pbkdf2('sha256', $pin, (string) hex2bin($saltHex), $iterations, 32, true);
    }

    /** check = hex(SHA-256(prefix ‖ K)). */
    public static function check(string $keyRaw): string
    {
        return hash('sha256', self::CHECK_PREFIX.$keyRaw);
    }

    public static function canonical(string $action, string $deviceUuid, int $approverStaffId, string $approvedAt,
        ?string $subjectUuid, ?int $amountBaisas, ?string $ref): string
    {
        return 'v1|'.$action.'|'.$deviceUuid.'|'.$approverStaffId.'|'.$approvedAt.'|'.($subjectUuid ?? '')
            .'|'.($amountBaisas === null ? '' : (string) $amountBaisas).'|'.($ref ?? '');
    }

    public static function proof(string $keyRaw, string $canonical): string
    {
        return hash_hmac('sha256', $canonical, $keyRaw);
    }

    /** The approved_at form every canonical uses: UTC, millisecond precision, "Z". */
    public static function isoMillis(CarbonInterface $at): string
    {
        return Carbon::instance($at)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Compare a device proof with every candidate canonical, in constant time
     * per candidate.
     *
     * @param  list<string>  $canonicals
     */
    public static function verifyProof(string $keyRaw, array $canonicals, string $proofHex): bool
    {
        $proofHex = strtolower(trim($proofHex));
        if (preg_match('/^[0-9a-f]{64}$/', $proofHex) !== 1) {
            return false;
        }
        $ok = false;
        foreach (array_values(array_unique($canonicals)) as $canonical) {
            $ok = hash_equals(self::proof($keyRaw, $canonical), $proofHex) || $ok;
        }

        return $ok;
    }

    /**
     * The stored K as raw bytes, or null. The contract stores 64 hex
     * characters; 32 raw bytes or base64 of 32 bytes are read too, so a
     * writer that chose another encoding still verifies.
     */
    public static function storedKey(mixed $value): ?string
    {
        if (is_resource($value)) {
            $value = stream_get_contents($value);
        }
        if (! is_string($value) || $value === '') {
            return null;
        }
        $trimmed = trim($value);
        if (preg_match('/^[0-9a-fA-F]{64}$/', $trimmed) === 1) {
            return (string) hex2bin($trimmed);
        }
        if (strlen($value) === 32) {
            return $value;
        }
        $decoded = base64_decode($trimmed, true);

        return $decoded !== false && strlen($decoded) === 32 ? $decoded : null;
    }

    /**
     * The device-facing verifier of a staff row ({salt, iterations, check}),
     * or nulls when the row has none yet.
     *
     * @return array{salt: ?string, iterations: ?int, check: ?string}
     */
    public static function material(object $staff): array
    {
        $key = self::storedKey($staff->pin_offline_key ?? null);
        $salt = $staff->pin_offline_salt ?? null;
        $iterations = $staff->pin_offline_iterations ?? null;
        if ($key === null || ! is_string($salt) || $salt === '' || $iterations === null) {
            return ['salt' => null, 'iterations' => null, 'check' => null];
        }

        return ['salt' => strtolower($salt), 'iterations' => (int) $iterations, 'check' => self::check($key)];
    }

    /**
     * Lazily make the verifier while the server holds the plaintext PIN (a
     * successful login or manager-PIN check), only when the row has none.
     * Best effort: a failure here never fails the login or the check.
     */
    public static function ensureFor(PosStaff $staff, string $pin): void
    {
        if (self::storedKey($staff->pin_offline_key) !== null && $staff->pin_offline_salt !== null) {
            return;
        }
        try {
            $salt = bin2hex(random_bytes(16));
            $iterations = self::iterations();
            $key = bin2hex(self::deriveKey($pin, $salt, $iterations));
            $updated = DB::table('pos_staff')->where('id', $staff->id)->whereNull('pin_offline_key')->update([
                'pin_offline_key' => $key,
                'pin_offline_salt' => $salt,
                'pin_offline_iterations' => $iterations,
            ]);
            if ($updated === 1) {
                $staff->setRawAttributes(array_merge($staff->getAttributes(), [
                    'pin_offline_key' => $key,
                    'pin_offline_salt' => $salt,
                    'pin_offline_iterations' => $iterations,
                ]), true);
            } else {
                $staff->refresh();
            }
        } catch (Throwable) {
            // Never block a login or an approval on the verifier.
        }
    }
}
