<?php
require __DIR__.'/../app/Onenew/RuntimeLogs/LogFilter.php';
use App\Onenew\RuntimeLogs\LogFilter;
function check($ok){if(!$ok)throw new RuntimeException('Runtime log privacy boundary failed');}
foreach(['users added via UserManager total=9','INFO [vless:25051] listener started','node configuration applied successfully'] as $line)check(LogFilter::message('info',$line)===null);
$line=LogFilter::message('error','listen tcp 2001:db8::1:25051 user_id=123 password=bad-secret: bind: address already in use');
foreach(['2001:db8','25051','123','bad-secret'] as $secret)check(!str_contains($line,$secret));check(str_contains($line,'address already in use'));
check(LogFilter::message('warn','certificate renewal failed: connection refused')==='certificate renewal failed: connection refused');
check(strlen(LogFilter::message('error',str_repeat('界',1000)))<=2048);
echo "Runtime log suppression, error reason preservation, identity/port/secret redaction and UTF-8 bound passed\n";
