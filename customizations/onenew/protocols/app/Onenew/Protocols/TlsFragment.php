<?php

namespace App\Onenew\Protocols;

final class TlsFragment
{
    public const LENGTH = '40-60';
    public const INTERVAL = '30-50';

    public static function validRange(mixed $value, int $minimum, int $maximum): bool
    {
        if (!is_string($value) || !preg_match('/^\d{1,5}(?:-\d{1,5})?$/D', $value)) {
            return false;
        }
        $parts = array_map('intval', explode('-', $value));
        return $parts[0] >= $minimum && end($parts) <= $maximum && $parts[0] <= end($parts);
    }

    /** Shadowrocket's client-only fragment parameter; never a server TLS option. */
    public static function appendShadowrocket(array &$query, array $server): void
    {
        $type = $server['type'] ?? '';
        $settings = $server['protocol_settings'] ?? [];
        $mode = (int) ($settings['tls'] ?? ($type === 'trojan' ? 1 : 0));
        $fragment = $settings['tls_settings']['fragment'] ?? null;
        if (!in_array($type, ['vmess', 'vless', 'trojan'], true) || !in_array($mode, [1, 2], true) ||
            !in_array(($settings['network'] ?? null) ?: 'tcp', ['tcp', 'ws', 'grpc', 'h2', 'httpupgrade', 'xhttp'], true) ||
            !is_array($fragment) || ($fragment['enabled'] ?? false) !== true) {
            return;
        }
        $length = $fragment['length'] ?? self::LENGTH;
        $interval = $fragment['interval'] ?? self::INTERVAL;
        if (!self::validRange($length, 1, 16384) || !self::validRange($interval, 0, 1000)) {
            return;
        }
        $query['fragment'] = '1,'.$length.','.$interval.',tlshello';
    }
}
