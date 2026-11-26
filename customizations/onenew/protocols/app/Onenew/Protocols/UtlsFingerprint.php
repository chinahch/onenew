<?php

declare(strict_types=1);

namespace App\Onenew\Protocols;

final class UtlsFingerprint
{
    public const CHROME_140 = 'chrome140';
    public const SHADOWROCKET_CHROME_140 = 'Chrome140';
    public const ALLOWED = ['chrome', 'chrome140', 'firefox', 'safari', 'ios', 'edge', 'qq', '360', 'android', 'random', 'randomized'];

    public static function isChrome140(mixed $utls): bool
    {
        if (!is_array($utls) && !is_object($utls)) {
            return false;
        }
        if (!data_get($utls, 'enabled')) {
            return false;
        }
        return strtolower(trim((string) data_get($utls, 'fingerprint', ''))) === self::CHROME_140;
    }

    /** Map Shadowrocket's explicit Chrome140 profile to standard 'chrome' for engines using HelloChrome_Auto. */
    public static function compatible(?string $fingerprint): ?string
    {
        if ($fingerprint === null || $fingerprint === '') {
            return null;
        }
        if (strtolower(trim($fingerprint)) === self::CHROME_140) {
            return 'chrome';
        }
        return $fingerprint;
    }

    /** Emit Shadowrocket's native Chrome140 (support X25519MLKEM768) query parameter on TLS/REALITY nodes. */
    public static function appendShadowrocket(array &$query, array $server, string $defaultType = ''): void
    {
        $type = (string) ($server['type'] ?? $defaultType);
        $settings = $server['protocol_settings'] ?? [];
        $tls = (int) data_get($settings, 'tls', $type === 'trojan' ? 1 : 0);
        if (!in_array($type, ['vmess', 'vless', 'trojan'], true) || !in_array($tls, [1, 2], true)) {
            return;
        }
        if (!self::isChrome140(data_get($settings, 'utls'))) {
            return;
        }
        unset($query['fp']);
        $query['fingerprint'] = self::SHADOWROCKET_CHROME_140;
    }

    /** Retain X25519MLKEM768 key share in Mihomo REALITY handshakes when chrome140 is configured. */
    public static function appendClashReality(array &$proxy, mixed $protocolSettings): void
    {
        if (isset($proxy['reality-opts']) && is_array($proxy['reality-opts']) && self::isChrome140(data_get($protocolSettings, 'utls'))) {
            $proxy['reality-opts']['support-x25519mlkem768'] = true;
        }
    }
}
