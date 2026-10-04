<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP5;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP5Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P5 fix order 1 (F4, review M2) — exact proofs. A proof is checked
 * against its item's own subject, amount and ref only (the contract table);
 * the one empty amount is a gift TENDER's block inside order.create. A block
 * whose ref names no item is failed `ref_mismatch`, and the item stays
 * `missing`.
 */
class ExactProofTest extends TestCase
{
    use LaunchP5Fixtures;
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->device = $this->p5Device('mdev_exact');
        $this->p5Product();
        $this->p5Staff(7, 'cashier', '700007');
        $this->p5Staff(8, 'manager', '800008', verifier: true);
    }

    /** @return list<array{0: string, 1: string, 2: ?string}> */
    private function verdicts(string $uuid): array
    {
        return DB::table('pos_approvals')->where('subject_uuid', $uuid)->orderBy('id')->get(['ref', 'result', 'reason'])
            ->map(fn ($r): array => [(string) $r->ref, $r->result, $r->reason])->all();
    }

    public function test_an_approval_for_a_small_discount_never_verifies_a_big_one(): void
    {
        // Review probe 8: discount:0 = 50.000 (gated), discount:1 = 1.000 (not
        // gated); the manager's proof was made over 1.000.
        $uuid = (string) Str::uuid();
        $order = ['subtotal_baisas' => 100000, 'discount_total_baisas' => 51000, 'grand_total_baisas' => 49000,
            'lines' => [['product_id' => 1, 'qty' => 100, 'unit_price_baisas' => 1000, 'line_total_baisas' => 100000]],
            'discounts' => [['name' => 'Manual', 'amount_baisas' => 50000], ['name' => 'Manual', 'amount_baisas' => 1000]]];
        $block = $this->p5Approval($this->device, 'discount.manual', 8, 7, $uuid, 1000, 'discount:0');
        $this->p5Push('mdev_exact', [$this->p5Create($uuid, $order, ['auth_v' => 1, 'authorizations' => [$block]])])
            ->assertOk()->assertJsonPath('data.results.0.status', 'processed');

        $this->assertSame([['discount:0', 'failed', 'bad_proof']], $this->verdicts($uuid));
        $this->assertSame('50.000', number_format((float) DB::table('pos_approvals')->value('amount'), 3));
    }

    public function test_an_empty_subject_or_amount_never_stands_in_for_the_items_own(): void
    {
        $order = fn (): array => ['discount_total_baisas' => 3000, 'grand_total_baisas' => 7000,
            'discounts' => [['name' => 'Manual', 'amount_baisas' => 3000]]];
        foreach (['no subject' => [null, 3000], 'no amount' => ['self', null]] as $case => [$subject, $amount]) {
            $uuid = (string) Str::uuid();
            $block = $this->p5Approval($this->device, 'discount.manual', 8, 7, $subject === 'self' ? $uuid : null, $amount, 'discount:0');
            $this->p5Push('mdev_exact', [$this->p5Create($uuid, $order(), ['auth_v' => 1, 'authorizations' => [$block]])])->assertOk();
            $this->assertSame([['discount:0', 'failed', 'bad_proof']], $this->verdicts($uuid), $case);
        }

        // A void proof without the order's uuid.
        $paid = (string) Str::uuid();
        $this->p5Push('mdev_exact', [$this->p5Create($paid), $this->p5Pay($paid, [['method' => 'cash', 'amount_baisas' => 10000]])])->assertOk();
        $this->p5Push('mdev_exact', [$this->p5Event('order.void', ['order_uuid' => $paid, 'staff_id' => 7, 'auth_v' => 1,
            'authorization' => $this->p5Approval($this->device, 'order.void_paid', 8, 7, null)])])->assertOk();
        $this->assertSame([['', 'failed', 'bad_proof']], $this->verdicts($paid));
    }

    public function test_a_block_must_name_its_items_ref(): void
    {
        // The block for the gated discount carries another index: the item is
        // missing and the stray block is failed ref_mismatch.
        $uuid = (string) Str::uuid();
        $block = $this->p5Approval($this->device, 'discount.manual', 8, 7, $uuid, 3000, 'discount:5');
        $this->p5Push('mdev_exact', [$this->p5Create($uuid, ['discount_total_baisas' => 3000, 'grand_total_baisas' => 7000,
            'discounts' => [['name' => 'Manual', 'amount_baisas' => 3000]]], ['auth_v' => 1, 'authorizations' => [$block]])])->assertOk();

        $this->assertSame([['discount:0', 'missing', 'no_authorization'], ['discount:5', 'failed', 'ref_mismatch']], $this->verdicts($uuid));
    }

    public function test_a_gift_tender_on_order_create_has_an_empty_amount_only(): void
    {
        // With the whole bill as its amount the proof is not the contract's.
        $uuid = (string) Str::uuid();
        $this->p5Push('mdev_exact', [$this->p5Create($uuid, [], ['auth_v' => 1,
            'authorizations' => [$this->p5Approval($this->device, 'gift', 8, 7, $uuid, 10000, 'tender:0')]])])->assertOk();
        $this->assertSame([['tender:0', 'failed', 'bad_proof']], $this->verdicts($uuid));

        $empty = (string) Str::uuid();
        $this->p5Push('mdev_exact', [$this->p5Create($empty, [], ['auth_v' => 1,
            'authorizations' => [$this->p5Approval($this->device, 'gift', 8, 7, $empty, null, 'tender:0')]])])->assertOk();
        $this->assertSame([['tender:0', 'verified', null]], $this->verdicts($empty));
    }
}
