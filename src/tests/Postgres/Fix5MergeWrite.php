<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Device;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyRule;
use App\Models\Order;
use App\Models\Product;
use App\Models\TableSession;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TableSessionFixtures;

require_once __DIR__.'/P0DisposableDatabase.php';

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, $error->__toString().PHP_EOL);
    exit(1);
});
if (DB::getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== \p0DisposableDatabase('merge', 'qr_fix4_fix5')) {
    throw new RuntimeException('Disposable qr_fix4_fix5 PostgreSQL only.');
}
[$self, $mode, $label, $kind] = $argv + [null, null, null, null];
$stateFile = (getenv('POS_RACE_STATE_DIR') ?: '/state').'/'.$label.'.json';
$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$request = function (array $state, string $route, array $payload) use ($app, $check): array {
    $app['auth']->forgetGuards();
    $kernel = $app->make(HttpKernel::class);
    $req = Request::create('/api/v1/device/'.$route, 'POST', [], [], [], [
        'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
        'HTTP_AUTHORIZATION' => 'Bearer '.$state['token'],
    ], json_encode($payload, JSON_THROW_ON_ERROR));
    $response = $kernel->handle($req);
    $kernel->terminate($req, $response);
    $json = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);
    $check($response->getStatusCode() === 200, 'HTTP '.$response->getStatusCode().' '.$response->getContent());

    return $json['data'];
};
$push = function (array $state, string $type, array $payload, ?string $id = null) use ($request, $check): array {
    $data = $request($state, 'sync/push', ['events' => [[
        'client_event_id' => $id ?? (string) Str::uuid(), 'event_type' => $type,
        'client_timestamp' => now()->toIso8601String(), 'payload' => $payload,
    ]]]);
    $result = $data['results'][0];
    $check($result['status'] === 'processed', 'Sync failure '.json_encode($result));

    return $result;
};
if ($mode === 'seed') {
    $company = DB::table('pos_companies')->insertGetId(['uuid' => (string) Str::uuid(), 'name' => 'FIX5 '.$label, 'status' => 'active']);
    $branch = DB::table('pos_branches')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company, 'name' => 'FIX5', 'status' => 'active']);
    $staff = DB::table('pos_staff')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company, 'branch_id' => $branch, 'name' => 'FIX5 staff', 'pin_hash' => 'synthetic', 'position' => 'cashier', 'status' => 'active']);
    $device = Device::factory()->paired()->create(['company_id' => $company, 'branch_id' => $branch, 'device_type' => 'fixed_pos']);
    $target = Device::factory()->paired()->create(['company_id' => $company, 'branch_id' => $branch, 'device_type' => 'handheld']);
    $product = Product::create(['uuid' => Str::uuid(), 'company_id' => $company, 'name' => 'FIX5 coffee', 'base_price' => '1.000', 'tax_rate' => '0.00', 'stock_mode' => 'untracked', 'status' => 'active']);
    $survivor = Customer::create(['uuid' => Str::uuid(), 'company_id' => $company, 'name' => 'FIX5 survivor', 'phone' => '+968 9000 0001']);
    $source = Customer::create(['uuid' => Str::uuid(), 'company_id' => $company, 'name' => 'FIX5 source', 'phone' => '90000001']);
    $rule = LoyaltyRule::create(['uuid' => Str::uuid(), 'company_id' => $company, 'name' => 'FIX5 points', 'type' => 'spend_based', 'status' => 'active', 'config_json' => ['points_per_omr' => 0, 'redemption_points' => 100, 'redemption_value' => '0.500']]);
    $account = LoyaltyAccount::create(['uuid' => Str::uuid(), 'company_id' => $company, 'customer_id' => $survivor->id, 'loyalty_rule_id' => $rule->id, 'point_balance' => 200, 'stamp_count' => 0]);
    LoyaltyAccount::create(['uuid' => Str::uuid(), 'company_id' => $company, 'customer_id' => $source->id, 'loyalty_rule_id' => $rule->id, 'point_balance' => 100, 'stamp_count' => 0]);
    $state = ['company' => $company, 'branch' => $branch, 'staff' => $staff, 'device' => $device->id, 'token' => $device->plainTextToken, 'target' => $target->id, 'product' => $product->id,
        'survivor' => $survivor->id, 'source' => $source->id, 'rule' => $rule->id, 'account' => $account->id, 'order_uuid' => (string) Str::uuid(), 'kind' => $kind];
    if (str_starts_with($kind, 'attach')) {
        $fixture = new class
        {
            use TableSessionFixtures;

            public function seat(int $company, int $branch, int $device): TableSession
            {
                return $this->seatingRow($this->seatingTable('FIX5 table', $branch, $company), ['opened_by_device_id' => $device]);
            }
        };
        $seat = $fixture->seat($company, $branch, $device->id);
        $push($state, 'table.session.round', ['table_id' => $seat->table_id, 'seating_key' => $seat->client_request_id, 'queued_offline' => false,
            'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(), 'lines' => [['product_id' => $product->id, 'qty' => 1, 'addon_ids' => []]]]);
        $order = Order::findOrFail($seat->fresh()->order_id);
        $state += ['table_id' => $seat->table_id, 'seating_key' => $seat->client_request_id, 'seating_uuid' => $seat->uuid];
        $state['order_uuid'] = $order->uuid;
    }
    file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR));
    echo 'SEED '.json_encode(array_diff_key($state, ['token' => true])).PHP_EOL;
    exit(0);
}
$state = json_decode(file_get_contents($stateFile), true, flags: JSON_THROW_ON_ERROR);
$kind = $state['kind'];
$adjust = static fn (array $intent): array => ['table_id' => $state['table_id'], 'seating_key' => $state['seating_key'], 'queued_offline' => false, 'client_request_id' => (string) Str::uuid(), 'adjustment' => $intent];
if ($mode === 'write') {
    if (str_starts_with($kind, 'attach')) {
        $payload = $adjust(['kind' => 'customer', 'mode' => 'attach', 'customer_id' => $state['source']]);
        if ($kind === 'attach-http') {
            $request($state, 'tables/'.$state['seating_uuid'].'/adjust', $payload);
        } else {
            $push($state, 'table.session.adjust', $payload);
        }
    } else {
        $payload = ['order' => ['uuid' => $state['order_uuid'], 'order_type' => 'quick', 'source' => 'main_pos', 'staff_id' => $state['staff'], 'opened_at' => now()->toIso8601String(),
            'customer_id' => $state['source'], 'subtotal_baisas' => 1000, 'discount_total_baisas' => 500, 'tax_total_baisas' => 0, 'grand_total_baisas' => 500,
            'lines' => [['product_id' => $state['product'], 'qty' => 1, 'unit_price_baisas' => 1000, 'line_total_baisas' => 1000]], 'discounts' => [['name' => 'Loyalty redemption', 'amount_baisas' => 500]]]];
        if ($kind === 'transfer') {
            $payload['target_device_id'] = $state['target'];
        }
        $push($state, 'order.'.$kind, $payload);
    }
    $stored = Order::where('uuid', $state['order_uuid'])->sole()->customer_id;
    echo 'WRITE '.json_encode(['kind' => $kind, 'stored' => $stored, 'survivor' => $state['survivor'], 'source' => $state['source']]).PHP_EOL;
    $check((int) $stored === $state['survivor'], 'Expected survivor id '.$state['survivor'].', actual '.$stored);
    exit(0);
}
if ($mode === 'pay') {
    if (str_starts_with($kind, 'attach')) {
        $request($state, 'tables/'.$state['seating_uuid'].'/adjust', $adjust(['kind' => 'loyalty', 'mode' => 'redeem', 'rule_id' => $state['rule'], 'blocks' => 1, 'approved_by_staff_id' => $state['staff'], 'authorized_by' => 'FIX5 staff']));
        $request($state, 'qr/claim-settlement', ['order_uuid' => $state['order_uuid']]);
    }
    $pay = ['order_uuid' => $state['order_uuid'], 'paid_at' => now()->toIso8601String(), 'payments' => [['method' => 'cash', 'amount_baisas' => 500]], 'loyalty_redeem' => ['rule_id' => $state['rule'], 'points' => 100, 'stamps' => 0]];
    $id = (string) Str::uuid();
    $push($state, 'order.pay', $pay, $id);
    $push($state, 'order.pay', $pay, $id);
    $account = LoyaltyAccount::findOrFail($state['account']);
    $order = Order::where('uuid', $state['order_uuid'])->sole();
    $debits = DB::table('pos_loyalty_transactions')->where('order_id', $order->id)->where('type', 'redeem')->count();
    echo 'PAY '.json_encode(['status' => $order->status, 'points_before' => 300, 'points_after' => $account->point_balance, 'debits' => $debits]).PHP_EOL;
    $check($order->status === 'paid' && $account->point_balance === 200 && $debits === 1, 'Expected paid, 300 -> 200 and one debit after replay.');
    exit(0);
}
throw new RuntimeException('Unknown mode');
