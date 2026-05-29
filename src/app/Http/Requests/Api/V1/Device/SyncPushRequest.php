<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Device;

use App\Models\SyncEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates POST /api/v1/device/sync/push.
 *
 * The device pushes a BATCH of offline events (max 50 per call — larger
 * backlogs are chunked client-side). This guards the envelope shape; the
 * idempotency / dedup of `client_event_id` is the ledger's job, not the
 * validator's, so in-batch repeats are NOT rejected here — they settle as
 * duplicates in IngestSyncEventsAction.
 *
 * `client_timestamp` carries when the action happened ON THE DEVICE and may
 * be hours stale (the 4-hour-late replay case); there is deliberately no
 * recency bound on it.
 */
class SyncPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'events' => ['required', 'array', 'min:1', 'max:50'],
            'events.*.client_event_id' => ['required', 'uuid'],
            'events.*.event_type' => ['required', 'string', Rule::in(SyncEvent::EVENT_TYPES)],
            'events.*.client_timestamp' => ['required', 'date'],
            'events.*.payload' => ['required', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'events.max' => 'A sync push carries at most 50 events; chunk larger backlogs.',
            'events.*.event_type.in' => 'Unknown sync event type.',
        ];
    }
}
