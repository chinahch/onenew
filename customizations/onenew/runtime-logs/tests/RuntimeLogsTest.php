<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\{User,ServerMachine};
use App\Services\AuthService;
use App\Onenew\RuntimeLogs\{LogFilter,Service};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
final class RuntimeLogsTest extends TestCase
{
 use RefreshDatabase;
 private function machine():ServerMachine{return ServerMachine::create(['name'=>'Fixture machine','token'=>Str::random(32),'is_active'=>true]);}
 private function auth(bool $admin=true):array{$u=User::create(['email'=>Str::random(12).'@example.test','password'=>'hashed','uuid'=>(string)Str::uuid(),'token'=>Str::random(32),'is_admin'=>$admin]);$this->app['auth']->forgetGuards();return ['Authorization'=>(new AuthService($u))->generateAuthData()['auth_data']];}
 private function batch(ServerMachine $m):array{return ['machine_id'=>$m->id,'deployment_id'=>(string)Str::uuid(),'batch_id'=>(string)Str::uuid(),'first_seq'=>1,'version'=>'v0.1.13','dropped'=>3,'entries'=>[['seq'=>1,'at'=>time(),'level'=>'info','message'=>'runtime starting'],['seq'=>2,'at'=>time(),'level'=>'error','message'=>'listen tcp 2001:db8::1:25051 user_id=123 password=bad-secret: bind: address already in use']]];}
 public static function missingTables():array{return array_map(fn($name)=>['onenew_runtime_log_'.$name],['entries','streams','batches','reads']);}
 #[\PHPUnit\Framework\Attributes\DataProvider('missingTables')]
 public function test_incomplete_schema_returns_migration_guidance_without_creating_tables(string $table):void{
  $m=$this->machine();$auth=$this->auth();Schema::drop($table);
  $response=$this->getJson('/api/onenew/admin/machines/'.$m->id.'/runtime-logs',$auth)->assertStatus(503)->assertJsonPath('code','runtime_logs_schema_missing');
  self::assertStringContainsString('php artisan migrate --force',$response->json('message'));self::assertFalse(Schema::hasTable($table));
  self::assertArrayNotHasKey('entries',$response->json());
 }
 public function test_direct_service_reader_uses_the_same_schema_guard():void{
  $m=$this->machine();Schema::drop('onenew_runtime_log_reads');
  try{app(Service::class)->query($m,[],1);self::fail('Unmigrated query should fail before reading entries');}
  catch(\Illuminate\Http\Exceptions\HttpResponseException $e){self::assertSame(503,$e->getResponse()->getStatusCode());self::assertSame('runtime_logs_schema_missing',$e->getResponse()->getData(true)['code']);}
  self::assertFalse(Schema::hasTable('onenew_runtime_log_reads'));
 }
 public function test_existing_schema_does_not_mislabel_query_failures_as_missing_migrations():void{
  $m=$this->machine();Schema::table('onenew_runtime_log_entries',fn($table)=>$table->dropColumn('message'));
  $this->withoutExceptionHandling();$this->expectException(\Illuminate\Database\QueryException::class);
  app(Service::class)->query($m,['search'=>'failure'],1);
 }
 public function test_machine_upload_is_owned_deduplicated_and_rechecked_before_storage():void{
  $m=$this->machine();$b=$this->batch($m);$headers=['Authorization'=>'Bearer '.$m->token];$this->postJson('/api/v3/server/runtime-logs',$b,['Authorization'=>'Bearer wrong'])->assertStatus(403);
  $this->postJson('/api/v3/server/runtime-logs',$b,$headers)->assertOk()->assertJsonPath('last_seq',2);$this->postJson('/api/v3/server/runtime-logs',$b,$headers)->assertOk();self::assertSame(2,DB::table('onenew_runtime_log_entries')->count());self::assertSame(3,(int)DB::table('onenew_runtime_log_streams')->value('dropped'));
  $stored=implode(' ',DB::table('onenew_runtime_log_entries')->pluck('message')->all());foreach(['2001:db8','25051','123','bad-secret'] as $secret)self::assertStringNotContainsString($secret,$stored);self::assertStringContainsString('address already in use',$stored);
 }
 public function test_admin_query_filters_pages_and_recycled_identity_cannot_inherit_logs():void{
  $m=$this->machine();$b=$this->batch($m);$this->postJson('/api/v3/server/runtime-logs',$b,['Authorization'=>'Bearer '.$m->token])->assertOk();$url='/api/onenew/admin/machines/'.$m->id.'/runtime-logs';$this->getJson($url,$this->auth(false))->assertStatus(403);$a=$this->auth();$this->getJson($url.'?limit=1',$a)->assertOk()->assertJsonCount(1,'entries')->assertJsonPath('status','collecting');$this->getJson($url.'?level=error&search=address',$a)->assertOk()->assertJsonCount(1,'entries');
  DB::table('v2_server_machine')->where('id',$m->id)->update(['onenew_identity'=>(string)Str::uuid()]);$this->getJson($url,$a)->assertOk()->assertJsonCount(0,'entries')->assertJsonPath('status','upgrade_required');
 }
 public function test_forbidden_successes_are_dropped_errors_sanitized_and_retention_prunes():void{
  foreach(['INFO [vless:25051] listener started','users added via UserManager added=4 total=9','node configuration applied successfully'] as $message)self::assertNull(LogFilter::message('info',$message));
  $m=$this->machine();$s=app(Service::class);$s->accept($m,$this->batch($m));DB::table('onenew_runtime_log_entries')->update(['received_at'=>time()-604801]);$s->prune();self::assertSame(0,DB::table('onenew_runtime_log_entries')->count());
 }
 public function test_batches_are_bounded_and_sequence_mismatch_rejected():void{
  $m=$this->machine();$b=$this->batch($m);$b['entries'][1]['seq']=5;$this->postJson('/api/v3/server/runtime-logs',$b,['Authorization'=>'Bearer '.$m->token])->assertStatus(422);self::assertSame(0,DB::table('onenew_runtime_log_entries')->count());
  $b=$this->batch($m);$b['entries'][0]['message']=str_repeat('x',270000);$this->postJson('/api/v3/server/runtime-logs',$b,['Authorization'=>'Bearer '.$m->token])->assertStatus(413);
 }
 public function test_offline_capable_node_is_waiting_after_retention_not_upgrade_required():void{
  $m=$this->machine();$s=app(Service::class);$s->accept($m,$this->batch($m));DB::table('onenew_runtime_log_streams')->update(['last_received_at'=>time()-604801]);$s->prune();self::assertSame('waiting',$s->query($m,[],1)['status']);
 }
 public function test_ai_catalog_marks_only_exact_runtime_log_reader_as_read_and_audits_query():void{
  $m=$this->machine();app(Service::class)->accept($m,$this->batch($m));$u=User::create(['email'=>Str::random(12).'@example.test','password'=>'hashed','uuid'=>(string)Str::uuid(),'token'=>Str::random(32),'is_admin'=>true]);
  $tools=app(\App\Onenew\AiOperations\NativeAdminTools::class);$items=$tools->catalog(['search'=>'runtime-logs'])['items'];self::assertCount(1,$items);self::assertSame('read',$items[0]['mode']);
  $tools->read($u->id,['capability'=>$items[0]['capability'],'params'=>['id'=>$m->id,'limit'=>10]]);self::assertSame(1,DB::table('onenew_runtime_log_reads')->where('admin_id',$u->id)->count());
 }
}
