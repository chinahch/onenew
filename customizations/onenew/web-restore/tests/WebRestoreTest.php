<?php
namespace Tests\Feature\Onenew;
use Tests\TestCase;
use App\Models\User;
use App\Onenew\WebRestore\Service;
use App\Onenew\WebRestore\Recovery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
final class WebRestoreTest extends TestCase
{
 use RefreshDatabase;
 private string $source;
 protected function setUp():void{parent::setUp();$this->source=sys_get_temp_dir().'/web-restore-api-'.bin2hex(random_bytes(6));mkdir($this->source.'/.docker/.data',0700,true);file_put_contents($this->source.'/.env','APP_KEY='.config('app.key')."\n");$db=new \PDO('sqlite:'.$this->source.'/.docker/.data/panel.sqlite');$db->exec('CREATE TABLE fixture(id INTEGER)');$db=null;config(['onenew.migration_source'=>$this->source,'onenew.migration_database'=>$this->source.'/.docker/.data/panel.sqlite','onenew.migration_revision'=>str_repeat('a',40)]);}
 protected function tearDown():void{if(isset($this->source))File::deleteDirectory($this->source);parent::tearDown();}
 private function admin():User{return User::create(['email'=>bin2hex(random_bytes(6)).'@example.test','password'=>'unused','uuid'=>(string)\Illuminate\Support\Str::uuid(),'token'=>bin2hex(random_bytes(16)),'is_admin'=>true]);}
 public function test_admin_owner_chunk_idempotency_masked_password_and_explicit_confirmation():void{
  $root='/api/onenew/admin/restore';$this->getJson($root)->assertForbidden();$user=$this->admin();Sanctum::actingAs($user);
  $this->getJson($root)->assertOk()->assertJsonPath('supported',true);
  $this->postJson($root,['filename'=>'bad.zip','bytes'=>32])->assertUnprocessable();
  $id=$this->postJson($root,['filename'=>'fixture.onm','bytes'=>32])->assertOk()->json('id');$s=app(Service::class);
  $s->chunk($id,0,str_repeat('a',16));$s->chunk($id,0,str_repeat('a',16));self::assertSame(16,$s->owned($id)['received']);$s->chunk($id,16,str_repeat('b',16));
  $this->postJson($root.'/'.$id.'/confirm',['confirmed'=>true])->assertStatus(409);
  $password='private-restore-password';$r=$this->postJson($root.'/'.$id.'/verify',['password'=>$password])->assertOk()->assertJsonPath('state','verifying');self::assertStringNotContainsString($password,$r->getContent());
  $dir=$s->directory($id);self::assertSame($password,Crypt::decryptString(file_get_contents($dir.'/password.enc')));$request=Recovery::json($dir.'/request.json');self::assertSame(hash_hmac('sha256',$request['body'],config('app.key')),$request['mac']);self::assertStringNotContainsString($password,serialize($request));
  Sanctum::actingAs($this->admin());$this->getJson($root.'/'.$id)->assertNotFound();Sanctum::actingAs($user);
  Recovery::state($dir,['state'=>'ready','summary'=>['counts'=>['users'=>1],'admin_path'=>'/restored-admin'],'package_sha256'=>hash_file('sha256',$dir.'/package.onm')]);
  $this->postJson($root.'/'.$id.'/confirm',[])->assertUnprocessable();$confirmed=$this->postJson($root.'/'.$id.'/confirm',['confirmed'=>true])->assertOk()->json();
  $this->postJson($root.'/'.$id.'/confirm',['confirmed'=>true])->assertStatus(409);
  $this->postJson('/api/onenew/restore/progress',['id'=>$id,'token'=>str_repeat('0',64)])->assertNotFound();
  $r=$this->postJson('/api/onenew/restore/progress',['id'=>$id,'token'=>$confirmed['progress_token']])->assertOk()->assertJsonPath('state','queued');self::assertStringNotContainsString('counts',$r->getContent());self::assertStringNotContainsString($password,$r->getContent());
 }
}
