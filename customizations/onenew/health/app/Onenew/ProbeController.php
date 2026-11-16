<?php

namespace App\Onenew;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use App\Services\ServerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ProbeController extends Controller
{
    public function poll(Request $request, ProbeService $service): JsonResponse
    {
        return response()->json(['tasks' => $service->poll($this->machine($request))])->header('Cache-Control', 'no-store');
    }

    public function result(Request $request, string $id, ProbeService $service): JsonResponse
    {
        $machine = $this->machine($request);
        abort_unless(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id), 404);
        abort_if(strlen($request->getContent()) > 8192, 413);
        $data = $request->validate([
            'lease' => 'required|string|min:1|max:100',
            'state' => ['required', Rule::in(['succeeded', 'failed', 'expired', 'unsupported'])],
            'error_code' => ['nullable', 'string', Rule::in(['', 'proxy_failed', 'target_failed', 'timeout', 'configuration_unavailable', 'unsupported', 'expired', 'connector_only'])],
            'latency_ms' => 'nullable|integer|min:0|max:15000',
            'ping_ms' => 'nullable|numeric|min:0|max:15000',
            'ping_error_code' => ['nullable', 'string', Rule::in(['', 'icmp_timeout', 'icmp_unavailable', 'icmp_resolve_failed'])],
            'diagnostics' => 'prohibited',
            'stages' => 'sometimes|array:version,entry,landings',
            'stages.version' => 'required_with:stages|integer|in:1',
            'stages.entry' => 'required_with:stages|array:state,error_code,source',
            'stages.entry.source' => 'required_with:stages|string|in:public_roundtrip,local_listener_roundtrip,cdn_roundtrip',
            'stages.entry.state' => 'required_with:stages|string',
            'stages.entry.error_code' => 'nullable|string|max:40',
            'stages.landings' => 'present_with:stages|array|max:8',
            'stages.landings.*' => 'array:id,state,error_code',
            'stages.landings.*.id' => 'required|string|max:100',
            'stages.landings.*.state' => 'required|string|max:20',
            'stages.landings.*.error_code' => 'nullable|string|max:40',
        ]);
        $error = $data['error_code'] ?? '';
        abort_if(($data['state'] === 'succeeded' && $error !== '') ||
            ($data['state'] === 'failed' && $error === '') ||
            ($data['state'] === 'expired' && $error !== 'expired') ||
            ($data['state'] === 'unsupported' && $error !== 'unsupported') ||
            (isset($data['ping_ms']) && ($data['state'] !== 'succeeded' || !empty($data['ping_error_code']))), 422, 'Invalid probe result');
        $service->accept($machine, $id, $data);
        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }

    public function nodes(Request $request, ProbeService $service): JsonResponse
    {
        $user = User::find(Auth::guard('sanctum')->id());
        if (!$user || $user->banned || (int) $user->u + (int) $user->d >= (int) $user->transfer_enable ||
            ($user->expired_at !== null && (int) $user->expired_at < now()->timestamp)) {
            return response()->json([])->header('Cache-Control', 'no-store');
        }
        // Use native visibility/group/quota rules, then project only public fields.
        $ids = array_column(ServerService::getAvailableServers($user), 'id');
        $nodes = Server::with('machine')->whereIn('id', $ids)->where('enabled', true)->orderBy('sort')->orderBy('id')->get();
        return response()->json($nodes->map(fn (Server $node) => [
            'id' => (int) $node->id, 'name' => $node->name, 'type' => $node->type, 'active' => (bool) $node->enabled,
            'machine_last_report_at' => $node->machine?->last_seen_at ? gmdate('Y-m-d\TH:i:s\Z', $node->machine->last_seen_at) : null,
            'latest_probe' => $service->status($node),
        ])->values())->header('Cache-Control', 'no-store');
    }

    private function machine(Request $request): ServerMachine
    {
        $request->validate(['machine_id' => 'required|integer|min:1']);
        $token = $request->bearerToken();
        abort_if(!$token || strlen($token) > 1024, 403, 'Machine not found or disabled');
        $machine = ServerMachine::find((int) $request->input('machine_id'));
        // Same native ServerMachine token source and active check as V2 machine auth.
        // Bearer-only: no token in query strings, URLs, task payloads or logs.
        abort_unless($machine && $machine->is_active && hash_equals((string) $machine->token, $token), 403, 'Machine not found or disabled');
        $machine->forceFill(['last_seen_at' => now()->timestamp])->saveQuietly();
        return $machine;
    }
}
