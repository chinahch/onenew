<?php

namespace App\Onenew;

use App\Models\Server;
use App\Models\ServerMachine;

final class ResourceConnections
{
    public static function valid(object $connection): bool
    {
        if (!empty($connection->machineId)) {
            $machine = ServerMachine::find($connection->machineId);
            $valid = $machine && $machine->is_active && isset($connection->onenewCredentialHash)
                && hash_equals($connection->onenewCredentialHash, hash('sha256', (string) $machine->token));
        } else {
            $node = Server::find($connection->nodeId ?? 0);
            $valid = $node && isset($connection->onenewNodeIdentity)
                && hash_equals((string) $node->onenew_identity, $connection->onenewNodeIdentity);
        }
        if (!$valid) $connection->close();
        return (bool) $valid;
    }
}
