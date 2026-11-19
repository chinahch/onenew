<?php

namespace App\Onenew\Traffic;

use App\Http\Controllers\Controller;
use App\Http\Controllers\V2\Admin\StatController;
use App\Models\Server;
use App\Models\StatServer;
use App\Models\StatUser;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

final class RankingController extends Controller
{
    public function getTrafficRank(Request $request): array
    {
        $data = $request->validate([
            'type' => 'required|in:node,user',
            'period' => 'sometimes|required|in:today,last7days,last30days,custom',
            'start_date' => 'required_if:period,custom|date_format:Y-m-d',
            'end_date' => 'required_if:period,custom|date_format:Y-m-d|after_or_equal:start_date',
        ]);
        // Retain compatibility with clients that explicitly submit timestamp ranges.
        if (!isset($data['period'])) {
            return app(StatController::class)->getTrafficRank($request);
        }

        $timezone = config('app.timezone', 'UTC');
        $end = Carbon::tomorrow($timezone);
        if ($data['period'] === 'custom') {
            $start = Carbon::parse($data['start_date'], $timezone)->startOfDay();
            $end = Carbon::parse($data['end_date'], $timezone)->startOfDay()->addDay();
        } else {
            $days = ['today' => 1, 'last7days' => 7, 'last30days' => 30][$data['period']];
            $start = $end->copy()->subDays($days);
        }
        $previousStart = $start->copy()->subDays((int) $start->diffInDays($end));
        $node = $data['type'] === 'node';
        $idColumn = $node ? 'server_id' : 'user_id';
        $query = fn () => ($node ? StatServer::query() : StatUser::query())
            ->where('record_type', 'd');
        $current = $query()->selectRaw($idColumn.' as id, SUM(u + d) as value')
            ->where('record_at', '>=', $start->timestamp)->where('record_at', '<', $end->timestamp)
            ->groupBy($idColumn)->orderByDesc('value')->orderBy($idColumn)->limit(10)->get();
        $ids = $current->pluck('id');
        $previous = $query()->selectRaw($idColumn.' as id, SUM(u + d) as value')
            ->where('record_at', '>=', $previousStart->timestamp)->where('record_at', '<', $start->timestamp)
            ->whereIn($idColumn, $ids)->groupBy($idColumn)->get()->keyBy('id');
        $names = $node ? Server::whereIn('id', $ids)->pluck('name', 'id')
            : User::whereIn('id', $ids)->pluck('email', 'id');

        $rows = $current->map(function ($row) use ($previous, $names, $node, $end): array {
            $value = (int) $row->value;
            $before = (int) ($previous->get($row->id)?->value ?? 0);
            return [
                'id' => (string) $row->id,
                'name' => $names[$row->id] ?? (($node ? 'Node ' : 'User ').$row->id),
                'value' => $value,
                'previousValue' => $before,
                'change' => $before > 0 ? round(($value - $before) / $before * 100, 1) : 0,
                'timestamp' => $end->toIso8601String(),
            ];
        })->all();

        return ['timestamp' => Carbon::now($timezone)->toIso8601String(), 'data' => $rows];
    }
}
