<?php

namespace App\Onenew\Traffic;

use App\Http\Controllers\Controller;
use App\Models\Server;
use App\Models\ServerMachine;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DomainAuditController extends Controller
{
    public static function enabled(): bool
    {
        return (bool) admin_setting('onenew_domain_audit_enable', false);
    }

    public static function retention(): int
    {
        return max(1, min(30, (int) admin_setting('onenew_domain_audit_retention_days', 7)));
    }

    public function ingest(Request $request): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 65536, 413);
        $machine = $this->machine($request);
        $request->validate(['node_id' => 'required|integer|min:1']);
        $node = Server::where('id', $request->input('node_id'))->where('machine_id', $machine->id)->where('enabled', true)->first();
        abort_unless($node, 403, 'Node not found on this machine');
        if (!self::enabled()) {
            return response()->json(['enabled' => false, 'accepted' => 0])->header('Cache-Control', 'no-store');
        }
        $data = $request->validate([
            'batch_id' => 'required|uuid', 'events' => 'required|array|min:1|max:200',
            'events.*' => 'required|array:user_id,domain,observed_at',
            'events.*.user_id' => 'required|integer|min:1',
            'events.*.domain' => 'required|string|min:1|max:254',
            'events.*.observed_at' => 'required|integer|min:' . (time() - self::retention() * 86400) . '|max:' . (time() + 300),
        ]);
        $rows = [];
        foreach ($data['events'] as $event) {
            $domain = strtolower($event['domain']);
            if (str_ends_with($domain, '.')) {
                $domain = substr($domain, 0, -1);
            }
            abort_unless(self::validDomain($domain), 422, 'Invalid DNS hostname');
            $rows[] = ['user_id' => (int) $event['user_id'], 'server_id' => (int) $node->id,
                'server_type' => (string) $node->type, 'node_name' => mb_substr((string) $node->name, 0, 255),
                'domain' => $domain, 'observed_at' => (int) $event['observed_at']];
        }
        // A machine token grants no authority over arbitrary panel users. Check
        // node group attribution for the whole batch before persisting anything.
        // Do not apply present quota/expiry/ban status: an authentic buffered
        // connection can have happened before those account attributes changed.
        $userIds = array_values(array_unique(array_column($rows, 'user_id')));
        $groups = array_values(array_filter($node->group_ids ?? [], fn ($id) =>
            (is_int($id) || is_string($id)) && ctype_digit((string) $id) && (int) $id > 0));
        $allowedUsers = User::whereIn('id', $userIds)->whereIn('group_id', $groups)->count();
        abort_unless($allowedUsers === count($userIds), 422, 'Invalid audit user for node');
        $duplicate = DB::transaction(function () use ($machine, $node, $data, $rows): bool {
            $inserted = DB::table('v2_onenew_domain_batches')->insertOrIgnore([
                'machine_id' => (int) $machine->id, 'server_id' => (int) $node->id,
                'batch_id' => $data['batch_id'], 'created_at' => time(),
            ]);
            if (!$inserted) {
                return true;
            }
            DB::table('v2_onenew_domain_events')->insert($rows);
            return false;
        }, 3);
        return response()->json(['enabled' => true, 'accepted' => $duplicate ? 0 : count($rows), 'duplicate' => $duplicate])
            ->header('Cache-Control', 'no-store');
    }

    public function settings(Request $request): JsonResponse
    {
        if (!$request->isMethod('get')) {
            $data = $request->validate(['enabled' => 'required|boolean', 'retention_days' => 'required|integer|min:1|max:30']);
            admin_setting(['onenew_domain_audit_enable' => (int) $data['enabled'], 'onenew_domain_audit_retention_days' => (int) $data['retention_days']]);
        }
        return response()->json(['enabled' => self::enabled(), 'retention_days' => self::retention(),
            'metadata_only' => true])->header('Cache-Control', 'no-store');
    }

    public function configuration(Request $request): JsonResponse
    {
        $this->machine($request);
        return response()->json(['enabled' => self::enabled(), 'retention_days' => self::retention()])
            ->header('Cache-Control', 'no-store');
    }

    private function machine(Request $request): ServerMachine
    {
        $request->validate(['machine_id' => 'required|integer|min:1']);
        $token = $request->bearerToken();
        $machine = ServerMachine::find((int) $request->input('machine_id'));
        abort_unless($token && strlen($token) <= 1024 && $machine && $machine->is_active &&
            hash_equals((string) $machine->token, $token), 403, 'Machine not found or disabled');
        return $machine;
    }

    public static function validDomain(string $domain): bool
    {
        if (strlen($domain) > 253 || !str_contains($domain, '.') || filter_var($domain, FILTER_VALIDATE_IP) !== false) {
            return false;
        }
        foreach (explode('.', $domain) as $label) {
            if (!preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/D', $label)) {
                return false;
            }
        }
        return true;
    }
}
