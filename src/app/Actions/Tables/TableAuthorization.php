<?php

declare(strict_types=1);

namespace App\Actions\Tables;

use App\Actions\Qr\QrDineInException;
use App\Http\Middleware\RequireStaffToken;
use App\Models\Device;
use App\Support\Staff\AuthorizationGate;
use App\Support\Staff\AuthorizationOutcome;
use Illuminate\Support\Carbon;

/**
 * LAUNCH-P5 — the authorization of the table endpoints cancel_line,
 * cancel_bill and adjust (online, and their queued sync events).
 *
 * A P5 build (auth_v: 1) sends `authorization` (the block, replacing the
 * free-text authorized_by): anything but position_ok / verified is REFUSED
 * with 403 approval_required (no block, or the position lacks the tick) or
 * approval_invalid (failed / unverifiable). An old build keeps today's
 * authorized_by text rules; its request writes a `legacy` row.
 *
 * Proof subject: subject_uuid = the request's seating_key; amount = the
 * adjustment's amount in baisas where it has one (else empty); ref = the
 * block's ref.
 */
final class TableAuthorization
{
    public function __construct(private readonly AuthorizationGate $gate) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $extra  more gate context (amount_baisas, needs_approval, percent, required, ...)
     * @return AuthorizationOutcome|null null for an old build (keep today's checks)
     *
     * @throws QrDineInException
     */
    public function check(Device $device, array $payload, string $action, array $extra = []): ?AuthorizationOutcome
    {
        $p5 = AuthorizationGate::isP5($payload);
        $outcome = $this->gate->evaluate($device, array_merge([
            'action' => $action,
            'subject_type' => 'table_session',
            'subject_uuid' => (string) ($payload['seating_key'] ?? ''),
            'actor_staff_id' => isset($payload['staff_id']) ? (int) $payload['staff_id'] : null,
            // F1 — online: the X-Staff-Token header (checked by
            // RequireStaffToken); a queued sync event: its own staff_token.
            'staff_token' => self::online() ? request()->attributes->get(RequireStaffToken::TOKEN) : ($payload['staff_token'] ?? null),
            'client_event_id' => isset($payload['client_request_id']) ? (string) $payload['client_request_id'] : null,
            'at' => isset($payload['client_timestamp']) ? Carbon::parse((string) $payload['client_timestamp']) : now(),
            'legacy_approver_staff_id' => isset($payload['adjustment']['approved_by_staff_id'])
                ? (int) $payload['adjustment']['approved_by_staff_id'] : null,
        ], $extra), AuthorizationGate::block($payload['authorization'] ?? null), $p5);

        if (! $p5) {
            return null;
        }
        if (! $outcome->authorized()) {
            throw new QrDineInException($outcome->refusalCode(), 403, $outcome->refusalCode() === 'approval_required'
                ? 'A manager must approve this.'
                : 'The manager approval could not be verified. Approve again.', details: ['reason' => $outcome->reason]);
        }

        return $outcome;
    }

    /** The request is the online table endpoint itself (not the sync outbox). */
    public static function online(): bool
    {
        return app()->bound('request') && request()->attributes->get(RequireStaffToken::ONLINE) === true;
    }
}
