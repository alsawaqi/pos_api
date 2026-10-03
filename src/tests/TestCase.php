<?php

namespace Tests;

use App\Actions\Device\Sync\TenantReferenceGuard;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * LAUNCH-P4 — make a company VAT-registered so its active pos_taxes rows
     * apply (an unregistered merchant charges no VAT at all), with the
     * merchant's "menu prices include VAT" switch set explicitly (the
     * product default is true; the pre-P4 suites priced tax on top).
     */
    protected function registerVat(int $companyId = 100, bool $pricesIncludeVat = false, ?string $vatNumber = 'OM1100000001'): void
    {
        DB::table('pos_companies')->updateOrInsert(['id' => $companyId], [
            'vat_number' => $vatNumber, 'vat_registered_at' => '2026-01-01',
        ]);
        DB::table('pos_company_settings')->updateOrInsert(
            ['company_id' => $companyId, 'key' => 'tax.prices_include_vat'],
            ['value' => json_encode($pricesIncludeVat), 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00'],
        );
    }

    /**
     * Phase 4 — seed pos_staff rows so the device-sync attribution guard
     * ({@see TenantReferenceGuard::assertStaffInTenant})
     * accepts the staff_id an event carries. Ids match those the event payloads
     * send; the tenant defaults to the common test company 100 / branch 10.
     *
     * @param  list<int>  $ids
     */
    protected function seedPosStaff(array $ids, int $companyId = 100, int $branchId = 10): void
    {
        $now = now();
        DB::table('pos_staff')->insert(array_map(static fn (int $id): array => [
            'id' => $id,
            'uuid' => (string) Str::uuid(),
            'company_id' => $companyId,
            'branch_id' => $branchId,
            'name' => 'Staff '.$id,
            'pin_hash' => 'x',
            'position' => 'cashier',
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ], $ids));
    }
}
