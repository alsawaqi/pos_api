<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use RuntimeException;

/**
 * LAUNCH-P5 — a handler's machine-readable refusal of a sync event. The
 * dispatcher stamps the event `failed` with
 *
 *   result_json = { error: <message>, code: <codeName>, ...details,
 *                   permanent?: true }
 *
 * A non-permanent refusal (e.g. shift.close `unsynced_sales`) is retried by
 * re-pushing the same client_event_id; a permanent one (e.g.
 * `training_refused`) never settles.
 */
final class SyncRefusal extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public readonly string $codeName,
        string $message,
        public readonly array $details = [],
        public readonly bool $permanent = false,
    ) {
        parent::__construct($message);
    }

    /** @return array<string, mixed> */
    public function resultJson(): array
    {
        return ['error' => $this->getMessage(), 'code' => $this->codeName] + $this->details
            + ($this->permanent ? ['permanent' => true] : []);
    }
}
