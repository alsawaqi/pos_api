<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\Support\LaunchP5Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 fix order 1 (F7, review M5) — a drawer pay-out whose shift is
 * already closed (it reached the server after the close) adds to the shift's
 * late_payouts_baisas, sets needs_review and writes a note line; the printed
 * Z is not changed and the pay-out result names the shift (late_for_shift).
 * A later close (after a portal re-open) counts it again and sets the figure
 * back to 0.
 */
class LatePayoutTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;

    private string $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p5Device('mdev_lp');
        $this->p5Product();
        $this->p5Staff(9, 'supervisor', '900009');
        $this->shift = (string) Str::uuid();
        $this->p5Push('mdev_lp', [$this->p5Event('shift.open', ['uuid' => $this->shift, 'staff_id' => 9, 'shared_shift' => true,
            'opening_cash_baisas' => 5000, 'opened_at' => now()->subHours(2)->toIso8601String()], at: now()->subHours(2)->toIso8601String())])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');
    }

    private function close(int $reopenCount, int $closing): array
    {
        return $this->p5Push('mdev_lp', [$this->p5Event('shift.close', ['shift_uuid' => $this->shift, 'closing_cash_baisas' => $closing,
            'closed_at' => now()->toIso8601String(), 'closed_by_staff_id' => 9, 'order_uuids' => [], 'auth_v' => 1],
            Uuid::uuid5(Uuid::NAMESPACE_URL, 'shift-close:'.$this->shift.':'.$reopenCount)->toString())])->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed')->json('data.results.0.result');
    }

    /** @param array<string, mixed> $extra */
    private function payout(int $amount, array $extra = []): array
    {
        return $this->p5Push('mdev_lp', [$this->p5Event('expense.log', $extra + ['category' => 'supplies', 'amount_baisas' => $amount,
            'staff_id' => 9, 'paid_from_drawer' => true, 'auth_v' => 1, 'authorization' => $this->p5Position('payout', 9),
            'logged_at' => now()->subMinutes(30)->toIso8601String()])])->assertOk()
            ->assertJsonPath('data.results.0.status', 'processed')->json('data.results.0.result');
    }

    /** @return array{0: int, 1: bool, 2: ?string} */
    private function shiftRow(): array
    {
        $row = DB::table('pos_shifts')->where('uuid', $this->shift)->first();

        return [(int) $row->late_payouts_baisas, (bool) $row->needs_review, $row->note];
    }

    public function test_a_pay_out_that_arrives_after_its_shift_closed_is_a_late_pay_out(): void
    {
        // Review probe 12: the drawer after a 2.500 pay-out holds 2.500.
        $this->assertSame(-2500, $this->close(0, 2500)['variance_baisas']);
        $result = $this->payout(2500);

        $this->assertSame($this->shift, $result['late_for_shift']);
        [$late, $review, $note] = $this->shiftRow();
        $this->assertSame([2500, true], [$late, $review]);
        $this->assertMatchesRegularExpression('/^Late pay-out [0-9a-f-]{36} \(2\.500\)$/', (string) $note);
        // The printed Z is unchanged.
        $this->assertSame('-2.500', number_format((float) DB::table('pos_shifts')->where('uuid', $this->shift)->value('variance'), 3));

        // A second late pay-out (naming the shift) adds up and keeps both note lines.
        $this->payout(1000, ['shift_uuid' => $this->shift]);
        [$late, , $note] = $this->shiftRow();
        $this->assertSame(3500, $late);
        $this->assertSame(2, substr_count((string) $note, 'Late pay-out'));
    }

    public function test_a_pay_out_of_an_open_shift_is_not_late_and_a_re_close_counts_late_ones_again(): void
    {
        $this->assertArrayNotHasKey('late_for_shift', $this->payout(500, ['shift_uuid' => $this->shift]));
        $this->assertSame(0, $this->shiftRow()[0]);
        $this->assertSame(500, $this->close(0, 4500)['summary']['payouts_baisas']);

        $this->payout(2500, ['shift_uuid' => $this->shift]);
        $this->assertSame(2500, $this->shiftRow()[0]);

        // The portal re-opens the shift; the next close counts every pay-out.
        DB::table('pos_shifts')->where('uuid', $this->shift)->update(['status' => 'open', 'closed_at' => null, 'closing_cash' => null,
            'expected_cash' => null, 'variance' => null, 'reopen_count' => 1]);
        $again = $this->close(1, 2000);
        $this->assertSame([2000, 0, 3000], [$again['expected_cash_baisas'], $again['variance_baisas'], $again['summary']['payouts_baisas']]);
        $this->assertSame(0, $this->shiftRow()[0]);
    }
}
