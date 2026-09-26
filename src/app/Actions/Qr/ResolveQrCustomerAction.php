<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Customer;
use App\Support\CustomerIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Resolves the customer identity for a QR checkout inside the order transaction.
 *
 * Shares the device canonical identity lock and find-or-create policy.
 * A deleted, unmerged customer is restored without changing the staff-authored
 * name or linked history; merged ids resolve to their survivor.
 */
final class ResolveQrCustomerAction
{
    public function handle(int $companyId, string $phone, ?string $plateNumber): ResolvedQrCustomer
    {
        $phone = trim($phone);

        $customer = CustomerIdentity::findOrCreate($companyId, $phone, $phone);

        return $this->forCustomer($customer, $plateNumber);
    }

    /** Keep the bill's selected identity while applying the usual plate delta. */
    public function forCustomer(Customer $customer, ?string $plateNumber): ResolvedQrCustomer
    {
        $companyId = (int) $customer->company_id;
        $normalisedPlate = self::normalisePlate($plateNumber);
        if ($normalisedPlate !== null) {
            $created = DB::table('pos_customer_vehicle_plates')->insertOrIgnore([
                'uuid' => (string) Str::uuid(),
                'company_id' => $companyId,
                'customer_id' => $customer->id,
                'plate_number' => $normalisedPlate,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($created > 0) {
                // Table rounds already hold order/table locks. Touch only after
                // commit, so an attaching device can keep customer-before-table
                // lock order. The callback finishes before the response/delta.
                DB::afterCommit(static fn () => $customer->touch());
            }
        }

        return new ResolvedQrCustomer((int) $customer->id, $normalisedPlate);
    }

    public static function normalisePlate(?string $plateNumber): ?string
    {
        if ($plateNumber === null) {
            return null;
        }

        $normalised = strtoupper((string) preg_replace('/\s+/', ' ', trim($plateNumber)));

        return $normalised !== '' ? $normalised : null;
    }
}
