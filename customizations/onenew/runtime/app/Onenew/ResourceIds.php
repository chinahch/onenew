<?php

namespace App\Onenew;

use App\Models\Server;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class ResourceIds
{
    /** Input is ordered; stop at the first gap rather than scanning to MAX(id). */
    public static function firstGap(iterable $ids): int
    {
        $next = 1;
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > $next) break;
            if ($id === $next) ++$next;
        }
        return $next;
    }

    public static function lock(string $table): void
    {
        // A write lock also works for SQLite, including an entirely empty resource
        // table. Held by the caller's transaction through the actual INSERT.
        if (DB::table('v2_onenew_id_mutex')->where('kind', $table)->increment('revision') !== 1) {
            throw new \RuntimeException('Resource ID allocator is not migrated');
        }
    }

    public static function matches(array $snapshot): bool
    {
        $identity = $snapshot['onenew_identity'] ?? '';
        return is_string($identity) && $identity !== '' && Server::whereKey($snapshot['id'] ?? 0)
            ->where('onenew_identity', $identity)->exists();
    }

    public static function clearRuntime(int $id): void
    {
        Cache::forget('node_ws_alive:'.$id);
        foreach (Server::VALID_TYPES as $type) {
            foreach (['ONLINE_USER', 'LAST_CHECK_AT', 'LAST_PUSH_AT', 'LOAD_STATUS', 'LAST_LOAD_AT', 'METRICS'] as $field) {
                Cache::forget('SERVER_'.strtoupper($type).'_'.$field.'_'.$id);
            }
        }
        $cache = Cache::store(app()->environment('testing') ? config('onenew.health_store', 'array') : 'redis');
        $cache->forget('onenew:probe:node:'.$id);
        if (!app()->environment('testing')) app(\App\Services\DeviceStateService::class)->clearAllNodeDevices($id);
    }

    public static function archiveStats(int $id, string $identity): void
    {
        // Native per-node aggregates have no incarnation key. Retain them outside
        // the live table before the numeric ID can be assigned to another node.
        DB::table('v2_stat_server')->where('server_id', $id)->orderBy('id')->chunkById(200, function ($rows) use ($id, $identity) {
            foreach ($rows as $row) DB::table('v2_onenew_retired_stats')->insert([
                'server_id' => $id, 'node_identity' => $identity,
                'payload' => json_encode($row, JSON_THROW_ON_ERROR), 'archived_at' => time(),
            ]);
        });
        DB::table('v2_stat_server')->where('server_id', $id)->delete();
    }
}
