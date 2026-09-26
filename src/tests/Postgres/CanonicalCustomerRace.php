<?php

declare(strict_types=1);

use App\Actions\Qr\ResolveQrCustomerAction;
use App\Models\Device;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): never {
    fwrite(STDERR, $e->__toString().PHP_EOL);
    exit(1);
});
if (DB::getDriverName() !== 'pgsql' || ! str_starts_with(DB::connection()->getDatabaseName(), 'qr_fix4')) {
    throw new RuntimeException('This contract runs only on a disposable qr_fix4 PostgreSQL database.');
}
if (($argv[1] ?? '') === 'worker') {
    [, , $kind, $company, $token, $gate] = $argv;
    file_put_contents($gate.'.'.$kind, 'ready');
    $deadline = microtime(true) + 20;
    while (! file_exists($gate)) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Worker gate timed out');
        }
        usleep(1000);
    }
    if ($kind === 'device') {
        $kernel = $app->make(HttpKernel::class);
        $request = Request::create('/api/v1/device/customers', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], json_encode(['name' => 'Race synthetic', 'phone' => '90000003']));
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        if ($response->getStatusCode() !== 200) {
            throw new RuntimeException($response->getContent());
        }
        echo json_encode(['id' => json_decode($response->getContent(), true)['data']['customer']['id']]);
    } else {
        echo json_encode(['id' => app(ResolveQrCustomerAction::class)->handle((int) $company, '+968 9000 0003', null)->customerId]);
    }
    exit(0);
}
for ($i = 1; $i <= 20; $i++) {
    $company = DB::table('pos_companies')->insertGetId(['uuid' => (string) Str::uuid(), 'name' => 'FIX4 RACE '.$i, 'status' => 'active']);
    $branch = DB::table('pos_branches')->insertGetId(['uuid' => (string) Str::uuid(), 'company_id' => $company, 'name' => 'Race', 'status' => 'active']);
    $device = Device::factory()->paired()->create(['company_id' => $company, 'branch_id' => $branch]);
    $gate = sys_get_temp_dir().'/fix4-race-'.Str::uuid();
    $workers = [];
    foreach (['device', 'qr'] as $kind) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', $kind, (string) $company, $device->device_token, $gate], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]);
        $workers[$kind] = [$process, $pipes];
    }
    $deadline = microtime(true) + 20;
    while (! file_exists($gate.'.device') || ! file_exists($gate.'.qr')) {
        if (microtime(true) > $deadline) {
            throw new RuntimeException('Workers did not reach the real database barrier');
        }
        usleep(1000);
    }
    touch($gate);
    $ids = [];
    foreach ($workers as $kind => [$process, $pipes]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        if ($code !== 0) {
            throw new RuntimeException($kind.' failed: '.$out.$err);
        }
        $ids[] = json_decode($out, true, flags: JSON_THROW_ON_ERROR)['id'];
    }
    $count = DB::table('pos_customers')->where('company_id', $company)->count();
    echo 'RACE '.json_encode(['pair' => $i, 'ids' => $ids, 'customer_rows' => $count]).PHP_EOL;
    if ($count !== 1 || $ids[0] !== $ids[1]) {
        throw new RuntimeException('Expected one canonical customer across concurrent device and QR writers');
    }
    foreach ([$gate, $gate.'.device', $gate.'.qr'] as $path) {
        unlink($path);
    }
}
echo "PASS: 20 concurrent device/QR pairs; exactly one customer per pair.\n";
