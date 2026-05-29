<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Device;

use App\Http\Requests\Api\V1\Device\CreateCustomerRequest;
use App\Models\Customer;
use App\Models\CustomerVehiclePlate;
use App\Models\Device;
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
 *   POST /api/v1/device/customers            register (find-or-create on phone)
 *
 * Both scoped to the device's company. Search covers the full customer book
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

        $platesByCustomer = CustomerVehiclePlate::query()
            ->whereIn('customer_id', $customers->pluck('id')->all() ?: [0])
            ->get()
            ->groupBy('customer_id');

        return response()->json([
            'data' => [
                'customers' => $customers->map(fn (Customer $c): array => $this->mapCustomer($c, $platesByCustomer->get($c->id)))->all(),
            ],
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
                CustomerVehiclePlate::firstOrCreate(
                    ['company_id' => $companyId, 'plate_number' => strtoupper(trim((string) $data['plate_number']))],
                    ['uuid' => (string) Str::uuid(), 'customer_id' => $customer->id],
                );
            }

            return $customer;
        });

        $plates = CustomerVehiclePlate::query()->where('customer_id', $customer->id)->get();

        return response()->json([
            'data' => ['customer' => $this->mapCustomer($customer, $plates)],
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
     * @return array<string, mixed>
     */
    private function mapCustomer(Customer $customer, ?Collection $plates): array
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
