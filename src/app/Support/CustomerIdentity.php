<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class CustomerIdentity
{
    public static function lock(int $companyId, string $phone): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [
                'customer:'.$companyId.':'.(CanonicalPhone::of($phone) ?? 'raw:'.$phone),
            ]);
        }
    }

    public static function liveMatch(int $companyId, string $phone): ?Customer
    {
        $canonical = CanonicalPhone::of($phone);

        return Customer::query()->where('company_id', $companyId)
            ->where(function ($query) use ($canonical, $phone): void {
                $query->where('phone', $phone);
                if ($canonical !== null) {
                    $query->orWhere('phone_canonical', $canonical);
                }
            })->orderBy('id')->first();
    }

    public static function survivor(int $companyId, int $id, bool $revive = false): ?Customer
    {
        $seen = [];
        while (! isset($seen[$id])) {
            $seen[$id] = true;
            $customer = Customer::withTrashed()->where('company_id', $companyId)->whereKey($id)->first();
            if ($customer === null) {
                return null;
            }
            if ($customer->merged_into_customer_id !== null) {
                $id = (int) $customer->merged_into_customer_id;

                continue;
            }
            if ($customer->trashed()) {
                if (! $revive) {
                    return null;
                }
                $customer->restore();
            }

            return $customer;
        }
        throw new RuntimeException('Customer merge chain is invalid.');
    }

    public static function findOrCreate(int $companyId, string $phone, string $name, bool $renameRevived = false): Customer
    {
        return DB::transaction(function () use ($companyId, $phone, $name, $renameRevived): Customer {
            self::lock($companyId, $phone);
            $customer = self::liveMatch($companyId, $phone);
            if ($customer !== null) {
                return $customer;
            }
            $deleted = Customer::withTrashed()->where('company_id', $companyId)->where('phone', $phone)->first();
            if ($deleted !== null) {
                $customer = self::survivor($companyId, (int) $deleted->id, true);
                if ($customer === null) {
                    throw new RuntimeException('Customer merge survivor could not be resolved.');
                }
                if ($renameRevived && $deleted->merged_into_customer_id === null) {
                    $customer->update(['name' => $name]);
                }

                return $customer;
            }

            return Customer::create([
                'uuid' => (string) Str::uuid(), 'company_id' => $companyId,
                'name' => $name, 'phone' => $phone, 'phone_canonical' => CanonicalPhone::of($phone),
            ]);
        });
    }
}
