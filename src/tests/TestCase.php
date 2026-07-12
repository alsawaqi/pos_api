<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

abstract class TestCase extends BaseTestCase
{
    /**
     * Phase 4 — seed pos_staff rows so the device-sync attribution guard
     * ({@see \App\Actions\Device\Sync\TenantReferenceGuard::assertStaffInTenant})
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
