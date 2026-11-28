<?php

namespace App\Onenew;

use App\Models\User;

final class ProbeAccount
{
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
        $probeIds = self::ids();
        if (empty($probeIds)) {
            return count($trafficMap);
        }
        return count(array_diff_key($trafficMap, array_flip($probeIds)));
    }
}
