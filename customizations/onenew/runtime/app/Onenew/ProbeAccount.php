<?php

namespace App\Onenew;

use App\Models\User;

final class ProbeAccount
{
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
}
