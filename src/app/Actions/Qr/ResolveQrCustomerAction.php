<?php

declare(strict_types=1);

namespace App\Actions\Qr;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Resolves the customer identity for a QR checkout inside the order transaction.
 *
 * Phone handling deliberately matches the existing device path: global
 * TrimStrings and no estate-specific normalisation. A soft-deleted customer is
 * restored without changing the staff-authored name or any linked history.
 */
final class ResolveQrCustomerAction
{
    public function handle(int $companyId, string $phone, ?string $plateNumber): ResolvedQrCustomer
    {
        $phone = trim($phone);

        // insertOrIgnore is race-safe on both Postgres and SQLite. It also
        // avoids catching a unique violation inside a Postgres transaction,
        // which would otherwise leave that transaction aborted.
        DB::table('pos_customers')->insertOrIgnore([
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'name' => $phone,
            'phone' => $phone,
            'wallet_balance' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customer = Customer::query()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where('phone', $phone)
            ->lockForUpdate()
            ->first();

        if ($customer === null) {
            throw new RuntimeException('QR customer could not be resolved.');
        }

        if ($customer->trashed()) {
            // Restore only. Public input never renames an existing profile.
            $customer->restore();
        }

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
