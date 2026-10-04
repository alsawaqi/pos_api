<?php

declare(strict_types=1);

namespace App\Support\Staff;

/**
 * LAUNCH-P5 — the server's verdict on one gated action (one pos_approvals
 * row, or none when the action was not gated).
 */
final readonly class AuthorizationOutcome
{
    public const POSITION_OK = 'position_ok';

    public const VERIFIED = 'verified';

    public const FAILED = 'failed';

    public const MISSING = 'missing';

    public const UNVERIFIABLE = 'unverifiable';

    public const LEGACY = 'legacy';

    /** Not a pos_approvals result: the action needed no tick or approval. */
    public const NOT_GATED = 'not_gated';

    public function __construct(
        public string $result,
        public ?int $actorStaffId = null,
        public ?int $approverStaffId = null,
        public ?string $reason = null,
        public ?string $mode = null,
        public ?string $method = null,
    ) {}

    /** The action may go ahead (an online endpoint refuses anything else). */
    public function authorized(): bool
    {
        return in_array($this->result, [self::POSITION_OK, self::VERIFIED, self::NOT_GATED], true);
    }

    /**
     * Who approved: the verified approver, or the actor whose own position
     * allowed it; null otherwise (never a guess — B1).
     */
    public function approvedBy(): ?int
    {
        return match ($this->result) {
            self::VERIFIED => $this->approverStaffId,
            self::POSITION_OK => $this->actorStaffId,
            default => null,
        };
    }

    /** The refusal code an online endpoint answers with (403). */
    public function refusalCode(): string
    {
        return in_array($this->result, [self::MISSING, self::NOT_GATED], true) ? 'approval_required' : 'approval_invalid';
    }
}
