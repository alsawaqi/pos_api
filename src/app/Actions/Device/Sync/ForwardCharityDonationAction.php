<?php

declare(strict_types=1);

namespace App\Actions\Device\Sync;

use App\Models\Device;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Forwards a POS card round-up to the charity app's `store_dhofar`
 * (POST /api/donations-dhofar) so the SAME function the charity stack uses
 * creates a real `charity_transactions` row (+ `charity_transaction_shares`
 * split by the device's charity commission profile) and fires the charity
 * app's CharityTransactionCreated event / downstream processing.
 *
 * Keyed by the device's kiosk_id — the charity app resolves ITS OWN device
 * (and all the non-null geo + commission profile) from that. A POS-only device
 * with no charity twin ⇒ store_dhofar responds "Device not found" ⇒ we skip it
 * (the pos_roundup_donations row already stands; the user's chosen fallback).
 *
 * BEST-EFFORT: this never throws and never fails the donation.record event — a
 * charity-side outage or a missing twin must not roll back the POS round-up.
 * Skipped entirely when CHARITY_API_URL is unset (e.g. in tests).
 */
class ForwardCharityDonationAction
{
    /**
     * @param  array<string, mixed>|null  $receipt  the bank receipt (bank_response)
     * @param  string  $amountOmr  the round-up amount as a decimal OMR string
     */
    public function forward(
        Device $device,
        string $amountOmr,
        ?array $receipt,
        ?float $latitude,
        ?float $longitude,
    ): void {
        $baseUrl = rtrim((string) config('services.charity.url'), '/');
        $kioskId = $device->kiosk_id;

        if ($baseUrl === '' || $kioskId === null || $kioskId === '') {
            return; // not configured / no kiosk id → nothing to forward
        }

        try {
            $response = Http::timeout((int) config('services.charity.timeout', 8))
                ->acceptJson()
                ->asJson()
                ->post($baseUrl.'/api/donations-dhofar', [
                    'id' => $kioskId,
                    'amount' => $amountOmr,
                    'receipt' => $receipt,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'terminalId' => $device->terminal_id,
                ]);

            // A non-2xx (e.g. the device has no charity twin, or no commission
            // profile) is an expected skip, not a failure — record it for
            // visibility but never propagate.
            if (! $response->successful()) {
                Log::info('charity donation forward skipped', [
                    'kiosk_id' => $kioskId,
                    'status' => $response->status(),
                    'body' => $response->json('message') ?? $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('charity donation forward failed: '.$e->getMessage(), [
                'kiosk_id' => $kioskId,
            ]);
        }
    }
}
