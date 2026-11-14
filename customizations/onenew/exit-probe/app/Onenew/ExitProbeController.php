<?php
namespace App\Onenew;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMachine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ExitProbeController extends Controller
{
    public function runtime(string $node)
    {
        $record = Server::findOrFail((int) $node);
        $metrics = $record->metrics ?? [];
        return response()->json(['node_id' => (int) $record->id, 'machine_id' => (int) $record->machine_id,
            'kernel_type' => $metrics['kernel_type'] ?? null, 'onenew_runtime' => ($metrics['onenew_runtime'] ?? false) === true,
            'updated_at' => $metrics['updated_at'] ?? null])->header('Cache-Control', 'no-store');
    }
    public function create(Request $request, ExitProbeService $service)
    {
        abort_if(strlen($request->getContent()) > 40000, 413);
        $data = $request->validate(['machine_id' => 'nullable|integer|min:1|required_without:node_id', 'node_id' => 'nullable|integer|min:1',
            'core' => ['required', Rule::in(['singbox', 'xray', 'xray-caddy'])], 'outbound' => 'required|array', 'target' => 'prohibited', 'command' => 'prohibited']);
        $node = !empty($data['node_id']) ? Server::with('machine')->findOrFail($data['node_id']) : null;
        $machine = ServerMachine::findOrFail($data['machine_id'] ?? $node?->machine_id);
        abort_if($node && (int) $node->machine_id !== (int) $machine->id, 422);
        return response()->json($service->create($machine, (int) Auth::guard('sanctum')->id(), $data['core'], $data['outbound'], $node))->header('Cache-Control', 'no-store');
    }
    public function status(Request $request, string $id, ExitProbeService $service)
    {
        $this->id($id);
        return response()->json($service->status($id, (int) Auth::guard('sanctum')->id()))->header('Cache-Control', 'no-store');
    }
    public function poll(Request $request, ExitProbeService $service)
    {
        return response()->json(['tasks' => $service->poll($this->machine($request))])->header('Cache-Control', 'no-store');
    }
    public function result(Request $request, string $id, ExitProbeService $service)
    {
        $machine = $this->machine($request); $this->id($id);
        abort_if(strlen($request->getContent()) > 2048, 413);
        $data = $request->validate(['lease' => 'required|string|min:1|max:100',
            'state' => ['required', Rule::in(['succeeded', 'failed', 'expired'])],
            'error_code' => ['nullable', Rule::in(['', 'proxy_failed', 'target_failed', 'timeout', 'configuration_unavailable', 'unsupported', 'expired'])],
            'exit_ip' => 'nullable|ip', 'country' => 'nullable|regex:/^[A-Z]{2}$/D', 'diagnostics' => 'prohibited']);
        $success = $data['state'] === 'succeeded';
        abort_if(($success && (empty($data['exit_ip']) || empty($data['country']) || !empty($data['error_code']))) ||
            (!$success && (empty($data['error_code']) || isset($data['exit_ip']) || isset($data['country']))) ||
            ($data['state'] === 'expired' && $data['error_code'] !== 'expired'), 422);
        $service->accept($machine, $id, $data);
        return response()->json(['ok' => true])->header('Cache-Control', 'no-store');
    }
    private function id(string $id): void { abort_unless(preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/iD', $id), 404); }
    private function machine(Request $request): ServerMachine
    {
        $request->validate(['machine_id' => 'required|integer|min:1']);
        $token = $request->bearerToken();
        $machine = ServerMachine::find((int) $request->input('machine_id'));
        abort_unless($token && strlen($token) <= 1024 && $machine && $machine->is_active && hash_equals((string) $machine->token, $token), 403);
        return $machine;
    }
}
