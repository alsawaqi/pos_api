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
        $firstRound = $existing instanceof QrOrderRound
            ? (int) $existing->round_no === 1
            : ($session instanceof QrSession && ! $session->rounds()->exists());

        return [
            'client_request_id' => ['required', 'string', 'min:1', 'max:64'],
            'phone' => $firstRound
                ? ['required', 'string', 'min:1', 'max:32']
                : ['missing'],
            'plate_number' => $firstRound
                ? ['sometimes', 'nullable', 'string', 'max:32']
                : ['missing'],
            'roundup_amount_baisas' => ['missing'],
            'roundup_amount' => ['missing'],
        ] + QuoteQrRequest::lineRules();
    }
}
