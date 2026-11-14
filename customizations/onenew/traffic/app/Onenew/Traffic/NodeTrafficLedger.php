<?php

namespace App\Onenew\Traffic;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class NodeTrafficLedger
{
    public function applyBatch(string $batchId, array $server, array $data, string $protocol, int $recordAt, callable $nativeArchive): void
    {
        $rate = (float) ($server['rate'] ?? -1);
        if (!Str::isUuid($batchId) || (int) ($server['id'] ?? 0) <= 0 || !is_finite($rate) || $rate < 0 || $rate > 1000000 ||
            count($data) > 1000 || strlen($protocol) > 32 || $protocol === '') {
            throw new \InvalidArgumentException('Invalid native traffic ledger batch');
        }
        $increments = [];
        foreach ($data as $uid => $traffic) {
            if (filter_var($uid, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false || !is_array($traffic) || count($traffic) !== 2) {
                throw new \InvalidArgumentException('Invalid native traffic ledger row');
            }
            foreach ([0, 1] as $index) {
                if (!isset($traffic[$index]) || filter_var($traffic[$index], FILTER_VALIDATE_INT,
                    ['options' => ['min_range' => 0]]) === false || $traffic[$index] * $rate > PHP_INT_MAX) {
                    throw new \InvalidArgumentException('Invalid native traffic ledger bytes');
                }
            }
            // These exact per-report truncations match StatUserJob; never multiply
            // an already billed archive or recompute old bytes at today's rate.
            $increments[(int) $uid] = ['raw_u' => (int) $traffic[0], 'raw_d' => (int) $traffic[1],
                'u' => intval($traffic[0] * $rate), 'd' => intval($traffic[1] * $rate)];
        }
        DB::transaction(function () use ($batchId, $server, $protocol, $recordAt, $rate, $increments, $nativeArchive): void {
            if (!DB::table('v2_onenew_traffic_receipts')->insertOrIgnore(['batch_id' => $batchId, 'created_at' => time()])) {
                return;
            }
            $nativeArchive();
            foreach ($increments as $uid => $bytes) {
                $key = ['user_id' => $uid, 'server_id' => (int) $server['id'], 'server_type' => $protocol,
                    'record_at' => $recordAt, 'server_rate' => number_format($rate, 8, '.', '')];
                $metadata = ['node_name' => mb_substr((string) ($server['name'] ?? ('#' . $server['id'])), 0, 255), 'updated_at' => time()];
                DB::table('v2_onenew_user_node_traffic')->insertOrIgnore($key + $metadata + [
                    'raw_u' => 0, 'raw_d' => 0, 'u' => 0, 'd' => 0, 'created_at' => time(),
                ]);
                DB::table('v2_onenew_user_node_traffic')->where($key)->incrementEach($bytes, $metadata);
            }
        }, 3);
    }
}
