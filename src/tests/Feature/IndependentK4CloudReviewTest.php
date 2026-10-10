<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Qr\BindQrTableSessionAction;
use App\Actions\Qr\OpenDineInTableAction;
use App\Actions\Qr\QuickOrderCancellationWasteAction;
use App\Actions\Qr\SubmitDineInQrRoundAction;
use App\Kitchen\Configuration;
use App\Kitchen\PreparationEvidence;
use App\Kitchen\Wire;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\QrOrderRound;
use App\Models\QrSession;
use App\Models\TableSession;
use App\Models\TabletOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LaunchP6Fixtures;
use Tests\TestCase;

final class IndependentK4CloudReviewTest extends TestCase
{
    use LaunchP6Fixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ThrottleRequests::class);
        $this->p6Setup();
        config(['kitchen.enabled' => true]);
        $this->till->forceFill(['assignment_activated_at' => now()])->save();
        $c = app(Configuration::class);
        $area = (string) Str::uuid();
        $printer = (string) Str::uuid();
        $c->setRouting(100, [10], 10, ['areas' => [['id' => $area, 'name' => 'Kitchen']],
            'destinations' => [['id' => $printer, 'type' => 'printer', 'name' => 'Test printer', 'address' => '192.0.2.10', 'port' => 9100, 'profile' => 'escpos-unverified', 'paused' => false]],
            'rules' => [], 'all_items' => [], 'fallback' => ['areas' => [$area], 'destinations' => [$printer]]], 'test');
        $c->enroll(100, [10], $this->till->id, str_repeat('a', 64), [$area], 'test');
        $this->handheld->forceFill(['assignment_activated_at' => now()])->save();
        $c->enroll(100, [10], $this->handheld->id, str_repeat('b', 64), [$area], 'test');
        $c->assign(100, [10], $this->till->id, 'test', 'Synthetic old-host isolation');
        $this->activate();
    }

    private function activate(): void
    {
        $b = DB::table('pos_kv2_branches')->where('branch_id', 10)->first();
        DB::table('pos_kv2_branches')->where('id', $b->id)->update(['mode' => 'active', 'activation_state' => 'idle', 'applied_version' => $b->desired_version]);
    }

    private function immediate(): void
    {
        app(Configuration::class)->setPolicy(100, [10], 10, 'customer_tablet', 'immediate', 'test');
        $this->activate();
    }

    private function quickQr(): array
    {
        $station = $this->p6Device('mdev_k4_station', 'payment_station');
        $secret = 'synthetic-k4-qr-secret';
        $session = QrSession::query()->create([
            'uuid' => (string) Str::uuid(), 'company_id' => 100, 'branch_id' => 10, 'device_id' => $station->id,
            'token' => hash('sha256', (string) Str::uuid()), 'token_expires_at' => now()->addMinute(),
            'client_secret_hash' => QrSession::hashClientSecret($secret), 'status' => 'active',
            'bound_at' => now(), 'last_seen_at' => now(), 'expires_at' => now()->addMinutes(30),
        ]);
        $body = ['client_request_id' => (string) Str::uuid(), 'checkout_choice' => 'counter', 'phone' => '90001234',
            'lines' => [$this->p6Line($this->coffee, 2)]];
        $headers = ['X-QR-Session' => $session->uuid, 'X-QR-Client-Secret' => $secret];
        $this->withHeaders($headers)->postJson('/api/v1/public/qr/checkout', $body)->assertCreated();
        $this->withHeaders($headers)->postJson('/api/v1/public/qr/checkout', $body)->assertCreated();

        return [$station, Order::query()->sole()];
    }

    public function test_staff_can_append_to_opted_in_quick_qr_bill_without_source_conflict(): void
    {
        app(Configuration::class)->setPolicy(100,[10],10,'qr_web','manual','review'); app(Configuration::class)->setPolicy(100,[10],10,'staff','immediate','review'); $this->activate();
        [, $order]=$this->quickQr();$body=['client_request_id'=>(string)Str::uuid(),'lines'=>[$this->p6Line($this->cake,1)]];
        $url='/api/v1/device/qr/pending-orders/'.$order->uuid.'/items';
        $this->p6Staff($this->till,7,'POST',$url,$body)->assertOk();
        $this->p6Staff($this->till,7,'POST',$url,$body)->assertOk();
        $this->assertDatabaseCount('pos_kv2_submissions',2);
        $this->assertDatabaseCount('pos_qr_order_rounds',1);
        $this->assertDatabaseHas('pos_kv2_submissions',['source'=>'qr_web','state'=>'waiting_approval']);
        $this->assertDatabaseHas('pos_kv2_submissions',['source'=>'main_pos','state'=>'released']);
        $initial=DB::table('pos_kv2_submissions')->where('source','qr_web')->sole();
        $event=['protocol_version'=>1,'event_id'=>(string)Str::uuid(),'epoch'=>1,'occurred_at'=>now()->toIso8601String(),'action'=>'approve','submission_uuid'=>$initial->uuid,'expected_revision'=>1];
        $this->p6Staff($this->till,7,'POST','/api/v1/device/kitchen-v2/events',$event)->assertOk();
        $this->p6Staff($this->till,7,'POST','/api/v1/device/kitchen-v2/events',$event)->assertOk();
        $this->assertDatabaseCount('pos_kv2_deliveries',2);
    }
    public function test_emit_actual_cloud_packets_for_independent_dart_review(): void
    {
        $this->p6Submit()->assertCreated();$s=DB::table('pos_kv2_submissions')->sole();$work=DB::table('pos_kv2_work')->sole();
        $packets=[];
        $packets[]=$this->p6As($this->till,'GET','/api/v1/device/kitchen-v2/intake')->assertOk()->json();
        foreach(['approve','item_done','undo','cancel'] as $action) {
            $body=['protocol_version'=>1,'event_id'=>(string)Str::uuid(),'epoch'=>1,'occurred_at'=>now()->toIso8601String(),'action'=>$action,'submission_uuid'=>$s->uuid,'expected_revision'=>1];
            if(in_array($action,['item_done','undo'],true))$body+=['area_uuid'=>$work->area_uuid,'line_uuid'=>$work->line_uuid];
            $this->p6Staff($this->till,in_array($action,['item_done','undo'],true)?8:7,'POST','/api/v1/device/kitchen-v2/events',$body)->assertOk();
            $packets[]=$this->p6As($this->till,'GET','/api/v1/device/kitchen-v2/intake')->assertOk()->json();
        }
        file_put_contents((getenv('KITCHEN_REVIEW_OUTPUT') ?: sys_get_temp_dir()).'/cloud-packets.json',json_encode(['packets'=>$packets,'identity'=>['company_id'=>100,'branch_id'=>10,'device_id'=>$this->till->id,'assignment'=>$this->till->assignment_activated_at->toIso8601String(),'epoch'=>1]],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
        $this->assertDatabaseCount('pos_payments',0);
    }

    public function test_operator_review_releases_only_accepted_subset_to_kitchen_once(): void
    {
        $table=$this->seatingTable();$base=['seating_key'=>(string)Str::uuid(),'table_id'=>$table->id,'queued_offline'=>false];
        $opened=$this->p6Staff($this->till,7,'POST','/api/v1/device/tables/open',$base+['opened_at'=>now()->toIso8601String(),'joined_table_ids'=>[]])->assertOk()->json('data');
        DB::table('pos_products')->where('id',$this->cake)->update(['status'=>'inactive']);
        $body=$base+['client_request_id'=>(string)Str::uuid(),'submitted_at'=>now()->toIso8601String(),'lines'=>[$this->p6Line($this->cake),$this->p6Line($this->coffee,2)]];
        $held=$this->p6Staff($this->till,7,'POST','/api/v1/device/tables/'.$opened['table_session_uuid'].'/round',$body)->assertOk()->assertJsonPath('data.needs_review',true)->json('data');
        $this->assertDatabaseCount('pos_kv2_submissions',0);
        $url='/api/v1/device/tables/'.$opened['table_session_uuid'].'/rounds/'.$held['round_id'].'/confirm';
        $this->p6Staff($this->handheld,7,'POST',$url)->assertOk()->assertJsonPath('data.outcome','accepted');
        $this->p6Staff($this->till,7,'POST',$url)->assertOk();
        $this->assertDatabaseCount('pos_kv2_submissions',1);
        $intent=Wire::read(DB::table('pos_kv2_revisions')->sole()->snapshot)['intent'];
        $this->assertSame([$this->coffee],array_column($intent['lines'],'product_id'));
        $this->assertSame('main_pos',$intent['source']);
        $this->assertDatabaseCount('pos_kv2_deliveries',1);
        $this->assertNull(QrOrderRound::query()->sole()->kitchen_printed_at);
        $cancel=$base+['client_request_id'=>(string)Str::uuid(),'product_id'=>$this->coffee,'addon_ids'=>[],'notes'=>null,'qty'=>1,'prepared'=>false,'cancelled_at'=>now()->toIso8601String()];
        $this->p6Staff($this->handheld,7,'POST','/api/v1/device/tables/'.$opened['table_session_uuid'].'/cancel-line',$cancel)->assertOk();
        $this->p6Staff($this->handheld,7,'POST','/api/v1/device/tables/'.$opened['table_session_uuid'].'/cancel-line',$cancel)->assertOk();
        $next=Wire::read(DB::table('pos_kv2_revisions')->where('revision',2)->sole()->snapshot)['intent'];
        $this->assertSame([$this->coffee],array_column($next['lines'],'product_id'));$this->assertSame('1.000000',$next['lines'][0]['quantity']);
        $this->assertSame('dropped_at_review',QrOrderRound::query()->sole()->priced_lines[0]['held_disposition']);
        $this->assertSame(1,DB::table('pos_kv2_deliveries')->where('state','queued')->count());
    }

    public function test_same_financial_round_cannot_enter_once_online_and_again_from_local_recovery(): void
    {
        $table=$this->seatingTable();$base=['seating_key'=>(string)Str::uuid(),'table_id'=>$table->id,'queued_offline'=>false];
        $opened=$this->p6Staff($this->till,7,'POST','/api/v1/device/tables/open',$base+['opened_at'=>now()->toIso8601String(),'joined_table_ids'=>[]])->assertOk()->json('data');
        $body=$base+['client_request_id'=>(string)Str::uuid(),'submitted_at'=>now()->toIso8601String(),'lines'=>[$this->p6Line($this->coffee)]];
        $this->p6Staff($this->till,7,'POST','/api/v1/device/tables/'.$opened['table_session_uuid'].'/round',$body)->assertOk();
        $this->assertDatabaseCount('pos_kv2_submissions',1);
        $domain=(string)Str::uuid();
        $this->p6Staff($this->till,7,'POST','/api/v1/device/sync/push',['events'=>[['client_event_id'=>$domain,'event_type'=>'table.session.round','client_timestamp'=>now()->toIso8601String(),'payload'=>$body]]])->assertOk()->assertJsonPath('data.results.0.status','processed');
        $localOrder=\App\Kitchen\Ids::stable('staff-order:100:10:'.$this->till->id.':'.$base['seating_key']);
        $product=DB::table('pos_products')->where('id',$this->coffee)->first();
        $intent=['protocol_version'=>1,'event_id'=>(string)Str::uuid(),'epoch'=>1,'occurred_at'=>now()->toIso8601String(),'action'=>'submit','submission_uuid'=>(string)Str::uuid(),'order_uuid'=>$localOrder,'round_uuid'=>(string)Str::uuid(),'revision'=>1,'policy_version'=>(int)DB::table('pos_kv2_branches')->where('branch_id',10)->value('applied_version'),'source'=>'main_pos','domain_event_uuid'=>$domain,'domain_state'=>'submitted','context'=>['reference'=>'review local retry'],'lines'=>[['line_uuid'=>(string)Str::uuid(),'product_id'=>$this->coffee,'category_id'=>$product->category_id===null?null:(int)$product->category_id,'quantity'=>'1.000000','name'=>$product->name]]];
        $this->p6Staff($this->till,7,'POST','/api/v1/device/kitchen-v2/submissions',$intent)->assertConflict();
        $this->assertDatabaseCount('pos_kv2_submissions',1);$this->assertDatabaseCount('pos_kv2_deliveries',0);
    }

    private function localThenHeld(bool $alreadyDone=false): array
    {
        app(Configuration::class)->setPolicy(100,[10],10,'staff','immediate','review'); $this->activate();
        $table=$this->seatingTable();$base=['seating_key'=>(string)Str::uuid(),'table_id'=>$table->id,'queued_offline'=>true];
        $opened=$this->p6Staff($this->till,7,'POST','/api/v1/device/tables/open',$base+['opened_at'=>now()->toIso8601String(),'joined_table_ids'=>[]])->assertOk()->json('data');
        $body=$base+['client_request_id'=>(string)Str::uuid(),'submitted_at'=>now()->toIso8601String(),'lines'=>[$this->p6Line($this->cake),$this->p6Line($this->coffee,2)]];
        $domain=(string)Str::uuid();$localOrder=\App\Kitchen\Ids::stable('staff-order:100:10:'.$this->till->id.':'.$base['seating_key']);$lines=[];
        foreach([$this->cake=>1,$this->coffee=>2] as $productId=>$qty){$p=DB::table('pos_products')->where('id',$productId)->first();$lines[]=['line_uuid'=>(string)Str::uuid(),'product_id'=>$productId,'category_id'=>$p->category_id===null?null:(int)$p->category_id,'quantity'=>number_format($qty,6,'.',''),'name'=>$p->name];}
        $intent=['protocol_version'=>1,'event_id'=>(string)Str::uuid(),'epoch'=>1,'occurred_at'=>now()->toIso8601String(),'action'=>'submit','submission_uuid'=>(string)Str::uuid(),'order_uuid'=>$localOrder,'round_uuid'=>(string)Str::uuid(),'revision'=>1,'policy_version'=>(int)DB::table('pos_kv2_branches')->where('branch_id',10)->value('applied_version'),'source'=>'main_pos','domain_event_uuid'=>$domain,'domain_state'=>'submitted','context'=>['reference'=>'review original local'],'lines'=>$lines];
        $this->p6Staff($this->till,7,'POST','/api/v1/device/kitchen-v2/submissions',$intent)->assertCreated();
        $packets=[$this->p6As($this->till,'GET','/api/v1/device/kitchen-v2/intake')->assertOk()->json()];
        if($alreadyDone){$work=DB::table('pos_kv2_work')->where('line_uuid',$lines[1]['line_uuid'])->sole();$this->p6Staff($this->till,8,'POST','/api/v1/device/kitchen-v2/events',['protocol_version'=>1,'event_id'=>(string)Str::uuid(),'epoch'=>1,'occurred_at'=>now()->toIso8601String(),'action'=>'item_done','submission_uuid'=>$intent['submission_uuid'],'expected_revision'=>1,'area_uuid'=>$work->area_uuid,'line_uuid'=>$work->line_uuid])->assertOk();$packets[]=$this->p6As($this->till,'GET','/api/v1/device/kitchen-v2/intake')->assertOk()->json();}
        DB::table('pos_products')->where('id',$this->cake)->update(['status'=>'inactive']);
        $sync=['events'=>[['client_event_id'=>$domain,'event_type'=>'table.session.round','client_timestamp'=>now()->toIso8601String(),'payload'=>$body]]];
        $this->p6Staff($this->till,7,'POST','/api/v1/device/sync/push',$sync)->assertOk()->assertJsonPath('data.results.0.status','processed');
        $round=QrOrderRound::query()->sole();$this->assertTrue((bool)$round->needs_review);
        $this->assertDatabaseHas('pos_kv2_submissions',['uuid'=>$intent['submission_uuid'],'link_state'=>'review_required','state'=>'needs_review']);
        $this->assertSame(0,DB::table('pos_kv2_deliveries')->whereIn('state',['queued','claimed'])->count());
        $event=['protocol_version'=>1,'event_id'=>(string)Str::uuid(),'epoch'=>1,'occurred_at'=>now()->toIso8601String(),'action'=>'item_done','area_uuid'=>DB::table('pos_kv2_work')->where('line_uuid',$lines[0]['line_uuid'])->value('area_uuid'),'line_uuid'=>$lines[0]['line_uuid'],'submission_uuid'=>$intent['submission_uuid'],'expected_revision'=>1];
        $refusal=$this->p6Staff($this->till,8,'POST','/api/v1/device/kitchen-v2/events',$event)->assertConflict(); file_put_contents((getenv('KITCHEN_REVIEW_OUTPUT') ?: sys_get_temp_dir()).'/held-done-refusal.json',json_encode(['status'=>$refusal->status(),'body'=>$refusal->json()],JSON_PRETTY_PRINT));
        $packets[]=$this->p6As($this->till,'GET','/api/v1/device/kitchen-v2/intake')->assertOk()->json();
        return [$intent,$opened,$round,$sync,$packets,$base];
    }
    public function test_original_local_hold_acceptance_keeps_identity_done_and_replay(): void
    {
        [$intent,$opened,$round,$sync,$packets,$base]=$this->localThenHeld(true);
        $url='/api/v1/device/tables/'.$opened['table_session_uuid'].'/rounds/'.$round->id.'/confirm';
        $this->p6Staff($this->handheld,7,'POST',$url)->assertOk();
        $packets[]=$this->p6As($this->till,'GET','/api/v1/device/kitchen-v2/intake')->assertOk()->json();
        $this->p6Staff($this->till,7,'POST',$url)->assertOk();
        $this->p6Staff($this->till,7,'POST','/api/v1/device/sync/push',$sync)->assertOk();
        $this->assertDatabaseCount('pos_kv2_submissions',1);
        $this->assertDatabaseHas('pos_kv2_submissions',['uuid'=>$intent['submission_uuid'],'link_state'=>'linked','revision'=>2,'state'=>'ready']);
        $next=Wire::read(DB::table('pos_kv2_revisions')->where('revision',2)->sole()->snapshot)['intent'];
        $this->assertSame([$intent['lines'][1]['line_uuid']],array_column($next['lines'],'line_uuid'));
        $this->assertDatabaseHas('pos_kv2_work',['line_uuid'=>$intent['lines'][1]['line_uuid'],'revision'=>2,'state'=>'done']);
        $evidence=PreparationEvidence::forOrder(Order::query()->findOrFail($round->order_id));$acceptedItem=(int)$round->fresh()->priced_lines[1]['order_item_id'];$this->assertContains($acceptedItem,$evidence['done'],'Review dropping an earlier line must retain the unchanged Done item financial identity.');
        $cancel=$base+['client_request_id'=>(string)Str::uuid(),'product_id'=>$this->coffee,'addon_ids'=>[],'notes'=>null,'qty'=>1,'prepared'=>false,'cancelled_at'=>now()->toIso8601String()];
        $this->p6Staff($this->handheld,7,'POST','/api/v1/device/tables/'.$opened['table_session_uuid'].'/cancel-line',$cancel)->assertConflict()->assertJsonPath('errors.0.code','kitchen_preparation_review_required');

        $this->assertSame(1,DB::table('pos_kv2_deliveries')->where('state','queued')->count());
        file_put_contents((getenv('KITCHEN_REVIEW_OUTPUT') ?: sys_get_temp_dir()).'/held-cloud-packets.json',json_encode(['packets'=>$packets,'identity'=>['company_id'=>100,'branch_id'=>10,'device_id'=>$this->till->id,'assignment'=>$this->till->assignment_activated_at->toIso8601String(),'epoch'=>1]],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
    }
    public function test_original_local_hold_rejection_cancels_same_submission_without_new_work(): void
    {
        [$intent,$opened,$round,$sync,$packets]=$this->localThenHeld();
        $url='/api/v1/device/tables/'.$opened['table_session_uuid'].'/rounds/'.$round->id.'/reject';
        $this->p6Staff($this->handheld,7,'POST',$url)->assertOk();$this->p6Staff($this->till,7,'POST',$url)->assertOk();
        $this->assertDatabaseCount('pos_kv2_submissions',1);$this->assertDatabaseHas('pos_kv2_submissions',['uuid'=>$intent['submission_uuid'],'link_state'=>'linked','state'=>'cancelled']);
        $this->assertSame(0,DB::table('pos_kv2_deliveries')->whereIn('state',['queued','claimed'])->count());
    }
}
