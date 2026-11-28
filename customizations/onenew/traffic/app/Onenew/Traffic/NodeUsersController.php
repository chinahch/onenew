<?php

namespace App\Onenew\Traffic;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\User;
use App\Services\DeviceStateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class NodeUsersController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'server_id' => 'required|integer|min:1',
            'date' => 'nullable|date_format:Y-m-d',
            'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $node = Server::findOrFail((int) $data['server_id']);
        $tz = config('app.timezone', 'UTC');
        $today = Carbon::now($tz)->startOfDay();
        $day = isset($data['date'])
            ? Carbon::createFromFormat('Y-m-d', $data['date'], $tz)->startOfDay()
            : $today->copy();
        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? 50);

        $onlineMap = $this->onlineUsersForNode($node->id);
        $onlineUids = array_keys($onlineMap);

        $todayTrafficByUid = [];
        if (!empty($onlineUids)) {
            $todayQuery = $this->ledgerQuery($node, $today->timestamp)->whereIn('user_id', $onlineUids)
                ->selectRaw('user_id, COALESCE(SUM(u),0) AS u, COALESCE(SUM(d),0) AS d, COALESCE(SUM(raw_u),0) AS raw_u, COALESCE(SUM(raw_d),0) AS raw_d')
                ->groupBy('user_id')->get();
            foreach ($todayQuery as $row) {
                $todayTrafficByUid[(int) $row->user_id] = [
                    'u' => (int) $row->u,
                    'd' => (int) $row->d,
                    'total' => (int) $row->u + (int) $row->d,
                ];
            }
        }

        $onlineUserRecords = !empty($onlineUids)
            ? User::whereIn('id', $onlineUids)->pluck('email', 'id')->all()
            : [];

        $onlineUsers = [];
        foreach ($onlineMap as $uid => $ips) {
            $traffic = $todayTrafficByUid[$uid] ?? ['u' => 0, 'd' => 0, 'total' => 0];
            $onlineUsers[] = [
                'user_id' => $uid,
                'email' => $onlineUserRecords[$uid] ?? ('用户 #' . $uid),
                'ips' => $ips,
                'device_count' => count($ips),
                'today_u' => $traffic['u'],
                'today_d' => $traffic['d'],
                'today_total' => $traffic['total'],
            ];
        }
        usort($onlineUsers, fn ($a, $b) => ($b['today_total'] <=> $a['today_total']) ?: ($a['user_id'] <=> $b['user_id']));

        $dailyBase = $this->ledgerQuery($node, $day->timestamp);
        $summaryRow = (clone $dailyBase)
            ->selectRaw('COUNT(DISTINCT user_id) AS active_users, COALESCE(SUM(raw_u),0) AS raw_u, COALESCE(SUM(raw_d),0) AS raw_d, COALESCE(SUM(u),0) AS u, COALESCE(SUM(d),0) AS d')
            ->first();
        $summary = [
            'active_users' => (int) ($summaryRow->active_users ?? 0),
            'raw_u' => (int) ($summaryRow->raw_u ?? 0),
            'raw_d' => (int) ($summaryRow->raw_d ?? 0),
            'u' => (int) ($summaryRow->u ?? 0),
            'd' => (int) ($summaryRow->d ?? 0),
            'total' => (int) ($summaryRow->u ?? 0) + (int) ($summaryRow->d ?? 0),
        ];

        $grouped = (clone $dailyBase)
            ->selectRaw('user_id, COALESCE(SUM(raw_u),0) AS raw_u, COALESCE(SUM(raw_d),0) AS raw_d, COALESCE(SUM(u),0) AS u, COALESCE(SUM(d),0) AS d, COALESCE(SUM(u + d),0) AS total')
            ->groupBy('user_id');

        $dailyTotal = $summary['active_users'];
        $dailyRows = (clone $grouped)
            ->orderByDesc('total')
            ->orderBy('user_id')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $dailyUids = $dailyRows->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        $dailyEmails = !empty($dailyUids)
            ? User::whereIn('id', $dailyUids)->pluck('email', 'id')->all()
            : [];

        $dailyData = $dailyRows->map(function ($row) use ($dailyEmails, $onlineMap): array {
            $uid = (int) $row->user_id;
            return [
                'user_id' => $uid,
                'email' => $dailyEmails[$uid] ?? ('用户 #' . $uid),
                'raw_u' => (int) $row->raw_u,
                'raw_d' => (int) $row->raw_d,
                'u' => (int) $row->u,
                'd' => (int) $row->d,
                'total' => (int) $row->u + (int) $row->d,
                'is_online' => array_key_exists($uid, $onlineMap),
            ];
        })->values();

        return response()->json([
            'node' => [
                'id' => (int) $node->id,
                'name' => (string) $node->name,
                'type' => (string) $node->type,
                'online' => count($onlineUsers),
            ],
            'timezone' => $tz,
            'date' => $day->format('Y-m-d'),
            'today' => $today->format('Y-m-d'),
            'online_users' => $onlineUsers,
            'daily' => [
                'date' => $day->format('Y-m-d'),
                'summary' => $summary,
                'data' => $dailyData,
                'total' => $dailyTotal,
                'current_page' => $page,
                'per_page' => $perPage,
            ],
        ])->header('Cache-Control', 'no-store');
    }

    private function ledgerQuery(Server $node, int $recordAt)
    {
        $query = DB::table('v2_onenew_user_node_traffic')
            ->where('server_id', $node->id)
            ->where('record_at', $recordAt);
        $identity = (string) ($node->onenew_identity ?? '');
        if ($identity !== '') {
            $query->where('node_identity', $identity);
        }
        return $query;
    }

    /**
     * Merge per-node live device IPs (DeviceStateService) with recently active traffic-push users.
     *
     * @return array<int, list<string>>
     */
    private function onlineUsersForNode(int $nodeId): array
    {
        $map = [];
        try {
            $devices = app(DeviceStateService::class)->getNodeDevices($nodeId);
            if (is_array($devices)) {
                foreach ($devices as $uid => $ips) {
                    $uid = (int) $uid;
                    if ($uid > 0 && is_array($ips) && !empty($ips)) {
                        $map[$uid] = array_values(array_unique(array_map('strval', $ips)));
                    }
                }
            }
        } catch (\Throwable) {
            // Fall back to traffic-push active user cache when Redis device state is unavailable.
        }

        try {
            $now = time();
            $window = max(300, (int) admin_setting('server_push_interval', 60) * 3);
            $active = Cache::get(NodeTrafficLedger::ACTIVE_USERS_PREFIX . $nodeId);
            if (is_array($active)) {
                foreach ($active as $uid => $ts) {
                    $uid = (int) $uid;
                    if ($uid > 0 && is_int($ts) && ($now - $ts) <= $window && !array_key_exists($uid, $map)) {
                        $map[$uid] = [];
                    }
                }
            }
        } catch (\Throwable) {
        }

        return $map;
    }
}
