<?php

namespace App\Onenew\Traffic;

use Closure;
use Illuminate\Support\Facades\DB;

final class TrafficBatch
{
    public static function apply(?string $id, Closure $write): void
    {
        DB::transaction(function () use ($id, $write): void {
            // Jobs serialized before rollout have no batch ID. Keep them readable.
            if ($id !== null && !DB::table('v2_onenew_traffic_receipts')->insertOrIgnore([
                'batch_id' => $id,
                'created_at' => time(),
            ])) {
                return;
            }

            $write();
        }, 3);
    }
}
