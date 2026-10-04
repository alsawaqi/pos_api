<?php

declare(strict_types=1);

namespace App\Support\Staff;

use App\Models\Device;
use App\Models\PosStaff;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * LAUNCH-P5 data contract — the server check of an authorization block, one
 * pos_approvals row per gated action (idempotent on device + client_event_id
 * + action + ref).
 *
 * Block (device → server):
 *   { action, ref, mode: position|approval, actor_staff_id,
 *     approver_staff_id?, approved_at?, method?: offline|online, proof? }
 *
 *  - mode position → the actor is the staff member of the event's signed staff
 *    token ({@see StaffToken}: the device's own login of the event's staff
 *    member; the block's actor_staff_id is ignored). No valid token → failed
 *    `actor_unverified` (and an integrity flag on a sync event); an actor not
 *    active at the event time → failed `actor_inactive`. Then the actor's
 *    position has the tick (and a manual discount is within the position's
 *    maximum; a "needs manager" rule is never enough) → position_ok,
 *    otherwise missing.
 *  - mode approval → the approver belongs to the company, works at the
 *    device's branch (home or pos_staff_branches), was active at approved_at,
 *    holds approvals.give, and the proof verifies → verified; no K on the
 *    server → unverifiable; a bad proof or any other failed check → failed
 *    with the reason. The same approval (approver + action + approved_at) can
 *    verify for one event only (failed: proof_reused).
 *  - a gated action of a P5 build (payload auth_v: 1) with no block → missing.
 *  - an event of an old build (no auth_v, from a device that never sent it,
 *    while pos.require_auth_v is off) → a `legacy` row where a check would
 *    apply; the caller keeps today's behaviour.
 *
 * The proof's canonical uses the subject and amount the CALLER derives from
 * the event ({@see ApproverVerifier::canonical()}); see the caller for each
 * action. As a tolerance between the parallel device builds, the proof is
 * also accepted with an empty subject and/or an empty or any same-kind
 * amount of the event — the replay guard above keeps such a proof to one
 * event.
 */
final class AuthorizationGate
{
    public const PROOF_REUSED = 'proof_reused';

    /** F3 — an online approval's approved_at is at most 10 minutes from the server time. */
    public const ONLINE_MAX_AGE_SECONDS = 600;

    public function __construct(private readonly PositionPermissions $permissions) {}

    /**
     * Every event and call of a P5 build carries auth_v: 1 at the payload top
     * level. F2 — the marker is sticky: a device that once sent it
     * (pos_devices.auth_v_seen_at) is a P5 build from then on, and config
     * pos.require_auth_v makes every device one.
     */
    public static function isP5(array $payload, ?Device $device = null): bool
    {
        return self::marked($payload) || ($device !== null && $device->auth_v_seen_at !== null)
            || (bool) config('pos.require_auth_v', false);
    }

    /** The payload itself carries auth_v: 1. */
    public static function marked(array $payload): bool
    {
        $v = $payload['auth_v'] ?? null;

        return $v === 1 || $v === '1' || $v === 1.0;
    }

    /**
     * F2 — stamp pos_devices.auth_v_seen_at the first time a device sends
     * auth_v: 1 (a sync event, at the top or inside `order`, or an online
     * call). Runs outside any refusal's transaction so it always sticks.
     */
    public static function observe(Device $device, mixed $payload): void
    {
        if ($device->auth_v_seen_at !== null || ! is_array($payload)) {
            return;
        }
        if (! self::marked($payload) && ! (is_array($payload['order'] ?? null) && self::marked($payload['order']))) {
            return;
        }
        $now = now();
        DB::table('pos_devices')->where('id', $device->getKey())->whereNull('auth_v_seen_at')->update(['auth_v_seen_at' => $now]);
        $device->setAttribute('auth_v_seen_at', $now);
        $device->syncOriginalAttribute('auth_v_seen_at');
    }

    /**
     * The block a device sent, normalised, or null when absent/not an object.
     *
     * @return array{action: ?string, ref: ?string, mode: ?string, actor_staff_id: ?int, approver_staff_id: ?int,
     *     approved_at: ?string, method: ?string, proof: ?string}|null
     */
    public static function block(mixed $raw): ?array
    {
        if (! is_array($raw) || array_is_list($raw) && $raw !== []) {
            return null;
        }
        $int = static fn (mixed $v): ?int => is_int($v) || (is_string($v) && ctype_digit($v)) ? (int) $v : null;
        $str = static fn (mixed $v): ?string => is_string($v) && $v !== '' ? $v : (is_int($v) ? (string) $v : null);

        return [
            'action' => $str($raw['action'] ?? null),
            'ref' => $str($raw['ref'] ?? null),
            'mode' => $str($raw['mode'] ?? null),
            'actor_staff_id' => $int($raw['actor_staff_id'] ?? null),
            'approver_staff_id' => $int($raw['approver_staff_id'] ?? null),
            'approved_at' => $str($raw['approved_at'] ?? null),
            'method' => in_array($raw['method'] ?? null, ['offline', 'online'], true) ? $raw['method'] : null,
            'proof' => $str($raw['proof'] ?? null),
        ];
    }

    /**
     * The list of blocks an event carries (`authorizations: [...]`, at the
     * payload top level or inside `order`), normalised.
     *
     * @return list<array<string, mixed>>
     */
    public static function blocks(array $payload): array
    {
        $raw = $payload['authorizations'] ?? ($payload['order']['authorizations'] ?? []);
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            $block = self::block($item);
            if ($block !== null) {
                $out[] = $block;
            }
        }

        return $out;
    }

    /**
     * Check one gated action and record it.
     *
     * `actor_staff_id` is the event's own staff member (order.staff_id, the
     * voider, the closer, logged_by, the request's staff_id) and
     * `staff_token` the event's signed staff token.
     *
     * F3 — `expected_ref` (the table operations and sold-out: the request's
     * client_request_id) must equal the block's ref, else failed
     * `ref_mismatch`; `max_age_seconds` (online only: 600) bounds an approval's
     * approved_at around the server time, else failed `approval_stale`.
     *
     * @param  array{action: string, subject_type: string, subject_uuid?: ?string, amount_baisas?: ?int, ref?: ?string,
     *     actor_staff_id?: ?int, staff_token?: mixed, client_event_id?: ?string, at: CarbonInterface, required?: bool,
     *     needs_approval?: bool, percent?: ?float, candidate_amounts?: list<int>, legacy_approver_staff_id?: ?int,
     *     expected_ref?: ?string, max_age_seconds?: ?int}  $ctx
     * @param  array<string, mixed>|null  $block  a normalised block ({@see block()})
     */
    public function evaluate(Device $device, array $ctx, ?array $block, bool $p5): AuthorizationOutcome
    {
        $actor = $ctx['actor_staff_id'] ?? null;
        $ref = $ctx['ref'] ?? ($block['ref'] ?? null);

        if (! $p5) {
            $approver = $ctx['legacy_approver_staff_id'] ?? null;
            $approver = $approver !== null && $this->staff($device, $approver) !== null ? $approver : null;

            return $this->record($device, $ctx, $ref, new AuthorizationOutcome(AuthorizationOutcome::LEGACY,
                $this->knownActor($device, $actor), $approver, null, $approver !== null ? 'approval' : 'position'), null);
        }

        if ($block === null) {
            if (! ($ctx['required'] ?? true)) {
                return new AuthorizationOutcome(AuthorizationOutcome::NOT_GATED, $actor);
            }

            return $this->record($device, $ctx, $ref, new AuthorizationOutcome(AuthorizationOutcome::MISSING,
                $this->knownActor($device, $actor), null, 'no_authorization', 'approval'), null);
        }

        if (array_key_exists('expected_ref', $ctx) && $block['ref'] !== $ctx['expected_ref']) {
            // F3 — one block per request: a block made for another request
            // (or none) never authorizes this one.
            return $this->record($device, $ctx, $ref, new AuthorizationOutcome(AuthorizationOutcome::FAILED,
                $this->knownActor($device, $actor), null, 'ref_mismatch', $block['mode'] === 'position' ? 'position' : 'approval',
                $block['method']), $block);
        }

        $outcome = match ($block['mode']) {
            'position' => $this->signedPosition($device, $ctx),
            'approval' => $this->approval($device, $ctx, $block, $actor),
            default => new AuthorizationOutcome(AuthorizationOutcome::FAILED, $this->knownActor($device, $actor), null, 'invalid_block', 'approval'),
        };

        return $this->record($device, $ctx, $ref, $outcome, $block);
    }

    /**
     * F1 — a position block counts only for the staff member of the event's
     * signed staff token; the block's own actor_staff_id is never trusted.
     */
    private function signedPosition(Device $device, array $ctx): AuthorizationOutcome
    {
        $eventStaff = $ctx['actor_staff_id'] ?? null;
        $token = StaffToken::check($device, $ctx['staff_token'] ?? null, $eventStaff);
        if ($token['failure'] !== null) {
            // A paid sale is never refused over this: the verdict is recorded
            // and the event is flagged.
            $device->syncIntegrityFlags[] = $eventStaff === null ? 'actor_unverified' : 'actor_unverified:'.$eventStaff;

            return new AuthorizationOutcome(AuthorizationOutcome::FAILED, $this->knownActor($device, $eventStaff), null,
                'actor_unverified', 'position');
        }

        return $this->position($device, $ctx, $token['staff_id']);
    }

    private function position(Device $device, array $ctx, ?int $actor): AuthorizationOutcome
    {
        $staff = $actor === null ? null : $this->staff($device, $actor);
        if ($staff === null) {
            return new AuthorizationOutcome(AuthorizationOutcome::FAILED, null, null, 'actor_unknown', 'position');
        }
        // A suspended (or since-terminated) actor never counts on their own tick.
        if (! $this->activeAt($staff, Carbon::instance($ctx['at']))) {
            return new AuthorizationOutcome(AuthorizationOutcome::FAILED, $actor, null, 'actor_inactive', 'position');
        }
        if ($ctx['needs_approval'] ?? false) {
            return new AuthorizationOutcome(AuthorizationOutcome::MISSING, $actor, null, 'needs_approval', 'position');
        }
        $companyId = (int) $device->company_id;
        if (! $this->permissions->allows($companyId, (string) $staff->position, $ctx['action'])) {
            return new AuthorizationOutcome(AuthorizationOutcome::MISSING, $actor, null, 'not_ticked', 'position');
        }
        $percent = $ctx['percent'] ?? null;
        if ($percent !== null && $percent > $this->permissions->discountMaxPercent($companyId, (string) $staff->position)) {
            return new AuthorizationOutcome(AuthorizationOutcome::MISSING, $actor, null, 'above_max', 'position');
        }

        return new AuthorizationOutcome(AuthorizationOutcome::POSITION_OK, $actor, null, null, 'position');
    }

    private function approval(Device $device, array $ctx, array $block, ?int $actor): AuthorizationOutcome
    {
        $actor = $actor !== null && $this->staff($device, $actor) !== null ? $actor : null;
        $method = $block['method'];
        $fail = static fn (string $reason, ?int $approver = null): AuthorizationOutcome => new AuthorizationOutcome(
            AuthorizationOutcome::FAILED, $actor, $approver, $reason, 'approval', $method);

        $approverId = $block['approver_staff_id'];
        if ($approverId === null) {
            return $fail('approver_missing');
        }
        $approver = $this->staff($device, $approverId);
        if ($approver === null) {
            return $fail('approver_unknown');
        }
        if (! StaffBranches::staffWorksAt($approverId, (int) $device->branch_id)) {
            return $fail('approver_not_at_branch', $approverId);
        }
        $approvedAt = self::parseTime($block['approved_at']);
        if ($approvedAt === null) {
            return $fail('approved_at_invalid', $approverId);
        }
        $maxAge = $ctx['max_age_seconds'] ?? null;
        if ($maxAge !== null && abs(now()->getTimestamp() - $approvedAt->getTimestamp()) > $maxAge) {
            return $fail('approval_stale', $approverId);
        }
        if (! $this->activeAt($approver, $approvedAt)) {
            return $fail('approver_inactive', $approverId);
        }
        if (! $this->permissions->allows((int) $device->company_id, (string) $approver->position, 'approvals.give')) {
            return $fail('approver_not_allowed', $approverId);
        }
        $key = ApproverVerifier::storedKey($approver->pin_offline_key);
        if ($key === null) {
            return new AuthorizationOutcome(AuthorizationOutcome::UNVERIFIABLE, $actor, $approverId, 'no_verifier', 'approval', $method);
        }
        if ($block['proof'] === null || ! ApproverVerifier::verifyProof($key, $this->canonicals($device, $ctx, $block, $approvedAt), $block['proof'])) {
            return $fail('bad_proof', $approverId);
        }
        if ($this->reused($device, $ctx, $approverId, $approvedAt)) {
            return $fail(self::PROOF_REUSED, $approverId);
        }

        return new AuthorizationOutcome(AuthorizationOutcome::VERIFIED, $actor, $approverId, null, 'approval', $method);
    }

    /** @return list<string> */
    private function canonicals(Device $device, array $ctx, array $block, Carbon $approvedAt): array
    {
        $times = array_values(array_unique([(string) $block['approved_at'], ApproverVerifier::isoMillis($approvedAt)]));
        $subjects = [$ctx['subject_uuid'] ?? null, null];
        $amounts = [$ctx['amount_baisas'] ?? null, null, ...($ctx['candidate_amounts'] ?? [])];
        $ref = $block['ref'] ?? ($ctx['ref'] ?? null);

        $out = [];
        foreach ($times as $time) {
            foreach ($subjects as $subject) {
                foreach ($amounts as $amount) {
                    $out[] = ApproverVerifier::canonical((string) $ctx['action'], (string) $device->uuid,
                        (int) $block['approver_staff_id'], $time, $subject, $amount === null ? null : (int) $amount, $ref);
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The same approval (approver + action + approved_at) may verify for one
     * subject only — a re-sent order carrying its own approval again is not a
     * reuse; the same approval on another order (or, with no subject, on
     * another event) is.
     */
    private function reused(Device $device, array $ctx, int $approverId, Carbon $approvedAt): bool
    {
        $subject = (string) ($ctx['subject_uuid'] ?? '');

        return DB::table('pos_approvals')
            ->where('company_id', $device->company_id)
            ->where('approver_staff_id', $approverId)
            ->where('action', $ctx['action'])
            ->where('approved_at', self::dbTime($approvedAt))
            ->where('result', AuthorizationOutcome::VERIFIED)
            ->where(function ($q) use ($device, $ctx, $subject): void {
                if ($subject !== '') {
                    $q->whereNull('subject_uuid')->orWhere('subject_uuid', '!=', $subject);

                    return;
                }
                $q->where('device_id', '!=', (int) $device->getKey())
                    ->orWhereNull('client_event_id')
                    ->orWhere('client_event_id', '!=', (string) ($ctx['client_event_id'] ?? ''));
            })
            ->exists();
    }

    private function activeAt(PosStaff $staff, Carbon $at): bool
    {
        if ((string) $staff->status === PosStaff::STATUS_ACTIVE && ! $staff->trashed()) {
            return true;
        }
        // A since-terminated approver who approved before leaving still counts.
        $left = $staff->terminated_at ?? $staff->deleted_at;

        return (string) $staff->status === PosStaff::STATUS_TERMINATED && $left !== null && $left->greaterThan($at);
    }

    private function staff(Device $device, int $id): ?PosStaff
    {
        return PosStaff::withTrashed()->where('company_id', $device->company_id)->whereKey($id)->first();
    }

    private function knownActor(Device $device, ?int $id): ?int
    {
        return $id !== null && $this->staff($device, $id) !== null ? $id : null;
    }

    /**
     * One row per gated action, deduplicated ONLY on (device, client_event_id,
     * action, ref): a repeat of the same event or request returns its first
     * verdict; every other action is recorded, even on the same subject with
     * the same verdict (fix order 1 F10 — two people's actions on one table
     * are two rows).
     *
     * @param  array<string, mixed>|null  $block
     */
    private function record(Device $device, array $ctx, ?string $ref, AuthorizationOutcome $outcome, ?array $block): AuthorizationOutcome
    {
        $eventId = $ctx['client_event_id'] ?? null;
        if ($eventId !== null) {
            $existing = DB::table('pos_approvals')
                ->where('device_id', (int) $device->getKey())
                ->where('client_event_id', $eventId)
                ->where('action', $ctx['action'])
                ->where(fn ($q) => $ref === null ? $q->whereNull('ref') : $q->where('ref', $ref))
                ->first();
            if ($existing !== null) {
                return new AuthorizationOutcome((string) $existing->result,
                    $existing->actor_staff_id !== null ? (int) $existing->actor_staff_id : null,
                    $existing->approver_staff_id !== null ? (int) $existing->approver_staff_id : null,
                    $existing->reason, (string) $existing->mode, $existing->method);
            }
        }

        $blockTime = self::parseTime($block['approved_at'] ?? null);
        $approvedAt = $blockTime ?? Carbon::instance($ctx['at']);
        $amount = $ctx['amount_baisas'] ?? null;
        DB::table('pos_approvals')->insert([
            'uuid' => (string) Str::uuid(),
            'company_id' => (int) $device->company_id,
            'branch_id' => (int) $device->branch_id,
            'device_id' => (int) $device->getKey(),
            'client_event_id' => $eventId,
            'action' => $ctx['action'],
            'subject_type' => $ctx['subject_type'],
            'subject_uuid' => $ctx['subject_uuid'] ?? null,
            'amount' => $amount === null ? null : number_format($amount / 1000, 3, '.', ''),
            'ref' => $ref,
            'actor_staff_id' => $outcome->actorStaffId,
            'approver_staff_id' => $outcome->approverStaffId,
            'mode' => $outcome->mode ?? 'approval',
            'method' => $outcome->method,
            'approved_at' => self::dbTime($approvedAt),
            'verified_at' => now(),
            'result' => $outcome->result,
            'reason' => $outcome->reason,
            'created_at' => now(),
        ]);

        return $outcome;
    }

    /**
     * One of the six online PIN-checked actions (card.reverse,
     * production.cancel, disposition, qr.payment_review, qr.expired_cancel,
     * bill.combine): the server itself verified the approver's PIN, so the row
     * is `verified`, method `online`. Called inside the action's transaction.
     */
    public function recordOnline(Device $device, string $action, string $subjectType, ?string $subjectUuid,
        ?int $amountBaisas, int $approverStaffId, ?int $actorStaffId = null, ?string $clientEventId = null): void
    {
        $this->record($device, [
            'action' => $action, 'subject_type' => $subjectType, 'subject_uuid' => $subjectUuid,
            'amount_baisas' => $amountBaisas, 'client_event_id' => $clientEventId, 'at' => now(),
        ], null, new AuthorizationOutcome(AuthorizationOutcome::VERIFIED, $this->knownActor($device, $actorStaffId),
            $approverStaffId, null, 'approval', 'online'), null);
    }

    public static function parseTime(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /** Millisecond precision on both Postgres timestamp(3) and the SQLite mirror. */
    public static function dbTime(CarbonInterface $at): string
    {
        return Carbon::instance($at)->utc()->format('Y-m-d H:i:s.v');
    }
}
