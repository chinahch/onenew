<?php
$path = __DIR__.'/../../runtime/app/Onenew/ResourceIds.php';
if (!is_file($path)) { fwrite(STDERR, "FAIL: minimum-gap allocator not implemented\n"); exit(1); }
require $path;
foreach ([[[],1], [[1,2,3],4], [[1,3],2], [[2,4],1], [[1,2,4,6],3]] as [$ids,$expected]) {
    if (\App\Onenew\ResourceIds::firstGap($ids) !== $expected) { throw new RuntimeException('Wrong minimum free ID'); }
}
echo "PASS: five minimum-gap allocations\n";
