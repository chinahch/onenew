<?php

namespace App\Onenew;

use App\Models\Server;
use App\Models\ServerMachine;
use App\Services\NodeSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class MachineEgress
{
    public static function forNode(Server $node): ?string
    {
        if (!$node->machine_id) {
            return null;
        }
        $row = DB::table('v2_server_machine_egress')->where('machine_id', $node->machine_id)->first();
        if (!$row) {
            return null;
        }
        $overrides = json_decode($row->node_overrides, true) ?: [];
        return EgressPolicy::effective($row->mode, $overrides[(string) $node->id] ?? [], $node->onenew_identity);
    }

    public static function settings(ServerMachine $machine): array
    {
        $row = DB::table('v2_server_machine_egress')->where('machine_id', $machine->id)->first();
        $mode = $row->mode ?? 'inherit';
        $overrides = $row ? json_decode($row->node_overrides, true) ?: [] : [];
        $nodes = $machine->servers()->orderBy('sort')->get()->map(function (Server $node) use ($mode, $overrides) {
            $entry = $overrides[(string) $node->id] ?? [];
            $override = ($entry['identity'] ?? null) === $node->onenew_identity ? ($entry['mode'] ?? 'inherit') : 'inherit';
            return [
                'id' => $node->id, 'identity' => $node->onenew_identity, 'name' => $node->name, 'type' => $node->type,
                'mode' => $override,
                'effective_mode' => EgressPolicy::effective($mode, $entry, $node->onenew_identity) ?? 'inherit',
            ];
        })->values()->all();
        return ['machine_id' => $machine->id, 'machine_identity' => $machine->onenew_identity, 'mode' => $mode, 'nodes' => $nodes];
    }

    // nodeModes is a complete snapshot: omitted nodes return to inheritance.
    public static function save(ServerMachine $machine, string $mode, array $nodeModes, ?string $expectedMachine = null, ?array $expectedNodes = null): array
    {
        EgressPolicy::validateMode($mode);
        $ids = DB::transaction(function () use ($machine, $mode, $nodeModes, $expectedMachine, $expectedNodes) {
            $currentMachine = ServerMachine::whereKey($machine->id)->lockForUpdate()->firstOrFail();
            abort_if($expectedMachine !== null && $currentMachine->onenew_identity !== $expectedMachine,409,'服务器已变更，请刷新后重试');
            $nodes = Server::where('machine_id', $machine->id)->lockForUpdate()->get()->keyBy('id');
            if ($expectedNodes !== null) {
                abort_unless(count($expectedNodes)===$nodes->count(),409,'节点列表已变更，请刷新后重试');
                foreach ($nodes as $node) abort_unless(($expectedNodes[(string)$node->id] ?? null)===$node->onenew_identity,409,'节点已变更，请刷新后重试');
            }
            $overrides = [];
            foreach ($nodeModes as $id => $nodeMode) {
                $node = $nodes->get($id);
                if (!$node) {
                    throw ValidationException::withMessages(['node_overrides' => 'Node must belong to this machine.']);
                }
                EgressPolicy::validateMode($nodeMode);
                if ($nodeMode !== 'inherit') {
                    $overrides[(string) $id] = ['identity' => $node->onenew_identity, 'mode' => $nodeMode];
                }
            }
            DB::table('v2_server_machine_egress')->updateOrInsert(['machine_id' => $machine->id], [
                'mode' => $mode, 'node_overrides' => json_encode((object) $overrides, JSON_THROW_ON_ERROR),
            ]);
            return $nodes->keys()->all();
        });
        foreach ($ids as $id) {
            NodeSyncService::notifyConfigUpdated((int) $id);
        }
        return self::settings($machine);
    }
}
