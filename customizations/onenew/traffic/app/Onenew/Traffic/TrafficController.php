<?php

namespace App\Onenew\Traffic;

use App\Http\Controllers\Controller;
use App\Models\StatUser;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class TrafficController extends Controller
{
    public function records(Request $request): JsonResponse
    {
        [$user, $page, $perPage] = $this->parameters($request);
        $records = StatUser::where('user_id', $user->id)->orderByDesc('record_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
        $data = $records->getCollection()->map(fn ($row) => [
            'id' => (int) $row->id, 'user_id' => (int) $row->user_id, 'server_rate' => (float) $row->server_rate,
            'u' => (int) $row->u, 'd' => (int) $row->d, 'record_type' => $row->record_type, 'record_at' => (int) $row->record_at,
            'date' => $this->at((int) $row->record_at)->format('Y-m-d'),
        ])->values();
        return response()->json(['data' => $data, 'total' => $records->total(), 'current_page' => $page,
            'per_page' => $perPage, 'timezone' => config('app.timezone')])->header('Cache-Control', 'no-store');
    }

    public function nodes(Request $request): JsonResponse
    {
        [$user, $page, $perPage] = $this->parameters($request);
        [$recordAt, $day] = $this->day($request);
        $base = DB::table('v2_onenew_user_node_traffic')->where('user_id', $user->id)->where('record_at', $recordAt);
        $totalRows = (clone $base)->count();
        $sums = (clone $base)->selectRaw('COALESCE(SUM(raw_u),0) AS raw_u, COALESCE(SUM(raw_d),0) AS raw_d, COALESCE(SUM(u),0) AS u, COALESCE(SUM(d),0) AS d')->first();
        $native = StatUser::where('user_id', $user->id)->where('record_type', 'd')->where('record_at', $recordAt)
            ->selectRaw('COALESCE(SUM(u),0) AS u, COALESCE(SUM(d),0) AS d')->first();
        $totals = ['raw_u' => (int) $sums->raw_u, 'raw_d' => (int) $sums->raw_d, 'u' => (int) $sums->u, 'd' => (int) $sums->d];
        $totals['total'] = $totals['u'] + $totals['d'];
        $nativeTotals = ['u' => (int) $native->u, 'd' => (int) $native->d, 'total' => (int) $native->u + (int) $native->d];
        $unattributed = ['u' => max(0, $nativeTotals['u'] - $totals['u']), 'd' => max(0, $nativeTotals['d'] - $totals['d'])];
        $unattributed['total'] = $unattributed['u'] + $unattributed['d'];
        $started = DB::table('v2_onenew_traffic_meta')->where('key', 'recording_started_at')->value('value');
        if ($totalRows === 0) {
            $state = $nativeTotals['total'] > 0 || !$started || $recordAt < $this->at((int) $started)->startOfDay()->timestamp ? 'unavailable' : 'no_traffic';
            $reason = $state === 'unavailable' ? 'legacy_node_breakdown_unavailable' : 'no_archived_traffic';
        } elseif ($totals['u'] === $nativeTotals['u'] && $totals['d'] === $nativeTotals['d']) {
            $state = 'complete';
            $reason = '';
        } else {
            $state = 'partial';
            $reason = 'archive_totals_do_not_match_recorded_node_breakdown';
        }
        $rows = (clone $base)->leftJoin('v2_server as nodes', function ($join) {
                $join->on('nodes.id', '=', 'v2_onenew_user_node_traffic.server_id')
                    ->on('nodes.onenew_identity', '=', 'v2_onenew_user_node_traffic.node_identity');
            })
            ->orderBy('v2_onenew_user_node_traffic.server_id')->orderBy('server_rate')->orderBy('v2_onenew_user_node_traffic.id')
            ->offset(($page - 1) * $perPage)->limit($perPage)
            ->get(['v2_onenew_user_node_traffic.*', 'nodes.id as existing_node_id'])->map(fn ($row) => [
                'server_id' => (int) $row->server_id, 'node_name' => $row->node_name, 'server_type' => $row->server_type,
                'server_rate' => (float) $row->server_rate, 'raw_u' => (int) $row->raw_u, 'raw_d' => (int) $row->raw_d,
                'u' => (int) $row->u, 'd' => (int) $row->d, 'total' => (int) $row->u + (int) $row->d,
                'node_deleted' => $row->existing_node_id === null,
            ])->values();
        return response()->json(['data' => $rows, 'total' => $totalRows, 'current_page' => $page, 'per_page' => $perPage,
            'record_at' => $recordAt, 'date' => $day->format('Y-m-d'), 'timezone' => config('app.timezone'),
            'totals' => $totals, 'native_totals' => $nativeTotals, 'unattributed' => $unattributed,
            'coverage' => ['state' => $state, 'reason' => $reason, 'started_at' => $started ? gmdate('Y-m-d\TH:i:s\Z', (int) $started) : null],
        ])->header('Cache-Control', 'no-store');
    }

    public function domains(Request $request): JsonResponse
    {
        [$user, $page, $perPage] = $this->parameters($request);
        [$recordAt, $day] = $this->day($request);
        $end = $day->copy()->addDay()->timestamp;
        $cutoff = time() - DomainAuditController::retention() * 86400;
        $query = DB::table('v2_onenew_domain_events')->where('user_id', $user->id)->where('observed_at', '>=', max($recordAt, $cutoff))
            ->where('observed_at', '<', $end)->select(['server_id', 'node_identity', 'server_type', 'domain'])
            ->selectRaw('MAX(node_name) AS node_name, MIN(observed_at) AS first_seen, MAX(observed_at) AS last_seen, COUNT(*) AS hit_count')
            ->groupBy('server_id', 'node_identity', 'server_type', 'domain');
        $request->validate(['include_background'=>'sometimes|boolean']);
        if (!$request->boolean('include_background')) {
            // Apply before grouping/counting/pagination, including previously collected rows.
            $query->whereNotIn('domain',['www.cloudflare.com','cloudflare.com','cp.cloudflare.com','connectivitycheck.gstatic.com','www.gstatic.com']);
            foreach (['api','telemetry','analytics','metrics'] as $label) $query->where('domain','not like',$label.'.%');
        }
        $total = DB::query()->fromSub(clone $query, 'domains')->count();
        $rows = $query->orderByDesc('last_seen')->orderBy('server_id')->orderBy('domain')
            ->offset(($page - 1) * $perPage)->limit($perPage)->get();
        $existing = DB::table('v2_server')->whereIn('id', $rows->pluck('server_id'))->pluck('onenew_identity', 'id')->all();
        $rows = $rows->map(fn ($row) => ['domain' => $row->domain, 'server_id' => (int) $row->server_id,
            'node_name' => $row->node_name, 'server_type' => $row->server_type,
            'first_seen' => gmdate('Y-m-d\TH:i:s\Z', (int) $row->first_seen), 'last_seen' => gmdate('Y-m-d\TH:i:s\Z', (int) $row->last_seen),
            'hit_count' => (int) $row->hit_count, 'node_deleted' => ($existing[$row->server_id] ?? null) !== $row->node_identity,
        ])->values();
        $state = $end <= $cutoff ? 'expired' : (!DomainAuditController::enabled() ? 'disabled' : ($total ? 'available' : 'unavailable'));
        return response()->json(['data' => $rows, 'total' => $total, 'current_page' => $page, 'per_page' => $perPage,
            'record_at' => $recordAt, 'date' => $day->format('Y-m-d'), 'timezone' => config('app.timezone'),
            'coverage' => ['state' => $state, 'reason' => match ($state) {
                'expired' => 'domain_records_outside_retention', 'disabled' => 'domain_collection_disabled',
                'unavailable' => 'no_reported_domain_records', default => '',
            }, 'retention_days' => DomainAuditController::retention()],
        ])->header('Cache-Control', 'no-store');
    }

    private function parameters(Request $request): array
    {
        $data = $request->validate(['user_id' => 'required|integer|min:1', 'page' => 'nullable|integer|min:1|max:100000',
            'per_page' => 'nullable|integer|min:1|max:100']);
        return [User::findOrFail((int) $data['user_id']), (int) ($data['page'] ?? 1), (int) ($data['per_page'] ?? 50)];
    }

    private function day(Request $request): array
    {
        $data = $request->validate(['record_at' => 'required|integer|min:946684800|max:' . time()]);
        $recordAt = (int) $data['record_at'];
        $day = $this->at($recordAt);
        abort_unless($day->copy()->startOfDay()->timestamp === $recordAt, 422, 'record_at must be panel-calendar midnight');
        return [$recordAt, $day];
    }

    private function at(int $timestamp): Carbon
    {
        return Carbon::createFromTimestamp($timestamp, config('app.timezone'));
    }
}
