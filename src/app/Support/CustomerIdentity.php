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

    /**
     * LAUNCH-P6 fix order 7 (F-23) — the live customer of this merchant for an
     * Omani number stored in ANY equivalent form (+968XXXXXXXX, 968XXXXXXXX,
     * 00968XXXXXXXX, XXXXXXXX, or its canonical column), merges followed. When
     * several match (duplicates made before phones were normalised), the
     * choice is deterministic: one with a loyalty balance first, then the most
     * recent order, then the lowest id. Stored phones are never rewritten
     * (merging and normalising duplicates is Phase 9). Any other number keeps
     * the exact rule of {@see liveMatch()}.
     */
    public static function equivalentMatch(int $companyId, string $phone): ?Customer
    {
        $canonical = CanonicalPhone::of($phone);
        if ($canonical === null || ! preg_match('/^968([0-9]{8})$/', $canonical, $m)) {
            $match = self::liveMatch($companyId, $phone);

            return $match === null ? null : self::survivor($companyId, (int) $match->id);
        }
        $forms = ['+'.$canonical, $canonical, '00'.$canonical, $m[1]];
        $survivors = Customer::query()->where('company_id', $companyId)
            ->where(fn ($query) => $query->whereIn('phone', $forms)->orWhere('phone_canonical', $canonical))
            ->orderBy('id')->pluck('id')
            ->map(static fn ($id): ?Customer => self::survivor($companyId, (int) $id))->filter()->unique('id')->keyBy('id');
        if ($survivors->count() <= 1) {
            return $survivors->first();
        }
        $ids = $survivors->keys()->all();
        $withBalance = DB::table('pos_loyalty_accounts')->where('company_id', $companyId)->whereIn('customer_id', $ids)
            ->where(fn ($query) => $query->where('point_balance', '>', 0)->orWhere('stamp_count', '>', 0))
            ->pluck('customer_id')->map(static fn ($id): int => (int) $id)->flip();
        $lastOrder = DB::table('pos_orders')->where('company_id', $companyId)->whereIn('customer_id', $ids)
            ->groupBy('customer_id')->selectRaw('customer_id, MAX(id) AS last_order_id')->pluck('last_order_id', 'customer_id');

        return $survivors->sortBy([
            static fn (Customer $a, Customer $b): int => (int) $withBalance->has((int) $b->id) <=> (int) $withBalance->has((int) $a->id),
            static fn (Customer $a, Customer $b): int => (int) ($lastOrder[$b->id] ?? 0) <=> (int) ($lastOrder[$a->id] ?? 0),
            static fn (Customer $a, Customer $b): int => (int) $a->id <=> (int) $b->id,
        ])->first();
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

    /**
     * Caller owns the write transaction. Discover the chain, lock in id order,
     * then resolve again from locked rows. If a concurrent merge extended it,
     * roll back this savepoint before acquiring the enlarged sorted set.
     */
    public static function lockedSurvivor(int $companyId, int $id): ?Customer
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException('Customer write resolution requires a transaction.');
        }
        for ($attempt = 0; ; $attempt++) {
            $ids = [];
            $next = $id;
            while (! isset($ids[$next])) {
                $ids[$next] = $next;
                $row = Customer::withTrashed()->where('company_id', $companyId)->find($next);
                if ($row?->merged_into_customer_id === null) {
                    break;
                }
                $next = (int) $row->merged_into_customer_id;
            }
            try {
                return DB::transaction(function () use ($companyId, $id, $ids): ?Customer {
                    $rows = Customer::withTrashed()->where('company_id', $companyId)
                        ->whereIn('id', $ids)->orderBy('id')->sharedLock()->get()->keyBy('id');
                    $seen = [];
                    $next = $id;
                    while (! isset($seen[$next])) {
                        $seen[$next] = true;
                        if (! isset($ids[$next])) {
                            throw new RuntimeException('Customer merge chain changed while locking.');
                        }
                        $row = $rows->get($next);
                        if ($row === null) {
                            return null;
                        }
                        if ($row->merged_into_customer_id === null) {
                            return $row->trashed() ? null : $row;
                        }
                        $next = (int) $row->merged_into_customer_id;
                    }
                    throw new RuntimeException('Customer merge chain is invalid.');
                });
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== 'Customer merge chain changed while locking.' || $attempt >= 4) {
                    throw $exception;
                }
            }
        }
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
