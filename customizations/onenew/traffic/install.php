<?php

/** Called by the root overlay installer on the pinned fresh upstream source. */
function applyOnenewTrafficPatch(string $root): void
{
    $path = $root . '/app/Services/UserService.php';
    $source = str_replace("\r\n", "\n", file_get_contents($path));
    $old = 'use App\\Jobs\\StatUserJob;';
    $new = 'use App\\Onenew\\Traffic\\LedgerStatUserJob as StatUserJob;';
    if (substr_count($source, $old) !== 1) {
        throw new RuntimeException('Pinned native daily traffic job import changed');
    }
    if (file_put_contents($path, str_replace($old, $new, $source)) === false) {
        throw new RuntimeException('Cannot apply daily traffic ledger integration');
    }
}
