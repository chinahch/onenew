<?php
require __DIR__.'/../core/MigrationBundle.php';
use App\Onenew\Migration\MigrationBundle as B;
function check($v,$m) { if (!$v) throw new RuntimeException($m); }
function refused($f) { try {$f();} catch (Throwable $e) {return;} throw new RuntimeException('Expected rejection'); }
$base=sys_get_temp_dir().'/onenew-core-'.bin2hex(random_bytes(8)); mkdir($base,0700);
function removeTree($p) { foreach(new FilesystemIterator($p) as $e) {if($e->isDir()&&!$e->isLink()) removeTree($e->getPathname()); else unlink($e->getPathname());} rmdir($p); }
try {
 mkdir($base.'/src/data/database',0700,true); mkdir($base.'/src/plugins/demo',0700,true); mkdir($base.'/src/storage/theme',0700,true);
 file_put_contents($base.'/src/.env',"APP_KEY=base64:original-secret\nDB_DATABASE=.docker/.data/database.sqlite\n");
 file_put_contents($base.'/src/data/database/database.sqlite',random_bytes(2100000)); file_put_contents($base.'/src/plugins/demo/test.php','<?php /* never executed */'); file_put_contents($base.'/src/storage/theme/a.css','body{}');
 $revision=str_repeat('a',40); $password='correct-password-123';
 $result=B::pack($base.'/src',$base.'/good.onm',$password,$revision);
 check($result['files']===4,'File count'); check(B::inspect($base.'/good.onm')['revision']===$revision,'Revision');
 B::unpack($base.'/good.onm',$password,$base.'/out');
 foreach(['.env','data/database/database.sqlite','plugins/demo/test.php','storage/theme/a.css'] as $path) check(hash_file('sha256',$base.'/src/'.$path)===hash_file('sha256',$base.'/out/'.$path),'Roundtrip '.$path);
 refused(fn()=>B::unpack($base.'/good.onm','incorrect-password',$base.'/wrong')); check(!file_exists($base.'/wrong'),'Wrong password publishes nothing');
 refused(fn()=>B::unpack($base.'/good.onm',$password,$base.'/out'));
 $bytes=file_get_contents($base.'/good.onm'); $bad=$bytes; $bad[1000]=chr(ord($bad[1000])^1); file_put_contents($base.'/tamper.onm',$bad); refused(fn()=>B::unpack($base.'/tamper.onm',$password,$base.'/tamper'));
 file_put_contents($base.'/short.onm',substr($bytes,0,-10)); refused(fn()=>B::unpack($base.'/short.onm',$password,$base.'/short'));
 $bad=$bytes; $bad[40]=chr(ord($bad[40])^1); file_put_contents($base.'/header.onm',$bad); refused(fn()=>B::unpack($base.'/header.onm',$password,$base.'/header'));
 file_put_contents($base.'/extra.onm',$bytes.'x'); refused(fn()=>B::unpack($base.'/extra.onm',$password,$base.'/extra'));
 $hlen=unpack('N',substr($bytes,8,4))[1]; $offset=12+$hlen; $first=4+unpack('N',substr($bytes,$offset,4))[1]; $second=4+unpack('N',substr($bytes,$offset+$first,4))[1];
 $reordered=substr($bytes,0,$offset).substr($bytes,$offset+$first,$second).substr($bytes,$offset,$first).substr($bytes,$offset+$first+$second);
 file_put_contents($base.'/reorder.onm',$reordered); refused(fn()=>B::unpack($base.'/reorder.onm',$password,$base.'/reorder'));
 $h=B::inspect($base.'/good.onm'); $raw=substr($bytes,12,$hlen); $key=hash_pbkdf2('sha256',$password,hex2bin($h['salt']),210000,32,true);
 $rec=new ReflectionMethod(B::class,'record'); $rec->setAccessible(true); $f=fopen($base.'/path.onm','wb'); fwrite($f,substr($bytes,0,$offset)); $idx=0;
 $args=[$f,'F'.json_encode(['path'=>'plugins/../../escape','size'=>0]),$key,$h,$raw,&$idx]; $rec->invokeArgs(null,$args); fclose($f);
 refused(fn()=>B::unpack($base.'/path.onm',$password,$base.'/unsafe')); check(!file_exists($base.'/escape'),'Authenticated malicious path blocked');
 $method=new ReflectionMethod(B::class,'allowed'); $method->setAccessible(true);
 foreach(['../.env','plugins/../evil','plugins/a/../../evil','plugins/a\\evil','plugins/C:evil','/plugins/a','plugins//a','plugins/a'.chr(0),'other/file'] as $path) check(!$method->invoke(null,$path),'Reject traversal '.$path);
 refused(fn()=>B::pack($base.'/src',$base.'/good.onm',$password,$revision)); refused(fn()=>B::pack($base.'/src',$base.'/weak.onm','short',$revision));
 foreach([chr(0),chr(10),chr(13),chr(31),chr(127)] as $control) refused(fn()=>B::pack($base.'/src',$base.'/control.onm','correct-password'.$control,$revision));
 check(count(glob($base.'/.onenew-restore-*'))===0,'Private stages cleaned'); echo "Migration core tests passed\n";
} finally {removeTree($base);}
