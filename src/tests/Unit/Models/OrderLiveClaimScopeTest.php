<?php

declare(strict_types=1);

namespace Tests\Unit\Models;

use App\Models\Order;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class OrderLiveClaimScopeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function order(array $attributes): Order
    {
        return Order::create(array_merge([
            'uuid' => (string) Str::uuid(),
            'company_id' => 100,
            'branch_id' => 10,
            'order_type' => 'quick',
            'status' => Order::STATUS_AWAITING_PAYMENT,
            'source' => 'main_pos',
            'opened_at' => now(),
        ], $attributes));
    }

    public function test_with_live_claim_matches_the_complete_lc_truth_table(): void
    {
        $at = CarbonImmutable::parse('2026-08-26 12:00:00');
        $cases = [
            'claimed_at null' => [
                'attributes' => [
                    'charge_claimed_at' => null,
                    'charge_deadline_at' => $at->addMinute(),
                    'charge_outcome' => Order::CHARGE_OUTCOME_APPROVED,
                ],
                'live' => false,
            ],
            'null outcome and future deadline' => [
                'attributes' => [
                    'charge_claimed_at' => $at->subMinute(),
                    'charge_deadline_at' => $at->addMinute(),
                    'charge_outcome' => null,
                ],
                'live' => true,
            ],
            'null outcome and past deadline' => [
                'attributes' => [
                    'charge_claimed_at' => $at->subMinutes(2),
                    'charge_deadline_at' => $at->subMinute(),
                    'charge_outcome' => null,
                ],
                'live' => false,
            ],
            'declined' => [
                'attributes' => [
                    'charge_claimed_at' => $at->subMinute(),
                    'charge_deadline_at' => $at->addMinute(),
                    'charge_outcome' => Order::CHARGE_OUTCOME_DECLINED,
                ],
                'live' => false,
            ],
            'cancelled' => [
                'attributes' => [
                    'charge_claimed_at' => $at->subMinute(),
                    'charge_deadline_at' => $at->addMinute(),
                    'charge_outcome' => Order::CHARGE_OUTCOME_CANCELLED,
                ],
                'live' => false,
            ],
            'uncertain regardless of deadline' => [
                'attributes' => [
                    'charge_claimed_at' => $at->subMinutes(2),
                    'charge_deadline_at' => $at->subMinute(),
                    'charge_outcome' => Order::CHARGE_OUTCOME_UNCERTAIN,
                ],
                'live' => true,
            ],
            'approved regardless of deadline' => [
                'attributes' => [
                    'charge_claimed_at' => $at->subMinutes(2),
                    'charge_deadline_at' => $at->subMinute(),
                    'charge_outcome' => Order::CHARGE_OUTCOME_APPROVED,
                ],
                'live' => true,
            ],
        ];

        foreach ($cases as $label => $case) {
            $order = $this->order($case['attributes']);

            $this->assertSame(
                $case['live'],
                Order::query()->whereKey($order->getKey())->withLiveClaim($at)->exists(),
                $label,
            );
        }
    }

    public function test_without_live_claim_matches_the_inverse_truth_table_but_excludes_held_orders(): void
    {
        $at = CarbonImmutable::parse('2026-08-26 12:00:00');
        $cases = [
            'claimed_at null' => [
                'attributes' => [
                    'charge_claimed_at' => null,
                    'charge_deadline_at' => $at->addMinute(),
                    'charge_outcome' => Order::CHARGE_OUTCOME_APPROVED,
                ],
                'without_live_claim' => true,
            ],
            'null outcome and future deadline' => [
                'attributes' => [
                    'charge_claimed_at' => $at->subMinute(),
                    'charge_deadline_at' => $at->addMinute(),
                    'charge_outcome' => null,
                ],
                'without_live_claim' => false,
            ],
            'null outcome and past deadline' => [
                'attributes' => [
                    'charge_claimed_at' => $at->subMinutes(2),
                    'charge_deadline_at' => $at->subMinute(),
                    'charge_outcome' => null,
                ],
                'without_live_claim' => true,
            ],
            'declined' => [
                'attributes' => [
                    'charge_claimed_at' => $at->subMinute(),
                    'charge_deadline_at' => $at->addMinute(),
                    'charge_outcome' => Order::CHARGE_OUTCOME_DECLINED,
                ],
                'without_live_claim' => true,
            ],
            'cancelled' => [
                'attributes' => [
                    'charge_claimed_at' => $at->subMinute(),
                    'charge_deadline_at' => $at->addMinute(),
                    'charge_outcome' => Order::CHARGE_OUTCOME_CANCELLED,
                ],
                'without_live_claim' => true,
            ],
            'uncertain regardless of deadline' => [
                'attributes' => [
                    'charge_claimed_at' => $at->subMinutes(2),
                    'charge_deadline_at' => $at->subMinute(),
                    'charge_outcome' => Order::CHARGE_OUTCOME_UNCERTAIN,
                ],
                'without_live_claim' => false,
            ],
            'approved regardless of deadline' => [
                'attributes' => [
                    'charge_claimed_at' => $at->subMinutes(2),
                    'charge_deadline_at' => $at->subMinute(),
                    'charge_outcome' => Order::CHARGE_OUTCOME_APPROVED,
                ],
                'without_live_claim' => false,
            ],
        ];

        foreach ($cases as $label => $case) {
            $order = $this->order($case['attributes']);

            $this->assertSame(
                $case['without_live_claim'],
                Order::query()->whereKey($order->getKey())->withoutLiveClaim($at)->exists(),
                $label,
            );
        }

        $held = $this->order([
            'status' => Order::STATUS_HELD,
            'charge_claimed_at' => null,
            'charge_deadline_at' => null,
            'charge_outcome' => null,
        ]);

        $this->assertFalse(
            Order::query()->whereKey($held->getKey())->withoutLiveClaim($at)->exists(),
        );
    }

    public function test_charge_fields_have_wire_safe_casts(): void
    {
        $order = $this->order([
            'charge_amount_baisas' => '4750',
            'charge_claimed_at' => '2026-08-26 12:00:00',
            'charge_deadline_at' => '2026-08-26 12:01:00',
        ])->fresh();

        $this->assertIsInt($order->charge_amount_baisas);
        $this->assertSame(4750, $order->charge_amount_baisas);
        $this->assertInstanceOf(CarbonInterface::class, $order->charge_claimed_at);
        $this->assertInstanceOf(CarbonInterface::class, $order->charge_deadline_at);
    }
}
