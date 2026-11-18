<?php
// Preserve the entrypoint's fallback drivers until migrations and updates finish.
declare(strict_types=1);
$root=$argv[1]??'/www';
// Native update ignores migrate's return code. Run it explicitly first so a
// failed migration cannot be hidden by later plugin/theme update success.
foreach ([[PHP_BINARY,__DIR__.'/seed-plugins.php',$root],[PHP_BINARY,$root.'/artisan','migrate','--force','--no-interaction'],[PHP_BINARY,$root.'/artisan','onenew:update','--no-interaction']] as $command) {
    // Array arguments bypass the shell; null environment inherits the caller unchanged.
    $process=proc_open($command,[0=>STDIN,1=>STDOUT,2=>STDERR],$pipes,null,null,['bypass_shell'=>true]);
    if(!is_resource($process)){fwrite(STDERR,"Cannot start startup update subprocess.\n");exit(1);}
    $status=proc_close($process);
    if($status!==0)exit($status>0&&$status<=255?$status:1);
}
