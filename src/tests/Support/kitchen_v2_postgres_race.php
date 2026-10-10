<?php

use App\Kitchen\Access;
use App\Kitchen\Configuration;
use App\Kitchen\Journal;
use App\Kitchen\KitchenFault;
use App\Kitchen\Submissions;
use App\Models\Device;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

// Disposable DB only. Run with the documented network-disabled container command.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! app()->environment('testing') || config('database.default') !== 'pgsql' || config('database.connections.pgsql.database') !== 'kitchen_k1' || config('database.connections.pgsql.host') !== '/k1-socket') {
    throw new RuntimeException('Disposable PostgreSQL guard refused.');
}
if (! Schema::hasTable('pos_kv2_branches') || DB::table('pos_kv2_events')->exists()) {
    throw new RuntimeException('Prepare a fresh admin-owned test database first.');
}
DB::table('pos_companies')->insert(['id' => 100, 'uuid' => (string) Str::uuid(), 'name' => 'Synthetic race merchant', 'status' => 'active']);
DB::table('pos_branches')->insert(['id' => 10, 'company_id' => 100, 'uuid' => (string) Str::uuid(), 'name' => 'Test kitchen', 'status' => 'active']);
DB::table('pos_products')->insert(['id' => 1, 'company_id' => 100, 'uuid' => (string) Str::uuid(), 'name' => 'Soup', 'status' => 'active', 'base_price' => '1.000']);
$d = Device::factory()->paired()->create(['company_id' => 100, 'branch_id' => 10, 'device_type' => 'fixed_pos', 'assignment_activated_at' => now()]);
$area = (string) Str::uuid();
$printer = (string) Str::uuid();
$c = app(Configuration::class);
$bundle = ['areas' => [['id' => $area, 'name' => 'Kitchen']], 'destinations' => [['id' => $printer, 'name' => 'Test', 'type' => 'printer', 'address' => '127.0.0.1', 'port' => 9100, 'profile' => 'simulated', 'paused' => false]], 'rules' => [], 'all_items' => [], 'fallback' => ['areas' => [$area], 'destinations' => [$printer]]];
$c->setRouting(100, [10], 10, $bundle, 'test');
$c->enroll(100, [10], $d->id, str_repeat('a', 64), [$area], 'test');
$c->assign(100, [10], $d->id, 'test', 'Isolated test with no prior executor.');
DB::table('pos_kv2_branches')->where('branch_id', 10)->update(['mode' => 'active', 'applied_version' => 1]);
$submission = (string) Str::uuid();
$event = ['protocol_version' => 1, 'event_id' => (string) Str::uuid(), 'epoch' => 1, 'occurred_at' => now()->toIso8601String(), 'action' => 'submit', 'submission_uuid' => $submission, 'order_uuid' => (string) Str::uuid(), 'round_uuid' => (string) Str::uuid(), 'revision' => 1, 'policy_version' => 1, 'source' => 'main_pos', 'domain_event_uuid' => (string) Str::uuid(), 'domain_state' => 'submitted', 'context' => ['reference' => 'RACE'], 'lines' => [['line_uuid' => (string) Str::uuid(), 'product_id' => 1, 'category_id' => null, 'quantity' => '1.000000', 'name' => 'Soup']]];
function parallel(array $events, int $deviceId): array
{
    $dir = sys_get_temp_dir().'/k1-race-'.bin2hex(random_bytes(6));
    mkdir($dir, 0700);
    $children = [];
    DB::disconnect();
    foreach ($events as $index => $input) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            try {
                $d = Device::findOrFail($deviceId);
                $branch = DB::table('pos_kv2_branches')->where('branch_id', 10)->first();
                $binding = DB::table('pos_kv2_devices')->where('device_id', $deviceId)->first();
                $a = new Access($d, $branch, $binding, 1);
                $result = app(Journal::class)->apply($a, $input, function ($state) use ($a, $input) {
                    usleep(25000);

                    return $input['action'] === 'submit' ? app(Submissions::class)->submit($a, $state, $input) : app(Submissions::class)->event($a, $state, $input);
                });
                file_put_contents($dir.'/'.$index, json_encode(['ok' => true, 'replayed' => $result['replayed']]));
                exit(0);
            } catch (KitchenFault $e) {
                file_put_contents($dir.'/'.$index, json_encode(['ok' => false, 'reason' => $e->reason]));
                exit(0);
            } catch (Throwable $e) {
                file_put_contents($dir.'/'.$index, json_encode(['error' => get_class($e).':'.$e->getMessage()]));
                exit(1);
            }
        }$children[] = $pid;
    }
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        if (pcntl_wexitstatus($status) !== 0) {
            throw new RuntimeException('Child failure: '.file_get_contents($dir.'/0'));
        }
    }
    $out = [];
    foreach (array_keys($events) as $i) {
        $out[] = json_decode(file_get_contents($dir.'/'.$i), true);
        unlink($dir.'/'.$i);
    }rmdir($dir);
    DB::reconnect();

    return $out;
}
$receipts = parallel(array_fill(0, 6, $event), $d->id);
if (count(array_filter($receipts, fn ($r) => $r['ok'] && ! $r['replayed'])) !== 1 || count(array_filter($receipts, fn ($r) => $r['ok'] && $r['replayed'])) !== 5) {
    throw new RuntimeException(json_encode($receipts));
}
if (DB::table('pos_kv2_submissions')->count() !== 1 || DB::table('pos_kv2_events')->count() !== 1 || DB::table('pos_kv2_deliveries')->count() !== 0) {
    throw new RuntimeException('Duplicate submission.');
}
$approvals = [];
for ($i = 0; $i < 4; $i++) {
    $approvals[] = ['protocol_version' => 1, 'event_id' => (string) Str::uuid(), 'epoch' => 1, 'occurred_at' => now()->toIso8601String(), 'action' => 'approve', 'submission_uuid' => $submission, 'expected_revision' => 1];
}
$results = parallel($approvals, $d->id);
if (count(array_filter($results, fn ($r) => $r['ok'])) !== 1 || DB::table('pos_kv2_deliveries')->count() !== 1 || DB::table('pos_kv2_events')->count() !== 2) {
    throw new RuntimeException('Approval race: '.json_encode($results));
}
echo json_encode(['result' => 'PASS', 'same_event_workers' => 6, 'new_acceptances' => 1, 'replays' => 5, 'approval_workers' => 4, 'approvals' => 1, 'conflicts' => 3, 'submissions' => 1, 'deliveries' => 1, 'journal_events' => 2], JSON_PRETTY_PRINT).PHP_EOL;
