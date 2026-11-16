<?php

namespace App\Onenew;

use App\Models\Server;
use App\Models\User;
use App\Protocols\SingBox;
use App\Utils\Helper;

/** Build a client through the same public entry used by subscribers. */
class ProbeOutboundBuilder extends SingBox
{
    public const TARGET = 'https://www.cloudflare.com/cdn-cgi/trace';

    public function __construct()
    {
        // These protected builders need a client version, not a subscription or template.
        $this->clientName = 'sing-box';
        $this->clientVersion = '1.12.0';
    }

    public function build(Server $node, User $user): array
    {
        if ($cdnHost = $this->cdnProbeHost($node)) {
            $node = clone $node;
            $node->host = $cdnHost;
            $node->port = 443;
        }
        $settings = $node->protocol_settings ?? [];
        $type = $node->type;
        $network = data_get($settings, 'network') ?: 'tcp';
        $tls = data_get($settings, 'tls', 0);
        $port = $node->port ?: $node->server_port;
        if (!$node->host || !is_numeric($port) || (int) $port < 1 || (int) $port > 65535 || $node->parent_id) {
            throw new ProbeUnavailable('configuration_unavailable');
        }
        if ($type === 'shadowsocks' && $network === 'ws') {
            return $this->buildShadowsocksWs($node, $user);
        }
        // Use Xray's native client schema when Sing-box cannot preserve the node.
        if (data_get($settings, 'encryption.enabled') || in_array($network, ['xhttp', 'splithttp'], true)) {
            return $this->buildXray($node, $user, (int) $port);
        }
        if (!in_array($network, ['tcp', 'ws', 'grpc', 'httpupgrade'], true) ||
            data_get($settings, 'network_settings.header.type', 'none') !== 'none') {
            throw new ProbeUnavailable('unsupported');
        }
        if (!in_array($type, ['vmess', 'vless', 'trojan', 'shadowsocks', 'hysteria', 'tuic', 'anytls', 'socks', 'http'], true)) {
            throw new ProbeUnavailable('unsupported');
        }
        if (in_array($type, ['vmess', 'vless', 'trojan', 'socks', 'http'], true) && !in_array((int) $tls, [0, 1, 2], true)) {
            throw new ProbeUnavailable('unsupported');
        }
        if (($type === 'vmess' && (int) $tls === 2) || ($type === 'trojan' && (int) $tls === 0) ||
            ($type === 'socks' && (int) $tls !== 0) ||
            ($type === 'http' && (int) $tls === 2) ||
            ($type === 'tuic' && (int) data_get($settings, 'version', 5) !== 5) ||
            ($type === 'hysteria' && (int) data_get($settings, 'version', 2) !== 2) ||
            ($type === 'shadowsocks' && (data_get($settings, 'plugin') || data_get($settings, 'obfs')))) {
            throw new ProbeUnavailable('unsupported');
        }
        if ((int) $tls === 2 && in_array($type, ['vless', 'trojan'], true)) {
            if (!data_get($settings, 'reality_settings.public_key') || !data_get($settings, 'reality_settings.server_name')) {
                throw new ProbeUnavailable('configuration_unavailable');
            }
            // REALITY clients need a TLS fingerprint even when the subscription omitted it.
            $settings['utls'] = array_replace(['enabled' => true, 'fingerprint' => 'chrome'], $settings['utls'] ?? []);
            $settings['utls']['enabled'] = true;
        }
        $dialHost = $node->host;
        $dialPort = (int) $port;
        // Keep the subscription's TLS name and dial through its public ingress.
        $tlsKey = in_array($type, ['hysteria', 'tuic', 'anytls'], true) ? 'tls' : 'tls_settings';
        if (!data_get($settings, $tlsKey . '.server_name')) {
            $settings[$tlsKey] = array_replace((array) ($settings[$tlsKey] ?? []), ['server_name' => $node->host]);
        }
        $server = [
            'name' => 'onenew-probe', 'host' => $dialHost, 'port' => $dialPort,
            'protocol_settings' => $settings, 'password' => $node->generateServerPassword($user),
        ];
        $method = match ($type) {
            'vmess' => 'buildVmess', 'vless' => 'buildVless', 'trojan' => 'buildTrojan',
            'shadowsocks' => 'buildShadowsocks', 'hysteria' => 'buildHysteria', 'tuic' => 'buildTuic',
            'anytls' => 'buildAnyTLS', 'socks' => 'buildSocks', 'http' => 'buildHttp',
        };
        $out = $this->$method($server['password'], $server);
        if (!empty(data_get($settings, 'network_settings.alpn'))) {
            if (empty($out['tls']['enabled'])) {
                throw new ProbeUnavailable('unsupported');
            }
            $out['tls']['alpn'] = $this->alpn(data_get($settings, 'network_settings.alpn'));
        }
        $protocol = $out['type'];
        unset($out['type'], $out['tag']);
        return [
            'kernel_type' => 'singbox',
            'outbound' => ['tag' => 'onenew-probe', 'protocol' => $protocol, 'settings' => $out],
            'target' => self::TARGET,
        ];
    }

    /** Operator-requested alternate edge for matching CDN transport configurations. */
    public function cdnProbeHost(Server $node): ?string
    {
        $s = $node->protocol_settings ?? [];
        $network = data_get($s, 'network');
        $ws = $network === 'ws' && in_array($node->type, ['vless', 'vmess', 'trojan', 'shadowsocks'], true);
        $alpn = data_get($s, 'network_settings.alpn', []);
        $alpn = is_string($alpn) ? array_map('trim', explode(',', $alpn)) : (is_array($alpn) ? $alpn : []);
        $xhttp = $node->type === 'vless' && in_array($network, ['xhttp', 'splithttp'], true)
            && in_array('h2', $alpn, true);
        if ((!$ws && !$xhttp) || (int) data_get($s, 'tls', 0) !== 1
            || !filter_var(trim((string) $node->host, '[]'), FILTER_VALIDATE_IP)) {
            return null;
        }
        $sni = strtolower(trim((string) data_get($s, 'tls_settings.server_name', '')));
        $headers = array_change_key_case((array) data_get($s, 'network_settings.headers', []), CASE_LOWER);
        $transportHost = $xhttp ? data_get($s, 'network_settings.host', '')
            : ($headers['host'] ?? data_get($s, 'network_settings.host', ''));
        $transportHost = strtolower(trim((string) $transportHost));
        if ($sni === '' || $sni !== $transportHost || !str_contains($sni, '.')
            || filter_var($sni, FILTER_VALIDATE_IP)
            || !filter_var($sni, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            return null;
        }
        return 'ip.sb';
    }

    private function buildShadowsocksWs(Server $node, User $user): array
    {
        $s = ShadowsocksTransport::normalize($node->protocol_settings);
        $tls = (int)($s['tls'] ?? 0);
        $ns = $s['network_settings'];
        $headers = $ns['headers'] ?? (isset($ns['host']) ? ['Host'=>$ns['host']] : []);
        $stream = ['network'=>'ws', 'security'=>$tls ? 'tls' : 'none',
            'wsSettings'=>['path'=>$ns['path'] ?? '/', 'headers'=>$headers ?: new \stdClass()]];
        if ($tls) $stream['tlsSettings'] = ['serverName'=>$s['tls_settings']['server_name']];
        return ['kernel_type'=>'xray', 'target'=>self::TARGET, 'outbound'=>[
            'tag'=>'onenew-probe', 'protocol'=>'shadowsocks',
            'settings'=>['servers'=>[['address'=>$node->host, 'port'=>(int)$node->port,
                'method'=>$s['cipher'], 'password'=>$node->generateServerPassword($user)]]],
            'stream_settings'=>$stream]];
    }

    private function buildXray(Server $node, User $user, int $port): array
    {
        $s = $node->protocol_settings ?? [];
        $type = $node->type;
        $network = data_get($s, 'network') ?: 'tcp';
        $network = $network === 'splithttp' ? 'xhttp' : $network;
        if (!in_array($type, ['vless', 'vmess', 'trojan'], true) ||
            !in_array($network, ['tcp', 'ws', 'grpc', 'httpupgrade', 'xhttp'], true) ||
            ($type !== 'vless' && data_get($s, 'encryption.enabled'))) {
            throw new ProbeUnavailable('unsupported');
        }
        $ns = $this->clientTransportSettings(data_get($s, 'network_settings') ?: []);
        if (!is_array($ns)) {
            throw new ProbeUnavailable('unsupported');
        }
        $allowed = match ($network) {
            'tcp' => ['header', 'alpn'], 'ws' => ['path', 'headers', 'host', 'alpn'],
            'grpc' => ['serviceName', 'service_name', 'alpn'],
            'httpupgrade' => ['path', 'host', 'headers', 'alpn'],
            'xhttp' => ['path', 'host', 'mode', 'extra', 'alpn'],
        };
        if (array_diff(array_keys($ns), $allowed) || data_get($ns, 'header.type', 'none') !== 'none') {
            throw new ProbeUnavailable('unsupported');
        }
        $this->validateHeaders($ns['headers'] ?? []);
        $account = ['id' => $user->uuid];
        if ($type === 'vless') {
            $encryption = data_get($s, 'encryption.enabled') ? data_get($s, 'encryption.encryption') : 'none';
            if (!$encryption || (data_get($s, 'encryption.enabled') && $encryption === 'none')) {
                throw new ProbeUnavailable('configuration_unavailable');
            }
            $account['encryption'] = $encryption;
            if ($flow = data_get($s, 'flow')) {
                $account['flow'] = $flow;
            }
        } else {
            $account += ['security' => 'auto', 'alterId' => 0];
        }
        $protocolSettings = $type === 'trojan'
            ? ['servers' => [['address' => $node->host, 'port' => $port, 'password' => $user->uuid]]]
            : ['vnext' => [['address' => $node->host, 'port' => $port, 'users' => [$account]]]];
        $stream = ['network' => $network, 'security' => 'none'];
        $tls = (int) data_get($s, 'tls', 0);
        if ($tls === 1) {
            // This pinned Xray removed allowInsecure. Do not emit an ignored flag
            // or quietly drop ECH when only Sing-box's mapping is implemented.
            if (data_get($s, 'tls_settings.allow_insecure') || data_get($s, 'tls_settings.ech.enabled')) {
                throw new ProbeUnavailable('unsupported');
            }
            $stream['security'] = 'tls';
            $ts = ['serverName' => data_get($s, 'tls_settings.server_name') ?: $node->host];
            if (data_get($s, 'utls.enabled')) {
                $ts['fingerprint'] = Helper::getTlsFingerprint(data_get($s, 'utls'));
            }
            if (!empty($ns['alpn'])) {
                $ts['alpn'] = $this->alpn($ns['alpn']);
            }
            $stream['tlsSettings'] = $ts;
        } elseif ($tls === 2) {
            $rs = data_get($s, 'reality_settings') ?: [];
            if (empty($rs['public_key']) || empty($rs['server_name'])) {
                throw new ProbeUnavailable('configuration_unavailable');
            }
            if (!empty($rs['allow_insecure'])) {
                throw new ProbeUnavailable('unsupported');
            }
            $stream['security'] = 'reality';
            $stream['realitySettings'] = [
                'serverName' => $rs['server_name'], 'publicKey' => $rs['public_key'],
                'shortId' => $rs['short_id'] ?? '',
                'fingerprint' => data_get($s, 'utls.enabled') ? Helper::getTlsFingerprint(data_get($s, 'utls')) : 'chrome',
            ];
        } elseif ($tls !== 0) {
            throw new ProbeUnavailable('unsupported');
        }
        if ($type === 'trojan' && $tls === 0) {
            throw new ProbeUnavailable('unsupported');
        }
        switch ($network) {
            case 'ws':
                $headers = array_replace($ns['headers'] ?? [], isset($ns['host']) ? ['Host' => $ns['host']] : []);
                $stream['wsSettings'] = ['path' => $ns['path'] ?? '/', 'headers' => $headers ?: new \stdClass()];
                break;
            case 'grpc':
                $stream['grpcSettings'] = ['serviceName' => $ns['serviceName'] ?? $ns['service_name'] ?? ''];
                break;
            case 'httpupgrade':
                $stream['httpupgradeSettings'] = ['path' => $ns['path'] ?? '/', 'host' => $ns['host'] ?? $node->host,
                    'headers' => $ns['headers'] ?? new \stdClass()];
                break;
            case 'xhttp':
                $xs = ['path' => $ns['path'] ?? '/', 'host' => $ns['host'] ?? $node->host, 'mode' => $ns['mode'] ?? 'auto'];
                if (!empty($ns['extra'])) {
                    if ($extra = $this->xhttpExtra($ns['extra'])) {
                        $xs['extra'] = $extra;
                    }
                }
                $stream['xhttpSettings'] = $xs;
                break;
        }
        return ['kernel_type' => 'xray', 'outbound' => ['tag' => 'onenew-probe', 'protocol' => $type,
            'settings' => $protocolSettings, 'stream_settings' => $stream], 'target' => self::TARGET];
    }

    private function xhttpExtra(mixed $extra): array
    {
        if ($extra instanceof \stdClass) $extra = get_object_vars($extra);
        if ($extra === []) return [];
        if (is_array($extra) && ($extra['xmux'] ?? null) instanceof \stdClass) {
            $extra['xmux'] = get_object_vars($extra['xmux']);
        }
        // Preserve safe native tuning, but prohibit alternate download endpoints,
        // dialers and arbitrary TLS settings that would bypass the actual ingress.
        $allowed = ['headers', 'noGRPCHeader', 'noSSEHeader', 'xPaddingBytes', 'scMaxEachPostBytes', 'scMinPostsIntervalMs',
            'scMaxBufferedPosts', 'scStreamUpServerSecs', 'xmux', 'xPaddingObfsMode', 'xPaddingKey',
            'xPaddingHeader', 'xPaddingPlacement', 'sessionIDPlacement', 'sessionIDKey', 'seqPlacement', 'seqKey',
            'uplinkDataPlacement', 'uplinkDataKey', 'uplinkChunkSize'];
        if (!is_array($extra) || array_is_list($extra) || array_diff(array_keys($extra), $allowed)) {
            throw new ProbeUnavailable('unsupported');
        }
        if (isset($extra['xmux']) && (!is_array($extra['xmux']) || array_diff(array_keys($extra['xmux']),
            ['maxConcurrency', 'maxConnections', 'cMaxReuseTimes', 'hMaxRequestTimes', 'hMaxReusableSecs', 'hKeepAlivePeriod']))) {
            throw new ProbeUnavailable('unsupported');
        }
        $this->validateHeaders($extra['headers'] ?? []);
        foreach (['noGRPCHeader', 'noSSEHeader'] as $field) {
            if (isset($extra[$field]) && !is_bool($extra[$field])) {
                throw new ProbeUnavailable('configuration_unavailable');
            }
        }
        // Empty PHP lists are not native Xray objects.
        foreach (['xmux', 'headers'] as $field) {
            if (isset($extra[$field]) && $extra[$field] === []) {
                $extra[$field] = new \stdClass();
            }
        }
        return $extra;
    }

    private function alpn(mixed $value): array
    {
        if (is_string($value)) {
            return array_values(array_filter(array_map('trim', explode(',', $value))));
        }
        if (is_array($value) && array_is_list($value)) {
            return $value;
        }
        throw new ProbeUnavailable('unsupported');
    }

    private function validateHeaders(mixed $headers): void
    {
        if ($headers instanceof \stdClass) $headers = get_object_vars($headers);
        if (!is_array($headers)) {
            throw new ProbeUnavailable('unsupported');
        }
        foreach ($headers as $name => $value) {
            if (!is_string($name) || !is_string($value) || preg_match('/[\r\n]/', $name . $value)) {
                throw new ProbeUnavailable('unsupported');
            }
        }
    }

    private function clientTransportSettings(mixed $settings): mixed
    {
        // This is an inbound-only switch present in the official TCP template.
        // Disabled PROXY protocol requires no client behavior; enabled PROXY
        // protocol cannot be claimed as tested without sending its prelude.
        if (is_array($settings) && array_key_exists('acceptProxyProtocol', $settings)) {
            if ($settings['acceptProxyProtocol'] !== false) {
                throw new ProbeUnavailable('unsupported');
            }
            unset($settings['acceptProxyProtocol']);
        }
        return $settings;
    }

    protected function buildTransport(array $protocol_settings, array $server): ?array
    {
        $network = data_get($protocol_settings, 'network') ?: 'tcp';
        $s = $this->clientTransportSettings(data_get($protocol_settings, 'network_settings') ?: []);
        if (!is_array($s)) {
            throw new ProbeUnavailable('unsupported');
        }
        // Only explicitly represented transport options are accepted. Extra dial/download
        // addresses, socket options, or unknown native fields cannot bypass the listener.
        $allowed = match ($network) {
            'tcp' => ['header', 'alpn'], 'ws' => ['path', 'headers', 'host', 'alpn'],
            'grpc' => ['serviceName', 'service_name', 'alpn'], 'httpupgrade' => ['path', 'host', 'headers', 'alpn'],
            default => [],
        };
        if (array_diff(array_keys($s), $allowed)) {
            throw new ProbeUnavailable('unsupported');
        }
        $this->validateHeaders($s['headers'] ?? []);
        return match ($network) {
            'tcp' => null,
            'ws' => array_filter(['type' => 'ws', 'path' => $s['path'] ?? '/',
                'headers' => array_replace($s['headers'] ?? [], isset($s['host']) ? ['Host' => $s['host']] : [])], fn ($v) => $v !== []),
            'grpc' => ['type' => 'grpc', 'service_name' => $s['serviceName'] ?? $s['service_name'] ?? ''],
            'httpupgrade' => ['type' => 'httpupgrade', 'path' => $s['path'] ?? '/',
                'host' => $s['host'] ?? data_get($protocol_settings, 'tls_settings.server_name', $server['host']), 'headers' => $s['headers'] ?? new \stdClass()],
            default => throw new ProbeUnavailable('unsupported'),
        };
    }
}

