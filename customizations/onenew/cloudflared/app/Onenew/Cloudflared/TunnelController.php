<?php
namespace App\Onenew\Cloudflared;

use App\Http\Controllers\Controller;
use App\Models\ServerMachine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

final class TunnelController extends Controller
{
    private function json(array $data)
    {
        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    public function show(int $machineId, TunnelService $service)
    {
        abort_if($machineId < 1, 404);
        return $this->json($service->show($machineId));
    }

    public function create(Request $r, int $machineId, TunnelService $service)
    {
        abort_if($machineId < 1, 404);
        abort_if(strlen($r->getContent()) > 4096, 413);
        $data = $r->validate([
            'action' => ['required', Rule::in(TunnelService::ACTIONS)],
            'token' => ['nullable', 'string', 'max:1024'],
            'command' => 'prohibited',
            'script' => 'prohibited',
            'url' => 'prohibited',
        ]);
        return $this->json($service->create(
            $machineId,
            $data['action'],
            $data['token'] ?? null,
            (int) Auth::guard('sanctum')->id()
        ));
    }

    public function cancel(string $id, TunnelService $service)
    {
        $this->id($id);
        $service->cancel($id);
        return $this->json(['ok' => true]);
    }

    public function poll(Request $r, TunnelService $service)
    {
        $m = $this->machine($r);
        $data = $r->validate([
            'arch' => ['required', Rule::in(['amd64', 'arm64'])],
            'installed' => 'required|boolean',
            'running' => 'required|boolean',
            'version' => 'nullable|string|max:64',
            'assistant_version' => ['required', 'regex:/^v[0-9]+\.[0-9]+\.[0-9]+$/D'],
        ]);
        return $this->json(['job' => $service->poll($m, $data)]);
    }

    public function status(Request $r, string $id, TunnelService $service)
    {
        abort_if(strlen($r->getContent()) > 2048, 413);
        $m = $this->machine($r);
        $this->id($id);
        $data = $r->validate([
            'lease' => 'required|string|size:64',
            'state' => ['required', Rule::in(['running', 'succeeded', 'failed'])],
            'installed' => 'required|boolean',
            'running' => 'required|boolean',
            'version' => 'nullable|string|max:64',
            'error_code' => ['nullable', Rule::in(array_merge([''], TunnelService::ERRORS))],
            'logs' => 'prohibited',
            'command' => 'prohibited',
            'url' => 'prohibited',
        ]);
        $service->accept($m, $id, $data);
        return $this->json(['ok' => true]);
    }

    private function id(string $id): void
    {
        abort_unless(strlen($id) === 36 && preg_match('/^[a-f0-9-]{36}$/D', $id), 404);
    }

    private function machine(Request $r): ServerMachine
    {
        $r->validate(['machine_id' => 'required|integer|min:1']);
        $token = $r->bearerToken();
        $m = ServerMachine::find((int) $r->input('machine_id'));
        abort_unless($token && strlen($token) <= 1024 && $m && $m->is_active && hash_equals((string) $m->token, $token), 403);
        return $m;
    }
}
