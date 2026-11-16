<?php
namespace App\Onenew;
use App\Models\Server;
use Illuminate\Validation\ValidationException;

final class ShadowsocksTransport
{
    public static function normalize(array $s): array
    {
        if (!array_key_exists('cipher', $s)) return $s;
        $tls = (int)($s['tls'] ?? 0);
        $network = $s['network'] ?? 'tcp';
        if ($tls !== 0 && $tls !== 1) self::fail('Shadowsocks 仅支持关闭 TLS 或启用 TLS。');
        if ($network !== 'ws') {
            if ($tls) self::fail('启用 Shadowsocks TLS 时请选择 WebSocket。');
            if (!empty($s['onenew_transport_managed'])) {
                $s['plugin'] = '';
                $s['plugin_opts'] = '';
                $s['onenew_transport_managed'] = false;
            }
            return $s;
        }
        if (!in_array($s['cipher'], ['aes-128-gcm','aes-256-gcm','chacha20-ietf-poly1305','2022-blake3-aes-128-gcm','2022-blake3-aes-256-gcm'], true)) {
            self::fail('该加密方式不支持当前 Xray Shadowsocks WS 多用户模式。');
        }
        $ns = $s['network_settings'] ?? [];
        $headers = array_change_key_case((array)($ns['headers'] ?? []), CASE_LOWER);
        $host = (string)($headers['host'] ?? $ns['host'] ?? '');
        $sni = (string)($s['tls_settings']['server_name'] ?? '');
        $path = (string)($ns['path'] ?? '/');
        if ($host === '' || !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            || ($tls && strcasecmp($host, $sni) !== 0) || !str_starts_with($path, '/')
            || preg_match('/[;\r\n]/', $path) || count(array_diff(array_keys($headers), ['host'])) > 0
            || !empty($s['tls_settings']['allow_insecure']) || !empty($s['tls_settings']['ech']['enabled'])) {
            self::fail('SS WS 的 Host 与 TLS 域名须相同；路径须以 / 开头且不含分号；暂不支持额外请求头、跳过证书验证或 ECH。');
        }
        $s['onenew_transport_managed'] = true;
        $s['plugin'] = 'v2ray-plugin';
        $s['plugin_opts'] = 'mode=websocket'.($tls ? ';tls' : '').';host='.$host.';path='.$path;
        return $s;
    }
    public static function nodeConfig(array $config, Server $node): array
    {
        if ($node->type !== 'shadowsocks') return $config;
        $s = self::normalize($node->protocol_settings);
        if (($s['network'] ?? 'tcp') !== 'ws') return $config;
        $config['kernel_type'] = 'xray';
        $config['tls'] = (int)($s['tls'] ?? 0);
        $config['tls_settings'] = $s['tls_settings'] ?? [];
        // Plugin parameters belong to subscriptions; Xray serves WS natively.
        $config['plugin'] = '';
        $config['plugin_opts'] = '';
        return $config;
    }
    private static function fail(string $message): never
    {
        throw ValidationException::withMessages(['protocol_settings.network_settings' => $message]);
    }
}
