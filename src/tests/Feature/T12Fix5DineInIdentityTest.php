<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Models\Order;
use App\Models\QrSession;
use App\Models\TableSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

/**
 * S1 P3 — SubmitDineInQrRoundAction (WO B1.4 last paragraph): the same number in another format is not a new
 * identity and does not clear a table redemption; a genuinely different number behaves as before.
 * Real routes only (device QR open-table, public table-bind, device table adjust, public table-round).
 * Uses no candidate-only class/column so it also runs on the base export.
 */
final class T12Fix5DineInIdentityTest extends TestCase
{
    use RefreshDatabase;
    use TableSessionFixtures;

    /**
     * @param  array<string, string>  $customers  key => phone, created in this order (ascending ids)
     * @return array<string, mixed>
     */
    private function scenario(array $customers, string $attachedKey, string $roundPhone, ?string $plate = null): array
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->seedPosStaff([7]);
        $ids = [];
        foreach ($customers as $key => $phone) {
            $ids[$key] = (int) Customer::create(['uuid' => (string) Str::uuid(), 'company_id' => 100, 'name' => 'Customer '.$key, 'phone' => $phone])->id;
        }
        $station = $this->seatingDevice('payment_station');
        $till = $this->seatingDevice();
        $product = $this->seatingProduct();
        $table = $this->seatingTable();
        $seat = $this->seatingRow($table, ['opened_by_device_id' => $till->id]);
        $order = $this->seatingOrder($seat, ['customer_id' => $ids[$attachedKey]]);
        $this->seatingRound($seat, $order);
        $this->app['auth']->forgetGuards();
        $open = $this->withToken($station->plainTextToken)->postJson('/api/v1/device/qr/open-table', ['table_id' => $table->id])->assertCreated();
        $bind = $this->postJson('/api/v1/public/qr/table-bind', ['table_token' => $open->json('data.table_token'), 'client_secret' => 's1-table'])->assertOk();
        $this->withHeaders(['X-QR-Session' => $bind->json('data.session_uuid'), 'X-QR-Client-Secret' => 's1-table']);
        $order = Order::whereNotNull('table_session_id')->sole();
        $seat = TableSession::findOrFail($order->table_session_id);
        $rule = LoyaltyRule::create(['uuid' => Str::uuid(), 'company_id' => 100, 'name' => 'S1 points', 'type' => 'spend_based', 'status' => 'active', 'config_json' => ['redemption_points' => 100, 'redemption_value' => '0.500']]);
        foreach ($ids as $id) {
            LoyaltyAccount::create(['uuid' => Str::uuid(), 'company_id' => 100, 'customer_id' => $id, 'loyalty_rule_id' => $rule->id, 'point_balance' => 200, 'stamp_count' => 0]);
        }
        $this->app['auth']->forgetGuards();
        $this->withToken($till->plainTextToken)->postJson('/api/v1/device/tables/'.$seat->uuid.'/adjust', [
            'table_id' => $seat->table_id, 'seating_key' => $seat->uuid, 'queued_offline' => false, 'client_request_id' => (string) Str::uuid(),
            'adjustment' => ['kind' => 'loyalty', 'mode' => 'redeem', 'rule_id' => $rule->id, 'blocks' => 1, 'approved_by_staff_id' => 7, 'authorized_by' => 'S1 staff'],
        ])->assertOk();
        $discounts = fn () => DB::table('pos_order_discounts')->where('order_id', $order->id)->orderBy('id')->get(['id', 'name_snapshot', 'amount'])->map(fn ($r) => (array) $r)->all();
        $before = ['discounts' => $discounts(), 'discount_total' => (string) $order->fresh()->discount_total, 'grand_total' => (string) $order->fresh()->grand_total, 'customer_id' => (int) $order->fresh()->customer_id];
        $lines = [['product_id' => $product->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]];
        $this->app['auth']->forgetGuards();
        $round = $this->postJson('/api/v1/public/qr/table-round', ['client_request_id' => (string) Str::uuid(), 'phone' => $roundPhone, 'plate_number' => $plate, 'lines' => $lines]);
        $after = ['round_status' => $round->status(), 'discounts' => $discounts(), 'discount_total' => (string) $order->fresh()->discount_total,
            'grand_total' => (string) $order->fresh()->grand_total, 'customer_id' => $order->fresh()->customer_id === null ? null : (int) $order->fresh()->customer_id,
            'plates' => DB::table('pos_customer_vehicle_plates')->get(['customer_id', 'plate_number'])->map(fn ($r) => (array) $r)->all(), 'customers_total' => Customer::withTrashed()->count()];
        $measured = ['ids' => $ids, 'attached' => $attachedKey, 'round_phone' => $roundPhone, 'before' => $before, 'after' => $after];
        fwrite(STDOUT, "\nP3 ".json_encode($measured, JSON_UNESCAPED_UNICODE)."\n");

        if ($before['customer_id'] === $after['customer_id']) {
            $this->assertSame('0.500', $after['discount_total']);
            $this->assertSame('1.500', $after['grand_total']);
        }

        return $measured;
    }

    /** The T3 89/90 shape: the bill carries the HIGHER-id duplicate; the table customer types the SAME stored phone. */
    public function test_a_duplicate_pair_same_exact_phone_keeps_redemption(): void
    {
        $m = $this->scenario(['89' => '+968 9000 0001', '90' => '90000001'], '90', '90000001');
        $this->assertSame(201, $m['after']['round_status']);
        $this->assertSame($m['before']['discounts'], $m['after']['discounts'], 'redemption cleared although the phone is byte-identical to the attached customer');
        $this->assertSame($m['ids']['90'], $m['after']['customer_id']);
        $this->assertSame(2, $m['after']['customers_total']);
    }

    /** Same pair; the table customer types the other spelling of the same number. */
    public function test_b_duplicate_pair_other_format_keeps_redemption(): void
    {
        $m = $this->scenario(['89' => '+968 9000 0001', '90' => '90000001'], '90', '+968 9000 0001');
        $this->assertSame(201, $m['after']['round_status']);
        $this->assertSame($m['before']['discounts'], $m['after']['discounts'], 'redemption cleared for the same number in another format');
        $this->assertSame(2, $m['after']['customers_total']);
    }

    /** Lowest-id customer attached; Arabic-Indic and 00 formats of its number. */
    public function test_c_single_customer_arabic_format_keeps_redemption(): void
    {
        $m = $this->scenario(['A' => '+968 9000 0001'], 'A', "\u{0669}\u{0660}\u{0660}\u{0660}\u{0660}\u{0660}\u{0660}\u{0661}");
        $this->assertSame($m['before']['discounts'], $m['after']['discounts']);
        $this->assertSame($m['ids']['A'], $m['after']['customer_id']);
        $this->assertSame(1, $m['after']['customers_total']);
    }

    public function test_d_single_customer_00_format_keeps_redemption(): void
    {
        $m = $this->scenario(['A' => '+968 9000 0001'], 'A', '0096890000001');
        $this->assertSame($m['before']['discounts'], $m['after']['discounts']);
        $this->assertSame($m['ids']['A'], $m['after']['customer_id']);
        $this->assertSame(1, $m['after']['customers_total']);
    }

    /** A genuinely different number still replaces the identity and clears the redemption (as before). */
    public function test_e_different_number_still_clears(): void
    {
        $m = $this->scenario(['A' => '+968 9000 0001'], 'A', '90000009');
        $this->assertSame(201, $m['after']['round_status']);
        $this->assertNotSame($m['before']['discounts'], $m['after']['discounts']);
        $this->assertNotSame($m['ids']['A'], $m['after']['customer_id']);
        $this->assertSame(2, $m['after']['customers_total']);
    }

    public function test_f_same_duplicate_phone_plate_is_saved_on_attached_customer(): void
    {
        $m = $this->scenario(['89' => '+968 9000 0001', '90' => '90000001'], '90', '90000001', ' ab  123 ');
        $this->assertSame(201, $m['after']['round_status']);
        $this->assertSame($m['ids']['90'], $m['after']['customer_id']);
        $this->assertSame($m['before']['discounts'], $m['after']['discounts']);
        $this->assertSame([['customer_id' => $m['ids']['90'], 'plate_number' => 'AB 123']], $m['after']['plates']);
    }

    public function test_g_null_canonical_keeps_only_exact_identity(): void
    {
        $m = $this->scenario(['A' => 'N/A', 'B' => '-'], 'A', 'N/A');
        $this->assertSame(201, $m['after']['round_status']);
        $this->assertSame($m['ids']['A'], $m['after']['customer_id']);
        $this->assertSame($m['before']['discounts'], $m['after']['discounts']);
    }

    public function test_h_different_null_canonical_clears_identity(): void
    {
        $m = $this->scenario(['A' => 'N/A', 'B' => '-'], 'A', '-');
        $this->assertSame(201, $m['after']['round_status']);
        $this->assertSame($m['ids']['B'], $m['after']['customer_id']);
        $this->assertSame('0.000', $m['after']['discount_total']);
        $this->assertSame('2.000', $m['after']['grand_total']);
    }

    public function test_i_brand_new_qr_checkout_still_uses_lowest_duplicate(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $a = Customer::create(['uuid' => Str::uuid(), 'company_id' => 100, 'name' => 'Lowest', 'phone' => '+968 9000 0001']);
        Customer::create(['uuid' => Str::uuid(), 'company_id' => 100, 'name' => 'Higher', 'phone' => '90000001']);
        $station = $this->seatingDevice('payment_station');
        $product = $this->seatingProduct();
        $this->withToken($station->plainTextToken)->postJson('/api/v1/device/qr/rotate')->assertOk();
        $qr = QrSession::whereNull('table_id')->sole();
        $this->postJson('/api/v1/public/qr/bind', ['token' => $qr->token, 'client_secret' => 'fix5-new'])->assertOk();
        $this->withHeaders(['X-QR-Session' => $qr->uuid, 'X-QR-Client-Secret' => 'fix5-new'])->postJson('/api/v1/public/qr/checkout', [
            'client_request_id' => (string) Str::uuid(), 'checkout_choice' => 'counter', 'phone' => '90000001',
            'lines' => [['product_id' => $product->id, 'qty' => 1, 'addon_ids' => [], 'notes' => null]],
        ])->assertCreated();
        $this->assertSame($a->id, Order::sole()->customer_id);
        $this->assertSame(2, Customer::withTrashed()->count());
    }
}
