<?php

declare(strict_types=1);

namespace App\Actions\Device;

use App\Models\Device;
use App\Support\SoftPos\SoftPosProfileDto;
use App\Support\SoftPos\SoftPosProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class ResolveDeviceSoftPos
{
    public function handle(Device $device): ?SoftPosProfileDto
    {
        $row = DB::table('pos_bank_softpos_profiles')->where('bank_id', $device->bank_id)
            ->where('is_active', true)->first();
        if ($row === null || ! in_array($row->softpos_provider, [
            SoftPosProvider::Dhofar->value, SoftPosProvider::Muscat->value,
        ], true)) {
            return null;
        }

        return new SoftPosProfileDto(
            (int) $row->bank_id, $row->softpos_provider, $row->softpos_package,
            $row->currency_code, (bool) $row->refund_needs_transaction_id, (bool) $row->void_needs_session_id,
        );
    }

    public function clientContract(Device $device, bool $capable): array
    {
        $profile = $this->handle($device);
        $reason = $device->card_tenders_blocked_reason;
        if (! $capable && $profile?->provider !== SoftPosProvider::Dhofar->value) {
            $reason = 'softpos_app_update_required';
        } elseif ($profile === null && $reason === null) {
            $reason = 'softpos_not_configured';
        }

        return [
            'terminal_id' => $reason === null ? $device->terminal_id : null,
            'terminal_pin' => $reason === null ? $device->terminal_pin : null,
            'softpos' => [
                'provider' => $profile?->provider, 'package' => $profile?->package,
                'currency' => $profile?->currency,
                'refund_needs_transaction_id' => $profile?->refundNeedsTransactionId ?? false,
                'void_needs_session_id' => $profile?->voidNeedsSessionId ?? false,
                'requires_manual_first_launch' => true, 'login_requires_approved_code' => true,
                'blocked_reason' => $reason,
                'blocked_at' => $device->card_tenders_blocked_at === null
                    ? null : Carbon::parse($device->card_tenders_blocked_at)->toIso8601String(),
            ],
        ];
    }
}
