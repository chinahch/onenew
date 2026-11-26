<?php

namespace App\Onenew;

use App\Models\ServerMachine;

final class MachineConnectionStatus
{
    private const ONLINE_WINDOW_SECONDS = 300;

    public static function forMachine(?ServerMachine $machine, int $now): string
    {
        if ($machine !== null && !$machine->is_active) {
            return 'inactive';
        }
        if (!$machine?->last_seen_at) {
            return 'never';
        }

        return $now - $machine->last_seen_at < self::ONLINE_WINDOW_SECONDS ? 'online' : 'offline';
    }
}
