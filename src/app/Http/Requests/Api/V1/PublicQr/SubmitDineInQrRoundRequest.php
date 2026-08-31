<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\PublicQr;

use App\Http\Requests\Api\V1\PublicQr\Concerns\RejectsClientPricing;
use App\Models\QrOrderRound;
use App\Models\QrSession;

final class SubmitDineInQrRoundRequest extends PublicQrRequest
{
    use RejectsClientPricing;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $session = $this->attributes->get('qr_session');
        $existing = null;
        if ($session instanceof QrSession && is_string($this->input('client_request_id'))) {
            $existing = QrOrderRound::query()
                ->where('qr_session_id', $session->id)
                ->where('client_request_id', $this->input('client_request_id'))
                ->first();
        }
        $identityAllowed = $session instanceof QrSession
            && ! $session->rounds()
                ->where('status', QrOrderRound::STATUS_ACCEPTED)
                ->exists();
        // An existing client_request_id is a transport replay. Its identity
        // fields are harmless because the action returns the stored response
        // before processing them, and allowing them preserves the exact
        // original request even after later rounds have been accepted.
        $replay = $existing instanceof QrOrderRound;

        return [
            'client_request_id' => ['required', 'string', 'min:1', 'max:64'],
            'phone' => match (true) {
                $replay => ['sometimes', 'string', 'min:1', 'max:32'],
                $identityAllowed => ['required', 'string', 'min:1', 'max:32'],
                // Let the action emit the stable
                // qr_round_identity_already_set contract once identity has
                // been fixed by an accepted round.
                default => ['sometimes', 'string', 'min:1', 'max:32'],
            },
            'plate_number' => match (true) {
                $replay, $identityAllowed => ['sometimes', 'nullable', 'string', 'max:32'],
                default => ['sometimes', 'nullable', 'string', 'max:32'],
            },
            'roundup_amount_baisas' => ['missing'],
            'roundup_amount' => ['missing'],
        ] + QuoteQrRequest::lineRules();
    }
}
