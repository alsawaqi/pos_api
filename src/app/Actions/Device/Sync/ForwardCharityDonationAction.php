<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use App\Models\Branch;
use App\Models\Device;
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
 * "already forwarded" marker). A FALSE (unset URL / non-2xx / exception)
 * leaves the marker NULL and the admin reconciliation paths retry later.
 */
class ForwardCharityDonationAction
{
    /**
     * @param  string  $amountOmr  the round-up amount as a decimal OMR string
     * @param  array<string, mixed>|null  $receipt  the bank receipt (bank_response)
     * @return bool true when the charity app accepted the forward
     */
    public function forward(
        Device $device,
        ?Branch $branch,
        string $amountOmr,
        ?array $receipt,
    ): bool {
        $baseUrl = rtrim((string) config('services.charity.url'), '/');
        if ($baseUrl === '') {
            return false; // not configured → nothing to forward
        }

        try {
            $response = Http::timeout((int) config('services.charity.timeout', 8))
                ->acceptJson()
                ->asJson()
                ->post($baseUrl.'/api/donations-pos-roundup', [
                    'pos_device_id' => $device->getKey(),
                    'pos_branch_id' => $device->branch_id,
                    // The device's CHARITY commission profile drives the shares;
                    // organization_id is the beneficiary org assigned in pos_admin.
                    'commission_profile_id' => $device->commission_profile_id,
                    'organization_id' => $device->organization_id,
                    'amount' => $amountOmr,
                    'receipt' => $receipt,
                    'terminal_id' => $device->terminal_id,
                    'bank_id' => $device->bank_id,
                    // Geo copied from the POS branch (same id-space as charity geo).
                    'country_id' => $branch?->country_id,
                    'region_id' => $branch?->region_id,
                    'district_id' => $branch?->district_id,
                    'city_id' => $branch?->city_id,
                    'latitude' => $branch?->latitude,
                    'longitude' => $branch?->longitude,
                ]);

            if (! $response->successful()) {
                Log::info('charity roundup forward skipped', [
                    'pos_device_id' => $device->getKey(),
                    'status' => $response->status(),
                    'body' => $response->json('message') ?? $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::warning('charity roundup forward failed: '.$e->getMessage(), [
                'pos_device_id' => $device->getKey(),
            ]);

            return false;
        }
    }
}
