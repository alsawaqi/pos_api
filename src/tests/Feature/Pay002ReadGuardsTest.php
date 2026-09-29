<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Pay002ReadGuardsTest extends TestCase
{
    use RefreshDatabase;

    public static function nonAttended(): array
    {
        return [
            ['payment_station', 10], ['customer_tablet', 10], ['fixed_pos', null],
        ];
    }

    #[DataProvider('nonAttended')]
    public function test_both_reads_refuse_non_attended_devices_before_lookup_or_writes(string $type, ?int $branch): void
    {
        Device::factory()->paired('read-guard')->create([
            'company_id' => 100, 'branch_id' => $branch, 'device_type' => $type,
        ]);
        $this->withToken('read-guard');
        foreach (['/payments/reversals', '/orders/'.Str::uuid().'/payments'] as $path) {
            $this->getJson('/api/v1/device'.$path)->assertStatus($branch === null ? 401 : 409)
                ->assertJsonPath('errors.0.code', $branch === null ? 'device_reactivation_required' : 'device_not_attended');
        }
        $this->assertDatabaseCount('pos_payment_reversals', 0);
        $this->assertDatabaseCount('pos_payment_reversal_results', 0);
        $this->assertDatabaseCount('pos_payments', 0);
    }

    public function test_blocked_attended_devices_can_still_read_restart_recovery(): void
    {
        foreach (['fixed_pos', 'handheld'] as $type) {
            Device::factory()->paired($type)->create([
                'company_id' => 100, 'branch_id' => 10, 'device_type' => $type,
                'card_tenders_blocked_reason' => 'softpos_mismatch',
            ]);
            app('auth')->forgetGuards();
            $this->withToken($type)->getJson('/api/v1/device/payments/reversals')
                ->assertOk()->assertJsonCount(0, 'data.reversals');
        }
        $this->assertSame(0, DB::table('pos_payment_reversal_results')->count());
    }
}
