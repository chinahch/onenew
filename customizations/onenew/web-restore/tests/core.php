<?php
require __DIR__.'/../core/Recovery.php';require __DIR__.'/../../migration/core/MigrationBundle.php';
use App\Onenew\WebRestore\Recovery;
use App\Onenew\Migration\MigrationBundle;
function expect($ok,$why){if(!$ok)throw new RuntimeException($why);}
$root=sys_get_temp_dir().'/onenew-recovery-'.bin2hex(random_bytes(6));mkdir($root,0700);$source=$root.'/target';$input=$root.'/input';$id='00000000-0000-4000-8000-000000000001';$pass='private-test-backup-password';$old='base64:'.base64_encode(str_repeat('a',32));$new='base64:'.base64_encode(str_repeat('b',32));
try{
 expect(!Recovery::validId('../bad'),'Unsafe ID accepted');
 foreach(["APP_KEY=$old\r\n","  APP_KEY = '$old' # comment\r\n","export APP_KEY=\"$old\" # inline\n","\xef\xbb\xbfAPP_KEY=$old\r\n"] as $environment)expect(Recovery::key($environment)===$old,'Valid dotenv key rejected');
 expect(Recovery::key(Recovery::environmentWithKey("APP_KEY='$old' # inline\r\nAPP_URL=https://target.example\r\nAPP_KEY=$old\r\n",$new))===$new,'Formatted environment key update failed');

 foreach([$source,$input] as $path){mkdir($path.'/.docker/.data',0700,true);mkdir($path.'/plugins/Example',0700,true);mkdir($path.'/storage/theme',0700,true);}
 file_put_contents($source.'/.env',"APP_KEY=$old\nAPP_URL=https://target.example\nDB_DATABASE=.docker/.data/panel.sqlite\n");
 file_put_contents($source.'/plugins/Example/test.php','old-plugin');file_put_contents($source.'/storage/theme/theme.json','old-theme');
 $db=new PDO('sqlite:'.$source.'/.docker/.data/panel.sqlite');$db->exec('CREATE TABLE v2_user (id INTEGER); INSERT INTO v2_user VALUES (1)');$db=null;
 mkdir($input.'/data/database',0700,true);file_put_contents($input.'/.env',"APP_KEY=$new\nAPP_URL=https://old.example\n");
 file_put_contents($input.'/plugins/Example/test.php','new-plugin');file_put_contents($input.'/storage/theme/theme.json','new-theme');
 $db=new PDO('sqlite:'.$input.'/data/database/panel.sqlite');$db->exec("CREATE TABLE v2_user(id INTEGER); INSERT INTO v2_user VALUES (2),(3); CREATE TABLE v2_server(id INTEGER); CREATE TABLE v2_settings(name TEXT,value TEXT); CREATE TABLE migrations(migration TEXT); INSERT INTO migrations VALUES ('known_migration'); CREATE TABLE personal_access_tokens(id INTEGER); INSERT INTO personal_access_tokens VALUES(1); CREATE TABLE onenew_cloud_backup_config(enabled INTEGER,next_run_at INTEGER); INSERT INTO onenew_cloud_backup_config VALUES(1,123);");$db=null;
 $dir=Recovery::dir($source,$id);mkdir($dir,0700,true);MigrationBundle::pack($input,$dir.'/package.onm',$pass,str_repeat('a',40));
 try{MigrationBundle::unpack($dir.'/package.onm','incorrect-password',$dir.'/bad');throw new RuntimeException('Wrong password accepted');}catch(RuntimeException $e){expect(!is_dir($dir.'/bad'),'Wrong password published data');}
 expect(Recovery::key(file_get_contents($source.'/.env'))===$old,'Wrong password changed target');
 MigrationBundle::unpack($dir.'/package.onm',$pass,$dir.'/stage');$checked=Recovery::validateStage($dir.'/stage',['known_migration']);expect($checked['counts']['users']===2,'User count missing');
 try{Recovery::validateStage($dir.'/stage',[]);throw new RuntimeException('Unknown schema accepted');}catch(RuntimeException $e){expect(str_contains($e->getMessage(),'newer'),'Unexpected schema error');}
 Recovery::snapshot($source,'panel.sqlite',$dir,$pass,str_repeat('a',40));Recovery::apply($source,'panel.sqlite',$dir,$dir.'/stage',$checked['database']);
 expect(Recovery::key(file_get_contents($source.'/.env'))===$new,'Imported key missing');expect(str_contains(file_get_contents($source.'/.env'),'target.example'),'Target infrastructure overwritten');expect(file_get_contents($source.'/plugins/Example/test.php')==='new-plugin','Plugin not restored');
 $db=new PDO('sqlite:'.$source.'/.docker/.data/panel.sqlite');expect($db->query('SELECT COUNT(*) FROM v2_user')->fetchColumn()==2,'Database not restored');expect($db->query('SELECT COUNT(*) FROM personal_access_tokens')->fetchColumn()==0,'Old login tokens preserved');expect($db->query('SELECT enabled FROM onenew_cloud_backup_config')->fetchColumn()==0,'Cloud backup not paused');$db=null;
 Recovery::rollback($source,$dir);expect(Recovery::key(file_get_contents($source.'/.env'))===$old,'Rollback key failed');expect(file_get_contents($source.'/plugins/Example/test.php')==='old-plugin','Rollback plugin failed');
 $db=new PDO('sqlite:'.$source.'/.docker/.data/panel.sqlite');expect($db->query('SELECT id FROM v2_user')->fetchColumn()==1,'Rollback database failed');$db=null;
 MigrationBundle::unpack($dir.'/rollback.onm',$pass,$root.'/rollback-check');expect(is_file($root.'/rollback-check/data/database/panel.sqlite'),'Rollback package lost original database filename');
 echo "Web restore authentication, schema checks, key/DB/resources and rollback passed\n";
}finally{Recovery::clear($root);}
