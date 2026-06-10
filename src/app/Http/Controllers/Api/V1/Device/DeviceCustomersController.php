<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Http\Requests\Api\V1\Device\CreateCustomerRequest;
use App\Models\Customer;
use App\Models\CustomerVehiclePlate;
use App\Models\Device;
use App\Models\LoyaltyAccount;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Device customer lookup + registration — blueprint §11.4 / §5.7.3.
 *
 *   GET  /api/v1/device/customers/search?q=  live search by phone, name, plate
 *   GET  /api/v1/device/customers/{id}       customer details (plates + loyalty)
 *   POST /api/v1/device/customers            register (find-or-create on phone)
 *
 * All scoped to the device's company. Search covers the full customer book
 * (beyond the device's cached slice — §9.1.3 "searching beyond the local
 * cache requires network"). Money is integer baisas.
 */
class DeviceCustomersController
{
    public function search(Request $request): JsonResponse
    {
        $device = $this->device($request);
        if (! $device->isAssigned()) {
            return $this->unassigned();
        }

        $validated = $request->validate(['q' => ['required', 'string', 'min:1', 'max:64']]);
        $raw = trim((string) $validated['q']);
        $companyId = $device->company_id;

        // Plate match uses the canonical uppercased stored form.
        $plateCustomerIds = CustomerVehiclePlate::query()
            ->where('company_id', $companyId)
            ->where('plate_number', 'LIKE', '%'.strtoupper($raw).'%')
            ->pluck('customer_id')
            ->all();

        $like = '%'.strtolower($raw).'%';
        $customers = Customer::query()
            ->where('company_id', $companyId)
            ->where(function ($w) use ($like, $plateCustomerIds): void {
                $w->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$like]);
                if ($plateCustomerIds !== []) {
                    $w->orWhereIn('id', $plateCustomerIds);
                }
            })
            ->orderBy('name')
            ->limit(25)
            ->get();

        $customerIds = $customers->pluck('id')->all() ?: [0];
        $platesByCustomer = CustomerVehiclePlate::query()
            ->whereIn('customer_id', $customerIds)
            ->get()
            ->groupBy('customer_id');

        $accountsByCustomer = LoyaltyAccount::query()
            ->whereIn('customer_id', $customerIds)
            ->get()
            ->groupBy('customer_id');

        return response()->json([
            'data' => [
                'customers' => $customers->map(fn (Customer $c): array => $this->mapCustomer($c, $platesByCustomer->get($c->id), $accountsByCustomer->get($c->id)))->all(),
            ],
            'meta' => ['money_unit' => 'baisas'],
            'errors' => [],
        ]);
    }

    /**
     * GET /api/v1/device/customers/{id} — the device's "customer details"
     * fetch (P-F2). Same envelope + shape as search's mapCustomer; 404 with
     * the standard errors[] envelope when the id isn't in the device's
     * company (tenant scope — never leaks another company's book).
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $device = $this->device($request);
        if (! $device->isAssigned()) {
            return $this->unassigned();
        }

        $customer = Customer::query()
            ->where('company_id', $device->company_id)
            ->whereKey($id)
            ->first();

        if ($customer === null) {
            return response()->json([
                'data' => null,
                'errors' => [['code' => 'customer_not_found', 'message' => 'No such customer in this company.']],
            ], 404);
        }

        $plates = CustomerVehiclePlate::query()->where('customer_id', $customer->id)->get();
        $accounts = LoyaltyAccount::query()->where('customer_id', $customer->id)->get();

        return response()->json([
            'data' => ['customer' => $this->mapCustomer($customer, $plates, $accounts)],
            'meta' => ['money_unit' => 'baisas'],
            'errors' => [],
        ]);
    }

    public function store(CreateCustomerRequest $request): JsonResponse
    {
        $device = $this->device($request);
        if (! $device->isAssigned()) {
            return $this->unassigned();
        }

        $data = $request->validated();
        $companyId = $device->company_id;

        $customer = DB::transaction(function () use ($data, $companyId): Customer {
            // Find-or-create on phone (the natural key) so the POS can't
            // spawn a duplicate customer for a returning phone number.
            $customer = Customer::query()
                ->where('company_id', $companyId)
                ->where('phone', $data['phone'])
                ->first();

            if ($customer === null) {
                $customer = Customer::create([
                    'uuid' => (string) Str::uuid(),
                    'company_id' => $companyId,
                    'name' => $data['name'],
                    'phone' => $data['phone'],
                ]);
            }

            if (! empty($data['plate_number'])) {
                // P-F2 — plates are many-to-many: keyed on (company,
                // CUSTOMER, plate) the plate ATTACHES TO THIS CUSTOMER as
                // an additional link even when it already belongs to
                // someone else (family car, several loyalty members).
                // Re-posting the same customer+plate is a no-op.
                $plate = CustomerVehiclePlate::firstOrCreate(
                    [
                        'company_id' => $companyId,
                        'customer_id' => $customer->id,
                        'plate_number' => strtoupper(trim((string) $data['plate_number'])),
                    ],
                    ['uuid' => (string) Str::uuid()],
                );

                // Delta-freshness: /device/config/delta filters customers
                // by updated_at — without this touch a newly attached
                // plate would never reach OTHER devices until something
                // else edited the customer row.
                if ($plate->wasRecentlyCreated) {
                    $customer->touch();
                }
            }

            return $customer;
        });

        $plates = CustomerVehiclePlate::query()->where('customer_id', $customer->id)->get();
        $accounts = LoyaltyAccount::query()->where('customer_id', $customer->id)->get();

        return response()->json([
            'data' => ['customer' => $this->mapCustomer($customer, $plates, $accounts)],
            'errors' => [],
        ]);
    }

    private function device(Request $request): Device
    {
        /** @var Device $device */
        $device = $request->user();

        return $device;
    }

    /**
     * @param  Collection<int, CustomerVehiclePlate>|null  $plates
     * @param  Collection<int, LoyaltyAccount>|null  $accounts
     * @return array<string, mixed>
     */
    private function mapCustomer(Customer $customer, ?Collection $plates, ?Collection $accounts = null): array
    {
        return [
            'id' => (int) $customer->id,
            'uuid' => $customer->uuid,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'wallet_balance_baisas' => Money::toBaisas($customer->wallet_balance ?? 0),
            'plates' => ($plates ?? new Collection)
                ->map(fn (CustomerVehiclePlate $p): string => $p->plate_number)
                ->values()
                ->all(),
            // Live loyalty balances per rule (NOT in the config bundle — volatile).
            'loyalty' => ($accounts ?? new Collection)
                ->map(fn (LoyaltyAccount $a): array => [
                    'rule_id' => (int) $a->loyalty_rule_id,
                    'points' => (int) $a->point_balance,
                    'stamps' => (int) $a->stamp_count,
                ])
                ->values()
                ->all(),
        ];
    }

    private function unassigned(): JsonResponse
    {
        return response()->json([
            'data' => null,
            'errors' => [['code' => 'device_unassigned', 'message' => 'This device is not assigned to a branch.']],
        ], 409);
    }
}
