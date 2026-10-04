<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use App\Models\Device;
use App\Support\Staff\PositionPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP5Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 fix order 1 (F9, review M7) — the comp tick covers a comp with a
 * reason WITHIN the reason's cap only. Above the cap it is never
 * position_ok (missing, reason `above_cap`): it needs an approval. The sale
 * is still accepted (and still flagged comp_over_cap).
 */
class CompCapTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->device = $this->p5Device('mdev_cap');
        $this->p5Product();
        $this->p5Staff(8, 'manager', '800008', verifier: true);
        $this->p5Staff(9, 'supervisor', '900009');
        // The merchant gives supervisors the comp tick.
        $matrix = PositionPermissions::defaults();
        $matrix['supervisor']['actions']['comp'] = true;
        DB::table('pos_company_settings')->insert(['company_id' => 100, 'key' => 'position_permissions', 'value' => json_encode($matrix),
            'created_at' => now(), 'updated_at' => now()]);
        DB::table('pos_comp_reasons')->insert(['id' => 2, 'uuid' => (string) Str::uuid(), 'company_id' => 100,
            'code' => 'staff_meal', 'name' => 'Staff Meal', 'is_active' => true, 'max_amount' => 1.000, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param list<array<string, mixed>> $blocks */
    private function comp(int $amount, array $blocks, ?string $uuid = null): string
    {
        $uuid ??= (string) Str::uuid();
        $this->p5Push('mdev_cap', [$this->p5Create($uuid, ['staff_id' => 9, 'comp_total_baisas' => $amount, 'grand_total_baisas' => 10000 - $amount,
            'comps' => [['comp_reason_id' => 2, 'amount_baisas' => $amount, 'staff_id' => 9]]], ['auth_v' => 1, 'authorizations' => $blocks])])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        return $uuid;
    }

    /** @return array{0: string, 1: ?string, 2: ?int} */
    private function verdict(string $uuid): array
    {
        $row = DB::table('pos_approvals')->where('subject_uuid', $uuid)->sole();
        $order = (int) DB::table('pos_orders')->where('uuid', $uuid)->value('id');
        $approver = DB::table('pos_order_comps')->where('order_id', $order)->value('approved_by_pos_staff_id');

        return [$row->result, $row->reason, $approver === null ? null : (int) $approver];
    }

    public function test_the_comp_tick_covers_a_comp_within_its_reasons_cap_only(): void
    {
        // Review probe 10: 8.000 on a reason capped at 1.000.
        $over = $this->comp(8000, [$this->p5Position('comp', 9, 'comp:0')]);
        $this->assertSame(['missing', 'above_cap', null], $this->verdict($over));

        $within = $this->comp(1000, [$this->p5Position('comp', 9, 'comp:0')]);
        $this->assertSame(['position_ok', null, 9], $this->verdict($within));
    }

    public function test_a_comp_above_the_cap_with_a_managers_approval_is_verified(): void
    {
        $uuid = (string) Str::uuid();
        $this->comp(8000, [$this->p5Approval($this->device, 'comp', 8, 9, $uuid, 8000, 'comp:0')], $uuid);
        $this->assertSame(['verified', null, 8], $this->verdict($uuid));
    }
}
