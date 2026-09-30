<?php
declare(strict_types=1);
namespace Tests\Feature;
use App\Actions\Device\IngestSyncEventsAction;
use App\Events\DeviceSyncBroadcast;
use App\Models\Device;
use App\Models\SyncEvent;
use App\Support\SyncReceiptFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

class LaunchP0FixOrder2ApiTest extends TestCase
{
 use RefreshDatabase;
 private function release(string $app = 'pos_machine'): array {
  // Exported by executing the release writers against the real T3 copy, or
  // the handheld release session/outbox writers. Never reconstruct the wire.
  return json_decode(file_get_contents(base_path("tests/Fixtures/launch-p0-fix2/$app/payloads.json")),true,512,JSON_THROW_ON_ERROR);
 }
 private function seedRelease(): Device {
  $device=Device::factory()->paired('release-test')->create(['company_id'=>100,'branch_id'=>10]);
  $this->seedPosStaff([7]);
  DB::table('pos_products')->insert(['id'=>1,'uuid'=>Str::uuid(),'company_id'=>100,'name'=>'Release item','base_price'=>1.5,'status'=>'active']);
  DB::table('pos_comp_reasons')->insert(['id'=>2,'uuid'=>Str::uuid(),'company_id'=>100,'code'=>'staff_meal','name'=>'Staff meal','is_active'=>true]);
  return $device;
 }
 private function ingest(Device $d,array $events): array {
  return app(IngestSyncEventsAction::class)->handle($d,$events)['data']['results'];
 }
 public function test_f2_release_cashier_comps_and_gifts_from_both_apps_land(): void {
  $d=$this->seedRelease();
  $this->assertSame('cashier',DB::table('pos_staff')->where('id',7)->value('position'));
  foreach (['pos_machine','pos_handheld'] as $app) {
   $wire=array_values(array_filter($this->release($app),fn($e)=>$e['event_type']==='order.create'));
   foreach ($wire as $e) {
    $this->assertSame(7,$e['payload']['order']['comps'][0]['staff_id']);
    $ack=$this->ingest($d,[$e])[0];
    $this->assertSame('processed',$ack['status'],json_encode($ack));
   }
  }
  $this->assertDatabaseCount('pos_order_comps',4);
 }
 public function test_f2_foreign_comp_staff_is_refused_even_with_an_owned_cashier(): void {
  $d=$this->seedRelease();$this->seedPosStaff([99],200,20);
  $wire=$this->release()[0];$wire['payload']['order']['comps'][0]['staff_id']=99;
  $ack=$this->ingest($d,[$wire])[0];
  $this->assertSame('failed',$ack['status']);
  $this->assertDatabaseCount('pos_orders',0);
 }
 public function test_f8_moved_cashier_and_changed_approver_position_are_flagged_not_refused(): void {
  $d=$this->seedRelease();
  // Simulate a staff move AFTER the frozen release sale; no promotion or
  // preference change is used to make settlement pass.
  DB::table('pos_staff')->where('id',7)->update(['branch_id'=>11]);
  $wire=$this->release()[0];
  $wire['payload']['order']['comps'][0]['approved_by_staff_id']=7;
  $ack=$this->ingest($d,[$wire])[0];
  $this->assertSame('processed',$ack['status'],json_encode($ack));
  $flags=$ack['result']['integrity_flags']??[];
  $this->assertContains('staff_branch_changed:7',$flags);
  $this->assertContains('approver_position_changed:7',$flags);
 }
 public function test_f4_refused_release_backlog_is_reviewable_and_reactivation_keeps_cutoff(): void {
  $d=$this->seedRelease();
  $d->forceFill(['token_issued_at'=>now()->addDay()])->save();
  $wire=$this->release()[0];
  $ack=$this->ingest($d,[$wire])[0];
  $this->assertSame('needs_review',$ack['status']);
  $row=SyncEvent::where('client_event_id',$wire['client_event_id'])->firstOrFail();
  $this->assertSame($wire['payload'],$row->payload_json);
  $this->assertSame($d->id,$row->device_id);
  $this->assertNull($row->company_id);
 }
 public function test_f4_same_identity_new_credential_retains_first_activation_epoch(): void {
  $d=Device::factory()->paired()->create();
  $d->forceFill(['token_issued_at'=>now()->subDay()])->save();
  $epoch=$d->token_issued_at->toIso8601String();$d->issueCredential();
  $this->assertSame($epoch,$d->fresh()->token_issued_at->toIso8601String());
 }
 public function test_f10_exact_release_pay_duplicate_returns_original_paid_result_even_unattributed(): void {
  $d=$this->seedRelease();$wire=$this->release()[1];
  SyncEvent::create(['device_id'=>$d->id,'client_event_id'=>$wire['client_event_id'],
   'event_type'=>$wire['event_type'],'payload_json'=>$wire['payload'],
   'client_timestamp'=>$wire['client_timestamp'],'server_received_at'=>now(),
   'ack_status'=>'processed','result_json'=>['status'=>'paid','receipt_number'=>'RELEASE']]);
  $d->token_issued_at=now()->addDay();
  $this->assertSame('paid',$this->ingest($d,[$wire])[0]['result']['status']??null);
  $wire['payload']['payments'][0]['amount_baisas']++;
  $this->assertNull($this->ingest($d,[$wire])[0]['result']);
  $this->assertDatabaseCount('pos_sync_events',1);
 }
 public function test_f7_reviewed_card_uses_only_review_snapshot_and_never_broadcasts(): void {
  $d=$this->seedRelease();
  $this->assertSame('processed',$this->ingest($d,[$this->release()[0]])[0]['status']);
  $wire=json_decode(file_get_contents(base_path('tests/Fixtures/launch-p0-fix2/pos_machine/card-pay.json')),true);
  $event=SyncEvent::create(['device_id'=>$d->id,'company_id'=>100,'branch_id'=>10,
   'client_event_id'=>$wire['client_event_id'],'event_type'=>$wire['event_type'],
   'payload_json'=>$wire['payload'],'client_timestamp'=>$wire['client_timestamp'],
   'server_received_at'=>now(),'ack_status'=>'needs_review']);
  $review=DB::table('pos_sync_event_reviews')->insertGetId([
   'sync_event_id'=>$event->id,'actor_user_id'=>1,'company_id'=>100,'branch_id'=>10,
   'fingerprint'=>SyncReceiptFingerprint::fingerprint((object)$event->fresh()->getRawOriginal()),
   'reason'=>'Original local release receipt reviewed','status'=>'queued',
   'device_snapshot'=>json_encode(['company_id'=>100,'branch_id'=>10,
    'bank_id'=>null,'terminal_id'=>'ORIGINAL','commission_profile_id'=>null,
    'organization_id'=>null,'device_type'=>'pos_terminal',
    'softpos_profile'=>['provider'=>'dhofar','package'=>'reviewed.original.package']]),
   'created_at'=>now(),'updated_at'=>now()]);
  $d->forceFill(['company_id'=>200,'branch_id'=>20,'terminal_id'=>'NEW'])->save();
  Event::fake([DeviceSyncBroadcast::class]);
  $this->artisan('sync:replay-reviewed',['--review'=>$review])->assertSuccessful();
  $this->assertNull($d->fresh()->card_tenders_blocked_reason);
  $this->assertDatabaseHas('pos_payments',['terminal_id'=>'ORIGINAL','softpos_package'=>'reviewed.original.package']);
  Event::assertNotDispatched(DeviceSyncBroadcast::class);
 }
 public function test_f1_existing_token_can_resolve_identity_without_activation(): void {
  $d=Device::factory()->paired('legacy-token')->create();
  $this->withToken('legacy-token')->getJson('/api/v1/device/identity')->assertOk()
   ->assertJsonPath('data.uuid',$d->uuid)->assertJsonPath('data.company_id',$d->company_id)
   ->assertJsonPath('data.branch_id',$d->branch_id);
 }
 public function test_f1_identity_requires_an_existing_valid_token(): void {
  $this->withToken('invalid-token')->getJson('/api/v1/device/identity')->assertUnauthorized();
 }
}
