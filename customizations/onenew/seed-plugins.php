<?php
// Runs before Artisan, including the first install. No database is accessed.
declare(strict_types=1);
$root=$argv[1]??'/www';
$source=__DIR__.'/plugins/SubscriptionGuard';
$target=$root.'/plugins/SubscriptionGuard';
if (file_exists($target)) { exit(0); }
if (!is_dir($source)) { throw new RuntimeException('Bundled plugin source missing'); }
if (!is_dir(dirname($target)) && !mkdir(dirname($target),0755,true)) { throw new RuntimeException('Cannot create plugin directory'); }
$stage=$target.'.seed.'.bin2hex(random_bytes(8));
mkdir($stage,0755);
$files=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source,FilesystemIterator::SKIP_DOTS));
foreach($files as $file) {
    if(!$file->isFile()||$file->isLink()) { throw new RuntimeException('Unexpected plugin entry'); }
    $dest=$stage.'/'.substr($file->getPathname(),strlen($source)+1);
    if(!is_dir(dirname($dest))) { mkdir(dirname($dest),0755,true); }
    if(!copy($file->getPathname(),$dest)) { throw new RuntimeException('Cannot seed plugin'); }
}
if(!rename($stage,$target)) { throw new RuntimeException('Cannot finish plugin seed'); }
