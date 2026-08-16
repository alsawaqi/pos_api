<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use App\Models\Branch;
use App\Models\Device;
use App\Models\RoundupDonation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Forwards a POS card round-up to the charity app's POS round-up endpoint
 * (POST /api/donations-pos-roundup) so a real `charity_transactions` row
 * (+ `charity_transaction_shares` split by the device's charity commission
 * profile) is created and the charity dashboard can show which POS device /
 * branch / country the round-up came from. The charity model's `created` hook
 * fires CharityTransactionCreated (broadcast) automatically.
 *
 * Unlike the old kiosk_id→store_dhofar forward, this works for EVERY POS device
 * (no charity twin needed): we send the POS device + branch ids and copy the
 * branch's geo (whose ids share the charity countries/regions/districts/cities
 * id-space). The charity transaction links back via pos_device_id / pos_branch_id.
 *
 * BEST-EFFORT: never throws, never fails the donation.record event — a
 * charity-side outage must not roll back the POS round-up. Skipped entirely
 * when CHARITY_API_URL is unset (e.g. in tests).
 *
 * P-F7 — returns TRUE only when the charity app actually accepted the
 * forward, so the caller can stamp pos_roundup_donations.forwarded_at (the
 * "already forwarded" marker). A FALSE (unset URL / non-2xx / missing or
 * negative receiver acknowledgement / exception) leaves the marker NULL
 * and the admin reconciliation paths retry later.
 */
class ForwardCharityDonationAction
{
    /**
     * @param  string  $amountOmr  the round-up amount as a decimal OMR string
     * @param  array<string, mixed>|null  $receipt  the bank receipt (bank_response)
     * @param  string|null  $status  explicit settlement outcome ('success' once
     *                               the ride card is confirmed) — the charity
     *                               app honours this over receipt['status'].
     * @return bool true when the charity app accepted the forward
     */
    public function forward(
        Device $device,
        ?Branch $branch,
        string $amountOmr,
        ?array $receipt,
        ?string $status = null,
        ?string $posReference = null,
    ): bool {
        return $this->forwardPayload([
            'pos_device_id' => $device->getKey(),
            'pos_branch_id' => $device->branch_id,
            'pos_branch_name' => $branch?->name,
            'commission_profile_id' => $device->commission_profile_id,
            'organization_id' => $device->organization_id,
            'amount' => $amountOmr,
            'receipt' => $receipt,
            'status' => $status,
            'terminal_id' => $device->terminal_id,
            'bank_id' => $device->bank_id,
            'pos_reference' => $posReference,
            'country_id' => $branch?->country_id,
            'region_id' => $branch?->region_id,
            'district_id' => $branch?->district_id,
            'city_id' => $branch?->city_id,
            'latitude' => $branch?->latitude,
            'longitude' => $branch?->longitude,
        ]);
    }

    /**
     * Forward using the attribution captured on the durable donation row.
     *
     * Recovery can happen after the originating terminal or branch has been
     * reassigned, renamed, or soft-deleted. No mutable origin is read here.
     */
    public function forwardSnapshot(RoundupDonation $donation): bool
    {
        return $this->forwardPayload([
            'pos_device_id' => $donation->device_id,
            'pos_branch_id' => $donation->branch_id,
            'pos_branch_name' => $donation->branch_name,
            'commission_profile_id' => $donation->commission_profile_id,
            'organization_id' => $donation->organization_id,
            'amount' => (string) $donation->amount,
            'receipt' => is_array($donation->bank_response) ? $donation->bank_response : null,
            'status' => (string) $donation->status,
            'terminal_id' => $donation->terminal_id,
            'bank_id' => $donation->bank_id,
            'pos_reference' => (string) $donation->uuid,
            'country_id' => $donation->country_id,
            'region_id' => $donation->region_id,
            'district_id' => $donation->district_id,
            'city_id' => $donation->city_id,
            'latitude' => $donation->latitude,
            'longitude' => $donation->longitude,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function forwardPayload(array $payload): bool
    {
        $baseUrl = rtrim((string) config('services.charity.url'), '/');
        if ($baseUrl === '') {
            return false; // not configured → nothing to forward
        }

        try {
            $response = Http::timeout((int) config('services.charity.timeout', 8))
                ->acceptJson()
                ->asJson()
                ->post($baseUrl.'/api/donations-pos-roundup', $payload);

            $receiverSuccess = $response->json('success');
            $receiverAccepted = $receiverSuccess === true;
            if (! $response->successful() || ! $receiverAccepted) {
                Log::info('charity roundup forward not accepted', [
                    'pos_device_id' => $payload['pos_device_id'] ?? null,
                    'status' => $response->status(),
                    // Log only the explicit contract field, never the full
                    // untrusted response body.
                    'receiver_success' => $receiverSuccess,
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('charity roundup forward failed: '.$e->getMessage(), [
                'pos_device_id' => $payload['pos_device_id'] ?? null,
            ]);

            return false;
        }
    }
}
