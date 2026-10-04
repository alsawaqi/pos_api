<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP5Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 fix order 2 (A2, device review M2) — a loyalty redemption is not
 * a manual discount. A P5 order.create that carries a loyalty.redeem block
 * sends the redemption's discount row with source "loyalty"; that row is
 * not checked as discount.manual (no false `missing` row). A "loyalty" row
 * without a loyalty.redeem block, and old builds, are checked as before.
 */
class LoyaltyDiscountRowTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p5Device('mdev_loy');
        $this->p5Product();
        $this->p5Staff(7, 'cashier', '700007');
    }

    /** Review probe C: 0.500 off a 2.000 bill (24.95 % > the cashier's 10 %). @return array<string, mixed> */
    private function order(): array
    {
        return ['subtotal_baisas' => 2000, 'discount_total_baisas' => 500, 'grand_total_baisas' => 1500,
            'lines' => [['product_id' => 1, 'qty' => 2, 'unit_price_baisas' => 1000, 'line_total_baisas' => 2000]],
            'discounts' => [['name' => 'Loyalty redemption', 'amount_baisas' => 500, 'amount_type' => 'fixed', 'source' => 'loyalty']]];
    }

    /** @return list<array{0: string, 1: string}> */
    private function rows(string $uuid): array
    {
        return DB::table('pos_approvals')->where('subject_uuid', $uuid)->orderBy('id')->get(['action', 'result'])
            ->map(fn ($r): array => [$r->action, $r->result])->all();
    }

    public function test_a_redemptions_discount_row_is_not_a_missing_manual_discount(): void
    {
        $uuid = (string) Str::uuid();
        $this->p5Push('mdev_loy', [$this->p5Create($uuid, $this->order(), ['auth_v' => 1,
            'authorizations' => [$this->p5Position('loyalty.redeem', 7, 'loyalty:0')]])])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        // Only the loyalty row (the cashier lacks the loyalty.redeem tick: missing, but as loyalty).
        $this->assertSame([['loyalty.redeem', 'missing']], $this->rows($uuid));
    }

    public function test_a_loyalty_row_without_a_redemption_and_old_builds_are_checked_as_before(): void
    {
        $alone = (string) Str::uuid();
        $this->p5Push('mdev_loy', [$this->p5Create($alone, $this->order(), ['auth_v' => 1])])->assertOk();
        $this->assertSame([['discount.manual', 'missing']], $this->rows($alone));

        $this->p5Device('mdev_old');
        $old = (string) Str::uuid();
        $this->p5Push('mdev_old', [$this->p5Create($old, $this->order())])->assertOk();
        $this->assertSame([['discount.manual', 'legacy']], $this->rows($old));
    }
}
