<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Onenew\CloudBackup\DriveClient;
use App\Onenew\CloudBackup\BackupFailure;
use Illuminate\Support\Facades\Http;
final class DriveClientTest extends TestCase
{
 private function client():DriveClient{return new DriveClient(['client_id'=>'fixture-client','client_secret'=>'fixture-secret','refresh_token'=>'fixture-refresh','folder_id'=>'fixture-folder','folder_key'=>str_repeat('a',32)]);}
 public function test_oauth_refresh_and_authorized_folder():void{
  Http::preventStrayRequests();Http::fake(['oauth2.googleapis.com/token'=>Http::response(['access_token'=>'fixture-access']), 'www.googleapis.com/drive/v3/files/fixture-folder*'=>Http::response(['id'=>'fixture-folder','mimeType'=>'application/vnd.google-apps.folder','capabilities'=>['canAddChildren'=>true]])]);
  self::assertSame('fixture-folder',$this->client()->ensureFolder());Http::assertSent(fn($r)=>$r->url()==='https://oauth2.googleapis.com/token'&&$r['refresh_token']==='fixture-refresh');
 }
 public function test_failed_authorization_is_sanitized_and_session_url_cannot_exfiltrate():void{
  Http::fake(['oauth2.googleapis.com/token'=>Http::response(['error'=>'invalid_grant','error_description'=>'private-secret'],400)]);
  try{$this->client()->ensureFolder();self::fail();}catch(BackupFailure $e){self::assertSame('authorization_expired',$e->reason);self::assertStringNotContainsString('private-secret',$e->getMessage());}
  foreach(['http://www.googleapis.com/upload/drive/v3/files?upload_id=x','https://evil.example/upload','https://u:p@www.googleapis.com/upload/drive/v3/files','https://www.googleapis.com.evil.test/upload/drive/v3/files'] as $url)self::assertFalse(DriveClient::validSession($url));
 }
 public function test_resumable_upload_uses_server_offset_and_checks_remote_md5():void{
  $path=tempnam(sys_get_temp_dir(),'drive-test');file_put_contents($path,'abcdef');$complete=false;$chunks=[];
  $run=['id'=>'fixture-run','filename'=>'fixture.onm','bytes'=>6,'md5'=>md5('abcdef'),'sha256'=>hash('sha256','abcdef'),'revision'=>str_repeat('a',40),'file_id'=>'fixture-file','session_uri'=>'https://www.googleapis.com/upload/drive/v3/files?upload_id=fixture'];
  Http::fake(function($r)use(&$complete,&$chunks,$run){
   if(str_contains($r->url(),'oauth2.googleapis.com'))return Http::response(['access_token'=>'fixture-access']);
   if(str_contains($r->url(),'upload_id=fixture')){
    if($r->header('Content-Range')[0]==='bytes */6')return Http::response('',308,['Range'=>'bytes=0-2']);
    $chunks[]=$r->body();$complete=true;return Http::response(['id'=>'fixture-file'],200);
   }
   if(str_contains($r->url(),'/files/fixture-file'))return $complete?Http::response(['id'=>'fixture-file','size'=>'6','md5Checksum'=>$run['md5'],'appProperties'=>['onenew_backup_run'=>'fixture-run']]):Http::response([],404);
   throw new \RuntimeException('Unexpected test HTTP route');
  });
  try{$saved=[];$result=$this->client()->upload($path,$run,function($p)use(&$saved){$saved[]=$p;});self::assertSame(['def'],$chunks);self::assertSame('fixture-file',$result['id']);self::assertNotEmpty($saved);}finally{unlink($path);}
 }
 public function test_remote_checksum_failure_cannot_become_success():void{
  Http::fake(['oauth2.googleapis.com/token'=>Http::response(['access_token'=>'fixture-access']), 'www.googleapis.com/drive/v3/files/fixture-file*'=>Http::response(['id'=>'fixture-file','size'=>'6','md5Checksum'=>str_repeat('0',32),'appProperties'=>['onenew_backup_run'=>'fixture-run']])]);
  $this->expectException(BackupFailure::class);$this->client()->verify(['id'=>'fixture-run','file_id'=>'fixture-file','bytes'=>6,'md5'=>md5('abcdef')]);
 }
}
