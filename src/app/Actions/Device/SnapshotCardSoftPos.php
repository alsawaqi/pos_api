<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Device;
use App\Models\Payment;
use App\Support\SoftPos\SoftPosProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Runs inside the payment transaction; never rejects an accepted bank tender. */
final class SnapshotCardSoftPos
{
    public function __construct(private readonly ResolveDeviceSoftPos $profiles) {}

    public function handle(Device $device, array $tender): array
    {
        if (($tender['method'] ?? null) !== Payment::METHOD_CARD) {
            return [];
        }
        if ($device->reviewedSoftposSnapshot !== null) {
            $profile = $device->reviewedSoftposSnapshot;
            $reported = $this->text($tender['softpos_provider'] ?? null, 32);
            $note = empty($profile) ? 'reviewed_profile_unavailable'
                : (($reported !== null && $reported !== ($profile['provider'] ?? null))
                    ? 'reported_provider_differs_from_reviewed_profile' : null);
            return [
                'softpos_provider' => $profile['provider'] ?? null,
                'softpos_package' => $profile['package'] ?? null,
                'softpos_reported_provider' => $reported,
                'softpos_mismatch' => $note !== null,
                'softpos_mismatch_note' => $note,
            ] + $this->receipt(is_array($tender['bank_response'] ?? null) ? $tender['bank_response'] : []);
        }
        $device = Device::query()->whereKey($device->id)->lockForUpdate()->firstOrFail();
        $profile = $this->profiles->handle($device);
        $reported = $this->text($tender['softpos_provider'] ?? null, 32);
        $note = null;
        if ($reported !== null && $reported !== $profile?->provider) {
            $note = 'reported_provider_differs_from_bank_profile';
        } elseif ($device->card_tenders_blocked_reason !== null) {
            $note = 'device_blocked';
        } elseif ($reported === null && $profile?->provider !== SoftPosProvider::Dhofar->value
            && request()->header('X-Mithqal-SoftPos-Capable') !== '1') {
            $note = 'softpos_app_update_required';
        }
        if ($note !== null) {
            $device->forceFill([
                'card_tenders_blocked_reason' => 'softpos_mismatch',
                'card_tenders_blocked_at' => $device->card_tenders_blocked_at ?? now(),
            ])->save();
        }

        return [
            'softpos_provider' => $profile?->provider,
            'softpos_package' => $profile?->package,
            'softpos_reported_provider' => $reported,
            'softpos_mismatch' => $note !== null,
            'softpos_mismatch_note' => $note,
        ] + $this->receipt(is_array($tender['bank_response'] ?? null) ? $tender['bank_response'] : []);
    }

    public function receipt(array $response): array
    {
        $receipt = $response['receiptResponse'] ?? $response;
        if (is_string($receipt)) {
            $receipt = json_decode($receipt, true);
        }
        $receipt = is_array($receipt) ? $receipt : [];
        $card = $this->text($receipt['cardNumber'] ?? null, 64);
        if ($card !== null && ! preg_match('/^[0-9]{0,6}[*xX•]+[0-9]{0,4}$/u', str_replace(' ', '', $card))) {
            // Do not copy a PAN into the log or indexed receipt columns.
            Log::warning('SoftPOS receipt card number was not safely masked');
            $card = null;
        }
        $at = null;
        if (is_string($receipt['date'] ?? null) && is_string($receipt['time'] ?? null)) {
            foreach (['d/m/Y H:i:s', 'd-m-Y H:i:s', 'Y-m-d H:i:s', 'dmY His', 'd/m/Y h:i:s A'] as $format) {
                try {
                    $value = $receipt['date'].' '.$receipt['time'];
                    $parsed = Carbon::createFromFormat('!'.$format, $value, config('pos.business_timezone', 'Asia/Muscat'));
                    if ($parsed !== false && $parsed->format($format) === $value) {
                        $at = $parsed->utc();
                        break;
                    }
                } catch (Throwable) {
                    // Missing/malformed receipt facts do not reject money.
                }
            }
        }

        return [
            'softpos_transaction_id' => $this->text($receipt['transactionId'] ?? null, 64),
            'softpos_rrn' => $this->text($receipt['retrievalReferenceNumber'] ?? null, 32),
            'softpos_batch_number' => $this->text($receipt['batchNumber'] ?? null, 16),
            'softpos_card_masked' => $card === null ? null : mb_substr($card, 0, 24),
            'softpos_card_type' => $this->text($receipt['cardType'] ?? null, 16),
            'softpos_receipt_at' => $at,
        ];
    }

    private function text(mixed $value, int $limit): ?string
    {
        if (! is_string($value) && ! is_int($value)) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $limit);
    }
}
