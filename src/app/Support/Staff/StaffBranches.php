<?php

declare(strict_types=1);

namespace App\Support\Staff;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * LAUNCH-P5 — staff at several branches (owner decision 8).
 *
 * A staff member works at their home branch (pos_staff.branch_id) and at every
 * branch of pos_staff_branches (same company). The home branch always counts,
 * even when the pivot has no row for it yet (a row written before the portal
 * maintains the pivot), so the pivot only ever ADDS branches.
 */
final class StaffBranches
{
    /**
     * Constrain a pos_staff query to staff who work at the branch.
     *
     * @template TQuery of Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public static function worksAt(Builder $query, int $branchId, string $table = 'pos_staff'): Builder
    {
        return $query->where(function ($q) use ($branchId, $table): void {
            $q->where($table.'.branch_id', $branchId)
                ->orWhereExists(function ($pivot) use ($branchId, $table): void {
                    $pivot->selectRaw('1')
                        ->from('pos_staff_branches as staff_branch')
                        ->whereColumn('staff_branch.staff_id', $table.'.id')
                        ->whereColumn('staff_branch.company_id', $table.'.company_id')
                        ->where('staff_branch.branch_id', $branchId);
                });
        });
    }

    public static function staffWorksAt(int $staffId, int $branchId): bool
    {
        return self::worksAt(DB::table('pos_staff')->where('pos_staff.id', $staffId), $branchId)->exists();
    }

    /**
     * Every branch the staff member works at: home first, then the pivot's.
     *
     * @return list<int>
     */
    public static function branchIds(object $staff): array
    {
        $home = (int) $staff->branch_id;
        $others = DB::table('pos_staff_branches')
            ->where('staff_id', (int) $staff->id)
            ->where('company_id', (int) $staff->company_id)
            ->where('branch_id', '!=', $home)
            ->orderBy('branch_id')
            ->pluck('branch_id')
            ->map(static fn ($id): int => (int) $id)
            ->all();

        return array_values(array_unique([$home, ...$others]));
    }
}
