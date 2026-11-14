<?php
namespace App\Onenew;

/** Only the parser's single proxy envelope is accepted; no route/direct/command target. */
class ExitProbePayload
{
    public static function build(string $core, array $out): array
    {
        abort_unless(in_array($core, ['singbox', 'xray', 'xray-caddy'], true), 422);
        abort_if(strlen(json_encode($out, JSON_THROW_ON_ERROR)) > 32768 || array_diff(array_keys($out), ['tag', 'protocol', 'settings', 'stream_settings']), 422);
        $protocol = $out['protocol'] ?? '';
        $allowed = $core === 'singbox' ? ['socks', 'shadowsocks', 'vless', 'vmess', 'trojan', 'hysteria2', 'tuic', 'anytls'] : ['socks', 'shadowsocks', 'vless', 'vmess', 'trojan'];
        abort_unless(in_array($protocol, $allowed, true) && is_array($out['settings'] ?? null), 422, 'Unsupported proxy');
        abort_unless(preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $out['tag'] ?? ''), 422);
        self::safeKeys($out['settings']);
        if ($core === 'singbox') {
            abort_if(isset($out['stream_settings']), 422);
            abort_unless(is_string($out['settings']['server'] ?? null) && strlen($out['settings']['server']) <= 253 &&
                is_int($out['settings']['server_port'] ?? null) && $out['settings']['server_port'] >= 1 && $out['settings']['server_port'] <= 65535, 422);
            abort_if(isset($out['settings']['servers']) || isset($out['settings']['vnext']), 422);
        } else {
            abort_if(isset($out['settings']['server']) || isset($out['settings']['server_port']), 422);
            $servers = $out['settings'][in_array($protocol, ['vmess', 'vless'], true) ? 'vnext' : 'servers'] ?? [];
            abort_unless(is_array($servers) && count($servers) === 1 && isset($servers[0]) && is_string($servers[0]['address'] ?? null) &&
                is_int($servers[0]['port'] ?? null) && $servers[0]['port'] >= 1 && $servers[0]['port'] <= 65535, 422);
            if ($core === 'xray') abort_if(isset($out['stream_settings']) || $protocol === 'trojan', 422, 'Unsupported standard kernel');
            if (isset($out['stream_settings'])) self::safeKeys($out['stream_settings']);
        }
        $out = self::restoreObjectSlots($out);
        return ['action' => 'exit_trace', 'kernel_type' => $core === 'singbox' ? 'singbox' : 'xray', 'outbound' => $out, 'target' => ExitProbeService::TARGET];
    }
    /** Laravel loses {} versus [] during request decoding. Repair only schema maps. */
    private static function restoreObjectSlots(array $value): array
    {
        $maps = ['settings', 'stream_settings', 'tls', 'utls', 'reality', 'transport', 'obfs',
            'tlsSettings', 'realitySettings', 'wsSettings', 'grpcSettings', 'xhttpSettings',
            'headers', 'extra', 'xmux'];
        foreach ($value as $key => $item) {
            if (!is_array($item)) continue;
            $value[$key] = $item === [] && in_array($key, $maps, true) ? new \stdClass() : self::restoreObjectSlots($item);
        }
        return $value;
    }
    private static function safeKeys(array $value, int $depth = 0): void
    {
        abort_if($depth > 10, 422);
        $keys = ['server','server_port','version','username','password','method','uuid','security','alter_id','encryption','flow','tls','transport',
            'enabled','server_name','insecure','alpn','utls','fingerprint','reality','public_key','short_id','type','path','headers','Host','host','service_name',
            'up_mbps','down_mbps','obfs','congestion_control','udp_relay_mode','servers','vnext','address','port','users','user','pass','id','alterId',
            'network','tlsSettings','realitySettings','wsSettings','grpcSettings','xhttpSettings','serverName','publicKey','shortId','spiderX','serviceName',
            'mode','pinnedPeerCertSha256','verifyPeerCertByName','extra','xmux','noGRPCHeader','noSSEHeader','xPaddingBytes','xPaddingObfsMode'];
        foreach ($value as $key => $item) {
            abort_if(!is_int($key) && !in_array($key, $keys, true), 422, 'Unsupported proxy setting');
            if ($key === 'headers') {
                abort_unless(is_array($item) && count($item) <= 32, 422);
                foreach ($item as $name => $content) abort_unless(is_string($name) && preg_match('/^[A-Za-z0-9-]{1,80}$/D', $name) &&
                    is_string($content) && strlen($content) <= 4096 && !preg_match('/[\r\n\x00]/', $content), 422);
            } elseif (is_array($item)) self::safeKeys($item, $depth + 1);
            else abort_unless(is_string($item) || is_int($item) || is_bool($item) || $item === null, 422);
        }
    }
}
