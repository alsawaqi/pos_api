<?php

declare(strict_types=1);

namespace Tests\Feature\LaunchP6;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LaunchP6Fixtures;
use Tests\TestCase;

/**
 * LAUNCH-P6 fix order 1 (F-5) — the submit cannot be used to read a phone's
 * points past the lookup's 10 per minute: a submit carrying a phone spends
 * the same budget, and every points refusal at submit is one answer.
 * (Throttling stays ON in this class.)
 */
final class TabletFixOrder1LimiterTest extends TestCase
{
    use LaunchP6Fixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p6Setup();
    }

    public function test_f5_lookups_and_submits_with_a_phone_share_ten_per_minute_per_tablet(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->p6As($this->tablet, 'POST', '/api/v1/device/tablet/loyalty/lookup', ['phone' => '91234567'])->assertOk();
        }
        for ($i = 0; $i < 4; $i++) {
            $this->p6Submit(['phone' => '91234567'])->assertCreated();
        }
        $this->p6Submit(['phone' => '91234567'])->assertStatus(429)->assertJsonPath('errors.0.code', 'rate_limited');
        $this->p6As($this->tablet, 'POST', '/api/v1/device/tablet/loyalty/lookup', ['phone' => '91234567'])->assertStatus(429);
        // Without a phone the submit is not limited by it.
        $this->p6Submit()->assertCreated();
        $this->p6Submit()->assertCreated();
    }

    public function test_f5_every_points_refusal_at_submit_is_the_same_answer(): void
    {
        $rule = $this->p6Rule();
        $customer = $this->p6Customer('+96891234567');
        $this->p6Account($customer, $rule, 250);
        $answers = [];
        foreach ([
            ['phone' => '91234567', 'redeem_request' => ['rule_id' => $this->p6Rule(200), 'blocks' => 1]],   // another merchant's reward
            ['phone' => '91234567', 'redeem_request' => ['rule_id' => $rule, 'blocks' => 3]],               // more than the balance
            ['phone' => '91234567', 'redeem_request' => ['rule_id' => $rule, 'blocks' => 2],
                'lines' => [$this->p6Line($this->coffee)]],                                                  // leaves nothing to pay
            ['redeem_request' => ['rule_id' => $rule, 'blocks' => 1]],                                       // no phone
            ['phone' => '99000000', 'redeem_request' => ['rule_id' => $rule, 'blocks' => 1]],               // unknown phone
        ] as $case) {
            $response = $this->p6Submit($case + ['payment' => 'points']);
            $answers[] = [$response->status(), $response->json('errors.0.code'), $response->json('errors.0.message'), $response->json('data')];
        }
        $this->assertSame(array_fill(0, 5, [409, 'redeem_not_available', "These points can't be used for this order.", null]), $answers);
    }
}
