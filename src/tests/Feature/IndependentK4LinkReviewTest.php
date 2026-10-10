<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\CancelExpiredQuickOrdersAction;
use App\Actions\Qr\QrChargeException;
use App\Actions\Qr\QrDineInException;
use App\Actions\Tables\ClaimKitchenTicketAction;
use App\Kitchen\Access;
use App\Kitchen\Compatibility;
use App\Kitchen\Configuration;
use App\Kitchen\DomainLinkage;
use App\Kitchen\Grants;
use App\Kitchen\KitchenFault;
use App\Kitchen\Routing;
use App\Kitchen\Wire;
use App\Models\Device;
use App\Models\Order;
use App\Models\SyncEvent;
use App\Support\Staff\StaffToken;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\TableSessionFixtures;
use Tests\TestCase;

final class IndependentK4LinkReviewTest extends TestCase
{
    use RefreshDatabase,TableSessionFixtures;

    private Device $device;

    private array $bundle;

    private int $product;

    private string $area;

    private string $printer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        config(['kitchen.enabled' => true]);
        $this->device = $this->seatingDevice(attributes: ['assignment_activated_at' => now()]);
        $this->seedPosStaff([1]);
        DB::table('pos_staff')->where('id', 1)->update(['position' => 'manager']);
        $this->product = $this->seatingProduct()->id;
        $this->area = (string) Str::uuid();
        $this->printer = (string) Str::uuid();
        $this->bundle = ['areas' => [['id' => $this->area, 'name' => 'Grill']], 'destinations' => [['id' => $this->printer, 'type' => 'printer', 'name' => 'Kitchen printer', 'address' => '192.168.100.173', 'port' => 9100, 'profile' => 'escpos-unverified', 'paused' => false]], 'rules' => [], 'all_items' => [], 'fallback' => ['areas' => [$this->area], 'destinations' => [$this->printer]]];
        $c = app(Configuration::class);
        $c->setRouting(100, [10], 10, $this->bundle, 'test-merchant');
        $c->setPolicy(100, [10], 10, 'staff', 'immediate', 'test-merchant');
        $c->enroll(100, [10], $this->device->id, str_repeat('a', 64), [$this->area], 'test-admin');
        $c->assign(100, [10], $this->device->id, 'test-admin', 'All legacy executors stopped in isolated test.');
        $this->activate();
    }

    private function envelope(string $action, array $extra = []): array
    {
        return [...['protocol_version' => 1, 'event_id' => (string) Str::uuid(), 'epoch' => (int) DB::table('pos_kv2_branches')->where('branch_id', 10)->value('epoch'), 'occurred_at' => now()->toIso8601String(), 'action' => $action], ...$extra];
    }

    private function kitchenPost(string $path, array $data, ?Device $device = null, ?int $staff = 1)
    {
        $device ??= $this->device;
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($device->plainTextToken)->withHeader('X-Staff-Token', $staff ? StaffToken::issue($device->id, $staff) : '')->postJson('/api/v1/device/kitchen-v2/'.$path, $data);
    }

    private function kitchenGet(string $path, ?Device $device = null)
    {
        $device ??= $this->device;
        $this->app['auth']->forgetGuards();
        $this->flushHeaders();

        return $this->withToken($device->plainTextToken)->getJson('/api/v1/device/'.$path);
    }

    private function activate(): void
    {
        $c = $this->kitchenGet('kitchen-v2/configuration')->assertOk()->json('data');
        $id = (string) Str::uuid();
        foreach (['prepare_configuration', 'commit_configuration', 'finish_configuration'] as $phase) {
            $this->kitchenPost('configuration/ack', $this->envelope($phase, ['version' => $c['desired_version'], 'hash' => $c['desired']['hash'], 'activation_id' => $id]))->assertOk();
        }
    }

    private function intent(array $extra = []): array
    {
        return $this->envelope('submit', [...['submission_uuid' => (string) Str::uuid(), 'order_uuid' => (string) Str::uuid(), 'round_uuid' => (string) Str::uuid(), 'revision' => 1, 'policy_version' => (int) DB::table('pos_kv2_branches')->where('branch_id', 10)->value('applied_version'), 'source' => 'main_pos', 'domain_event_uuid' => (string) Str::uuid(), 'domain_state' => 'submitted', 'context' => ['reference' => 'TEST-001', 'table' => 'A2'], 'lines' => [['line_uuid' => (string) Str::uuid(), 'product_id' => $this->product, 'category_id' => null, 'quantity' => '2.000000', 'name' => 'Fish', 'name_ar' => 'سمك', 'notes' => 'No salt', 'modifiers' => ['Extra lemon'], 'removals' => [], 'allergens' => ['fish']]]], ...$extra]);
    }

    private function submitted(array $extra = []): array
    {
        $i = $this->intent($extra);
        $this->kitchenPost('submissions', $i)->assertCreated();

        return $i;
    }

    private function action(array $i, string $action, array $extra = []): array
    {
        return $this->envelope($action, [...['submission_uuid' => $i['submission_uuid'], 'expected_revision' => $i['revision']], ...$extra]);
    }


    public function test_financial_review_stops_still_unsent_kitchen_work(): void
    {
        $intent=$this->submitted();$seat=$this->seatingRow($this->seatingTable());$order=$this->seatingOrder($seat);
        $round=$this->seatingRound($seat,$order,['needs_review'=>true,'status'=>'pending_confirmation']);
        DomainLinkage::forEvent(new SyncEvent(['client_event_id'=>$intent['domain_event_uuid']]),$this->device,['order_id'=>$order->id,'round_id'=>$round->id]);
        $this->assertDatabaseHas('pos_kv2_submissions',['uuid'=>$intent['submission_uuid'],'link_state'=>'review_required']);
        $this->assertSame(0,DB::table('pos_kv2_deliveries')->whereIn('state',['queued','claimed'])->count(),'Known-review financial work must not remain printable while operator review is pending.');
    }
}
