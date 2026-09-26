<?php

declare(strict_types=1);

use App\Models\Customer;
use App\Models\Device;
use App\Models\Order;
use App\Models\Product;
use App\Models\TableSession;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\TableSessionFixtures;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): never {
    fwrite(STDERR, $error->__toString().PHP_EOL);
    exit(1);
});
if (DB::getDriverName() !== 'pgsql' || DB::connection()->getDatabaseName() !== 'qr_fix6_disposable') {
    throw new RuntimeException('Disposable qr_fix6_disposable PostgreSQL only.');
}
[$self, $mode, $label] = $argv;
$stateFile = '/state/'.$label.'.json';
$check = static function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
$request = function (array $state, string $route, array $payload = [], string $method = 'POST', int $status = 200) use ($app, $check): array {
    $app['auth']->forgetGuards();
    $kernel = $app->make(HttpKernel::class);
    $headers = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    if (str_starts_with($route, 'device/')) {
        $headers['HTTP_AUTHORIZATION'] = 'Bearer '.$state['token'];
    } elseif (isset($state['session'])) {
        $headers['HTTP_X_QR_SESSION'] = $state['session'];
        $headers['HTTP_X_QR_CLIENT_SECRET'] = 'fix6-private-fixture';
    }
    $req = Request::create('/api/v1/'.$route, $method, [], [], [], $headers, json_encode($payload, JSON_THROW_ON_ERROR));
    $response = $kernel->handle($req);
    $kernel->terminate($req, $response);
    $check($response->getStatusCode() === $status, 'HTTP '.$response->getStatusCode().' '.$response->getContent());

    return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
};
if ($mode === 'seed') {
    $company = DB::table('pos_companies')->insertGetId(['uuid' => (string) Str::uuid(), 'name' => 'FIX6 '.$label, 'status' => 'active']);
    $branch = DB::table('pos_branches')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company, 'name' => 'FIX6', 'status' => 'active']);
    $staff = DB::table('pos_staff')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company, 'branch_id' => $branch, 'name' => 'FIX6 staff', 'pin_hash' => 'synthetic', 'position' => 'cashier', 'status' => 'active']);
    $device = Device::factory()->paired()->create(['company_id' => $company, 'branch_id' => $branch, 'device_type' => 'fixed_pos']);
    $station = Device::factory()->paired()->create(['company_id' => $company, 'branch_id' => $branch, 'device_type' => 'payment_station']);
    $product = Product::create(['uuid' => Str::uuid(), 'company_id' => $company, 'name' => 'FIX6 coffee', 'base_price' => '1.000', 'tax_rate' => '0.00', 'stock_mode' => 'untracked', 'status' => 'active']);
    $customer = Customer::create(['uuid' => Str::uuid(), 'company_id' => $company, 'name' => 'FIX6 customer', 'phone' => '90000001']);
    $fixture = new class
    {
        use TableSessionFixtures;

        public function seat(int $company, int $branch, int $device): TableSession
        {
            return $this->seatingRow($this->seatingTable('FIX6 table', $branch, $company), ['opened_by_device_id' => $device]);
        }
    };
    $seat = $fixture->seat($company, $branch, $device->id);
    $state = ['company' => $company, 'branch' => $branch, 'customer' => $customer->id, 'token' => $device->device_token, 'product' => $product->id, 'table_id' => $seat->table_id, 'seating_key' => $seat->client_request_id, 'seating_uuid' => $seat->uuid, 'gate' => 600000 + $company];
    $data = $request($state, 'device/sync/push', ['events' => [[
        'client_event_id' => (string) Str::uuid(), 'event_type' => 'table.session.round', 'client_timestamp' => now()->toIso8601String(),
        'payload' => ['table_id' => $seat->table_id, 'seating_key' => $seat->client_request_id, 'queued_offline' => false, 'client_request_id' => (string) Str::uuid(), 'submitted_at' => now()->toIso8601String(), 'lines' => [['product_id' => $product->id, 'qty' => 1, 'addon_ids' => []]]],
    ]]]);
    $check($data['results'][0]['status'] === 'processed', 'Initial staff round failed');
    $order = Order::findOrFail($seat->fresh()->order_id);
    $order->update(['customer_id' => $customer->id]);
    $state['order_id'] = $order->id;
    $opening = $state;
    $opening['token'] = $station->device_token;
    $open = $request($opening, 'device/qr/open-table', ['table_id' => $seat->table_id], status: 201);
    $bind = $request([], 'public/qr/table-bind', ['table_token' => $open['table_token'], 'client_secret' => 'fix6-private-fixture']);
    $state['session'] = $bind['session_uuid'];
    DB::table('pos_customers')->where('id', $customer->id)->update(['updated_at' => now()->subDays(2)]);
    $state['since'] = now()->subDay()->toIso8601String();
    file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR));
    echo 'SEED '.json_encode(array_diff_key($state, ['token' => true, 'session' => true])).PHP_EOL;
    exit(0);
}
$state = json_decode(file_get_contents($stateFile), true, flags: JSON_THROW_ON_ERROR);
if ($mode === 'round' || $mode === 'round-control') {
    if ($mode === 'round') {
        DB::listen(function ($query) use ($state): void {
            if (str_starts_with($query->sql, 'insert into "pos_customer_vehicle_plates"')) {
                // Test-only pause AFTER the real insert. The action holds its
                // real table/order locks; no production component is replaced.
                DB::select('SELECT pg_advisory_lock(?)', [$state['gate']]);
                DB::select('SELECT pg_advisory_unlock(?)', [$state['gate']]);
            }
        });
    }
    $request($state, 'public/qr/table-round', ['client_request_id' => (string) Str::uuid(), 'phone' => '90000001', 'plate_number' => 'NEW 6', 'lines' => [['product_id' => $state['product'], 'qty' => 1, 'addon_ids' => [], 'notes' => null]]], status: 201);
    echo "ROUND PASS\n";
} elseif ($mode === 'attach') {
    $request($state, 'device/tables/'.$state['seating_uuid'].'/adjust', [
        'table_id' => $state['table_id'], 'seating_key' => $state['seating_key'], 'queued_offline' => false, 'client_request_id' => (string) Str::uuid(),
        'adjustment' => ['kind' => 'customer', 'mode' => 'attach', 'customer_id' => $state['customer']],
    ]);
    echo "ATTACH PASS\n";
} elseif ($mode === 'check') {
    $check(DB::table('pos_customer_vehicle_plates')->where('customer_id', $state['customer'])->where('plate_number', 'NEW 6')->count() === 1, 'Plate missing or duplicate');
    $check((int) Order::findOrFail($state['order_id'])->customer_id === $state['customer'], 'Customer changed');
    $delta = $request($state, 'device/config/delta?since='.urlencode($state['since']), method: 'GET');
    $row = collect($delta['customers'])->firstWhere('id', $state['customer']);
    $check($row !== null && in_array('NEW 6', $row['plates'], true), 'New plate absent from customer delta');
    echo 'DELTA PASS '.json_encode(['customer' => $row['id'], 'plates' => $row['plates']]).PHP_EOL;
} else {
    throw new RuntimeException('Unknown mode');
}
