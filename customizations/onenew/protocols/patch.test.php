<?php
require __DIR__.'/install.php';
$root=dirname(__DIR__,3); $work=__DIR__.'/.test-work/preflight';
$files=['app/Models/Server.php','app/Services/ServerService.php','app/Protocols/General.php','app/Protocols/Shadowrocket.php','app/Protocols/ClashMeta.php'];
foreach ($files as $file) { @mkdir(dirname($work.'/'.$file),0777,true); copy($root.'/'.$file,$work.'/'.$file); }
file_put_contents($work.'/app/Protocols/General.php', '<?php // intentionally changed upstream');
$before=[]; foreach ($files as $file) $before[$file]=file_get_contents($work.'/'.$file);
try { applyOnenewProtocolPatch($work); throw new RuntimeException('Must reject drift'); }
catch (RuntimeException $e) { if (!str_contains($e->getMessage(),'anchor changed')) throw $e; }
foreach ($before as $file=>$source) if ($source!==file_get_contents($work.'/'.$file)) throw new RuntimeException('Partial write on failed preflight');
echo "PASS protocol patch drift preflight leaves all targets untouched\n";
