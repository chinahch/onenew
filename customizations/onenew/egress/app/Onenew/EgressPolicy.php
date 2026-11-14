<?php

namespace App\Onenew;

final class EgressPolicy
{
    public const MODES = ['inherit', 'default', 'prefer_ipv4', 'prefer_ipv6'];

    public static function validateMode(string $mode): string
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('Invalid egress preference');
        }
        return $mode;
    }

    public static function effective(string $machineMode, array $override, ?string $identity = null): ?string
    {
        $mode = $machineMode;
        if ($identity !== null && $identity !== '' && ($override['identity'] ?? null) === $identity) {
            $mode = ($override['mode'] ?? 'inherit') === 'inherit' ? $machineMode : $override['mode'];
        }
        self::validateMode($mode);
        return $mode === 'inherit' ? null : $mode;
    }
}
