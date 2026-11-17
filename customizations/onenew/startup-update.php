<?php
// Keep the entrypoint's selected drivers in BOTH subprocesses before embedded Redis starts.
declare(strict_types=1);
$root=$argv[1]??'/www';
foreach ([[PHP_BINARY,__DIR__.'/seed-plugins.php',$root],[PHP_BINARY,$root.'/artisan','onenew:update','--no-interaction']] as $command) {
    // Array arguments bypass the shell; null environment inherits the caller unchanged.
    $process=proc_open($command,[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,null,null,['bypass_shell'=>true]);
    if(!is_resource($process)){fwrite(STDERR,"Cannot start startup update subprocess.\n");exit(1);}
    $status=proc_close($process);
    if($status!==0)exit($status>0&&$status<=255?$status:1);
}
