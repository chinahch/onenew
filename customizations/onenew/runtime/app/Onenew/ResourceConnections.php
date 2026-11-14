<?php

namespace App\Onenew;

use App\Models\Server;
use App\Models\ServerMachine;

final class ResourceConnections
{
    public static function valid(object $connection, ?int $nodeId = null): bool
    {
        if (!empty($connection->machineId)) {
            $machine = ServerMachine::find($connection->machineId);
            $valid = $machine && $machine->is_active && isset($connection->onenewCredentialHash)
                && hash_equals($connection->onenewCredentialHash, hash('sha256', (string) $machine->token));
            if ($valid && $nodeId !== null) {
                $node = Server::find($nodeId);
                $valid = $node && (int) $node->machine_id === (int) $machine->id
                    && ($connection->onenewNodeIdentities[$nodeId] ?? null) === $node->onenew_identity;
            }
        } else {
            $node = Server::find($connection->nodeId ?? 0);
            $valid = $node && isset($connection->onenewNodeIdentity)
                && hash_equals((string) $node->onenew_identity, $connection->onenewNodeIdentity);
        }
        if (!$valid) $connection->close();
        return (bool) $valid;
    }

    public static function refresh(object $connection, array $ids): bool
    {
        if (!self::valid($connection)) return false;
        $nodes = Server::whereIn('id', $ids)->where('machine_id', $connection->machineId)->get();
        if ($nodes->count() !== count($ids)) { $connection->close(); return false; }
        $previous = $connection->onenewNodeIdentities ?? [];
        foreach ($nodes as $node) {
            if (isset($previous[$node->id]) && $previous[$node->id] !== $node->onenew_identity) {
                $connection->close(); return false;
            }
        }
        $connection->onenewNodeIdentities = $nodes->pluck('onenew_identity', 'id')->all();
        return true;
    }
}
