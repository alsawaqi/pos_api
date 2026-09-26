<?php

declare(strict_types=1);

namespace App\Actions\Qr;

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
                // Other tills receive the new plate through the customer delta.
                $customer->touch();
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
