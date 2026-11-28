<?php

namespace App\Onenew;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

final class ProbeAccount
{
    public const ACTIVE_USERS_PREFIX = 'onenew:node_active_users:';

    /** Unified 3-minute sliding window for both node online count and node-users modal. */
    public const ACTIVE_WINDOW_SECONDS = 180;

    /** Minimum single-report raw bytes (64 KiB) to treat a traffic push as real active usage rather than a client url-test/ping. */
    public const MIN_ACTIVE_REPORT_BYTES = 65536;

    /**
     * Return IDs of dedicated internal health-probe accounts.
     *
     * @return list<int>
     */
    public static function ids(): array
    {
        $ids = [];
        $configured = (int) config('onenew.probe_user_id', env('ONENEW_PROBE_USER_ID', 0));
        if ($configured > 0) {
            $ids[] = $configured;
        }
        try {
            $matched = User::query()
                ->where('email', 'like', 'probe@%')
                ->orWhere('email', 'like', '节点探针@%')
                ->pluck('id')
                ->all();
            foreach ($matched as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
        } catch (\Throwable) {
        }
        return array_values(array_unique($ids));
    }

    public static function countRealUsers(array $trafficMap): int
    {
        $probeMap = array_flip(self::ids());
        $count = 0;
        foreach ($trafficMap as $uid => $pair) {
            if (isset($probeMap[(int) $uid])) {
                continue;
            }
            $bytes = is_array($pair) ? ((int) ($pair[0] ?? 0) + (int) ($pair[1] ?? 0)) : 0;
            if ($bytes >= self::MIN_ACTIVE_REPORT_BYTES) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Record active real users from a node traffic push into the 3-minute sliding window
     * and return the resulting active real-user count.
     */
    public static function recordTrafficPush(int $nodeId, array $trafficMap): int
    {
        if ($nodeId <= 0) {
            return 0;
        }
        $probeMap = array_flip(self::ids());
        $now = time();
        $window = self::ACTIVE_WINDOW_SECONDS;
        $key = self::ACTIVE_USERS_PREFIX . $nodeId;

        try {
            $existing = Cache::get($key);
            $map = [];
            if (is_array($existing)) {
                foreach ($existing as $uid => $ts) {
                    $uid = (int) $uid;
                    if ($uid > 0 && is_int($ts) && ($now - $ts) <= $window && !isset($probeMap[$uid])) {
                        $map[$uid] = $ts;
                    }
                }
            }
            foreach ($trafficMap as $uid => $pair) {
                $uid = (int) $uid;
                if ($uid <= 0 || isset($probeMap[$uid])) {
                    continue;
                }
                $bytes = is_array($pair) ? ((int) ($pair[0] ?? 0) + (int) ($pair[1] ?? 0)) : 0;
                if ($bytes >= self::MIN_ACTIVE_REPORT_BYTES) {
                    $map[$uid] = $now;
                }
            }
            if (empty($map)) {
                Cache::forget($key);
                return 0;
            }
            Cache::put($key, $map, $window);
            return count($map);
        } catch (\Throwable) {
            return self::countRealUsers($trafficMap);
        }
    }

    /**
     * Return user IDs currently active on a node within the unified 3-minute window.
     *
     * @return list<int>
     */
    public static function activeUserIds(int $nodeId): array
    {
        if ($nodeId <= 0) {
            return [];
        }
        try {
            $existing = Cache::get(self::ACTIVE_USERS_PREFIX . $nodeId);
            if (!is_array($existing) || empty($existing)) {
                return [];
            }
            $probeMap = array_flip(self::ids());
            $now = time();
            $window = self::ACTIVE_WINDOW_SECONDS;
            $uids = [];
            foreach ($existing as $uid => $ts) {
                $uid = (int) $uid;
                if ($uid > 0 && is_int($ts) && ($now - $ts) <= $window && !isset($probeMap[$uid])) {
                    $uids[] = $uid;
                }
            }
            return $uids;
        } catch (\Throwable) {
            return [];
        }
    }

    public static function onlineCount(int $nodeId): int
    {
        return count(self::activeUserIds($nodeId));
    }
}
