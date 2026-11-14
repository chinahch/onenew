<?php

namespace App\Onenew\External;

/** Pure, offline share-link conversion. Never fetches a URL or replaces credentials. */
final class ShareLink
{
    private static function fail(string $message): never { throw new \InvalidArgumentException($message); }
    private static function b64(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $decoded = base64_decode($value.str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if ($decoded === false) self::fail('Base64 编码无效');
        return $decoded;
    }

    public static function parse(string $link): array
    {
        if (strlen($link) > 16384 || preg_match('/[\x00-\x20\x7f]/', $link)) self::fail('节点链接包含空白、控制字符或过长');
        if (!preg_match('~^([a-z0-9]+)://(.+)$~i', $link, $match)) self::fail('节点链接格式无效');
        $scheme = strtolower($match[1]);
        if (!in_array($scheme,['vless','vmess','trojan','ss','socks','socks5','http','https','hy2','hysteria2','anytls','tuic'],true)) self::fail('暂不支持该节点协议');
        $type = ['ss'=>'shadowsocks', 'socks5'=>'socks', 'hy2'=>'hysteria', 'hysteria2'=>'hysteria', 'https'=>'http'][$scheme] ?? $scheme;
        if (!in_array($type, ['vless','vmess','trojan','shadowsocks','socks','http','hysteria','anytls','tuic'], true)) self::fail('暂不支持该节点协议');
        $body = explode('#', $match[2], 2); $name = rawurldecode($body[1] ?? ''); $params = [];
        if ($type === 'vmess') {
            try { $vmess = json_decode(self::b64($body[0]), true, 24, JSON_THROW_ON_ERROR); }
            catch (\Throwable) { self::fail('VMess JSON 无效'); }
            if (!is_array($vmess)) self::fail('VMess 配置无效');
            foreach ($vmess as $value) if (!is_scalar($value) && $value !== null) self::fail('VMess 字段必须为简单值');
            $host = (string) ($vmess['add'] ?? ''); $port = $vmess['port'] ?? 0;
            $username = (string) ($vmess['id'] ?? ''); $password = ''; $name = $name ?: (string) ($vmess['ps'] ?? '');
            $params = ['type'=>$vmess['net'] ?? 'tcp', 'security'=>($vmess['tls'] ?? '') === 'tls' ? 'tls' : 'none'];
            foreach (['host','path','sni','alpn','fp'] as $key) if (isset($vmess[$key]) && $vmess[$key] !== '') $params[$key] = (string) $vmess[$key];
        } else {
            $main = $body[0];
            [$address,$query] = array_pad(explode('?',$main,2),2,'');
            if ($type === 'shadowsocks' && !str_contains($address, '@')) $address = self::b64($address);
            // Standard Base64 userinfo can contain '/'. Separate it before the
            // URL parser interprets slashes as a path delimiter.
            $at = strrpos($address,'@');
            $auth = $at === false ? '' : substr($address,0,$at);
            $destination = $at === false ? $address : substr($address,$at+1);
            $url = parse_url($scheme.'://'.$destination.($query!==''?'?'.$query:''));
            if (!$url || empty($url['host'])) self::fail('节点地址无效');
            $host = trim($url['host'], '[]'); $port = $url['port'] ?? ($scheme === 'https' ? 443 : 0);
            if (!empty($url['path']) && $url['path'] !== '/') self::fail('地址后包含未支持的路径，请放在 query 参数中');
            foreach (explode('&', $url['query'] ?? '') as $pair) {
                if ($pair === '') continue;
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
                $key = rawurldecode($key); $value = rawurldecode($value);
                if (array_key_exists($key, $params)) self::fail('节点链接包含重复参数');
                $params[$key] = $value;
            }
            $separator = strpos($auth,':');
            $username = rawurldecode($separator===false?$auth:substr($auth,0,$separator));
            $password = $separator===false?'':rawurldecode(substr($auth,$separator+1));
            if (in_array($type, ['trojan','hysteria','anytls'], true) && $separator!==false) { $username .= ':'.$password; $password = ''; }
            if (in_array($type, ['shadowsocks','socks'], true) && $separator===false && $username !== '') {
                $credentials = str_contains($username, ':') ? $username : self::b64($username);
                if (!str_contains($credentials, ':')) self::fail('认证信息缺少用户名/方法与密码分隔符');
                [$username, $password] = explode(':', $credentials, 2);
            }
        }
        if ($host === '' || strlen($host) > 253 || !is_numeric($port) || (int) $port < 1 || (int) $port > 65535) self::fail('节点地址或端口无效');
        if (in_array($type, ['vless','vmess','tuic'], true) && !preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/i', $username)) self::fail('节点 UUID 无效');
        if (in_array($type, ['vless','vmess'],true) && $password !== '') self::fail('该协议不支持 UUID 后附加密码');
        if (in_array($type, ['trojan','hysteria','anytls'], true) && $username === '') self::fail('节点认证信息为空');
        if ($type === 'shadowsocks' && ($username === '' || $password === '')) self::fail('SS 加密方式或密码为空');
        return compact('type','scheme','host','port','username','password','params','name') + ['vmess'=>$vmess ?? null];
    }

    public static function renamed(string $link, string $name): string
    {
        $node = self::parse($link);
        if ($node['type'] === 'vmess') {
            $value = $node['vmess']; $value['ps'] = $name;
            return 'vmess://'.base64_encode(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        return explode('#', $link, 2)[0].'#'.rawurlencode($name);
    }

    public static function convert(string $link, string $format, string $name): array
    {
        if (!in_array($format, ['clash','singbox'], true)) self::fail('未支持的外部节点订阅格式');
        $n = self::parse($link); $p = $n['params']; $sb = $format === 'singbox'; $type = $n['type'];
        $allowed = ['encryption','flow','security','sni','peer','servername','fp','pbk','sid','spx','alpn','allowInsecure','insecure',
            'type','headerType','host','path','serviceName','mode','extra','obfs','obfs-password','upmbps','downmbps',
            'congestion_control','congestion-controller','udp_relay_mode','disable_sni','allow_insecure'];
        foreach ($p as $key=>$value) if (!in_array($key, $allowed, true)) self::fail('不支持参数 '.$key.'，请使用通用链接订阅或修改链接');
        $specific = ['encryption'=>['vless'], 'flow'=>['vless'], 'obfs'=>['hysteria'], 'obfs-password'=>['hysteria'],
            'upmbps'=>['hysteria'], 'downmbps'=>['hysteria'], 'congestion_control'=>['tuic'], 'congestion-controller'=>['tuic'],
            'udp_relay_mode'=>['tuic'], 'disable_sni'=>['tuic']];
        foreach ($specific as $key=>$types) if (isset($p[$key]) && !in_array($type, $types, true)) self::fail('该协议不支持参数 '.$key);
        if ($n['vmess'] !== null) {
            foreach ($n['vmess'] as $key=>$value) if (!in_array($key, ['v','ps','add','port','id','aid','scy','net','type','host','path','tls','sni','alpn','fp'], true)) self::fail('VMess 含未支持字段 '.$key);
            if (!in_array($n['vmess']['type'] ?? 'none', ['', 'none'], true)) self::fail('VMess 伪装暂不支持转换');
        }
        $targetType = ['shadowsocks'=>$sb?'shadowsocks':'ss','socks'=>$sb?'socks':'socks5','hysteria'=>'hysteria2'][$type] ?? $type;
        $out = ['type'=>$targetType, $sb?'tag':'name'=>$name, 'server'=>$n['host'], $sb?'server_port':'port'=>(int)$n['port']];
        if (!$sb) $out['udp'] = true;
        if (in_array($type, ['vless','vmess','tuic'], true)) $out['uuid'] = $n['username'];
        if (in_array($type, ['trojan','hysteria','anytls'], true)) $out['password'] = $n['username'];
        if ($type === 'tuic') $out['password'] = $n['password'];
        if ($type === 'shadowsocks') { $out[$sb?'method':'cipher'] = $n['username']; $out['password'] = $n['password']; }
        if ($type === 'vmess') { $out[$sb?'alter_id':'alterId'] = (int)($n['vmess']['aid'] ?? 0); $out[$sb?'security':'cipher'] = $n['vmess']['scy'] ?? 'auto'; }
        if (in_array($type, ['socks','http'], true) && $n['username'] !== '') { $out['username'] = $n['username']; $out['password'] = $n['password']; }
        if ($type === 'socks' && $sb) $out['version'] = '5';
        if (($p['flow'] ?? '') !== '') $out['flow'] = $p['flow'];
        if (($p['encryption'] ?? 'none') !== 'none') {
            if ($sb) self::fail('当前标准 sing-box 不支持该 VLESS 原生加密，请使用通用链接或 Mihomo');
            $out['encryption'] = $p['encryption'];
        }
        $security = $p['security'] ?? (in_array($type, ['trojan','hysteria','anytls','tuic'], true) || $n['scheme'] === 'https' ? 'tls' : 'none');
        if (!in_array($security, ['none','tls','reality'], true)) self::fail('不支持的 TLS 安全模式');
        $names = array_filter([$p['sni'] ?? '', $p['peer'] ?? '', $p['servername'] ?? ''],fn ($name)=>$name!=='');
        if (count(array_unique($names))>1) self::fail('SNI 参数互相冲突');
        if ($security === 'none' && array_intersect(['sni','peer','servername','fp','pbk','sid','spx','alpn'],array_keys($p))) self::fail('未启用 TLS 的链接含 TLS 参数');
        if ($security !== 'reality' && array_intersect(['pbk','sid','spx'],array_keys($p))) self::fail('非 REALITY 链接含 REALITY 参数');
        $insecure = false;
        foreach (['allowInsecure','insecure','allow_insecure'] as $key) if (isset($p[$key])) {
            if (!in_array($p[$key], ['0','1','true','false'], true)) self::fail('TLS 校验参数无效');
            $insecure = in_array($p[$key], ['1','true'], true);
        }
        if ($security !== 'none') {
            $sni = $p['sni'] ?? $p['peer'] ?? $p['servername'] ?? $n['host'];
            $tls = ['enabled'=>true, 'server_name'=>$sni, 'insecure'=>$insecure];
            if (isset($p['alpn'])) $tls['alpn'] = explode(',', $p['alpn']);
            if (isset($p['fp'])) $tls['utls'] = ['enabled'=>true,'fingerprint'=>$p['fp']];
            if ($security === 'reality') {
                if (!preg_match('/^[A-Za-z0-9_-]{43}$/', $p['pbk'] ?? '') || !preg_match('/^(?:[a-f0-9]{2}){0,8}$/i', $p['sid'] ?? '')) self::fail('REALITY 公钥或 short ID 无效');
                $tls['reality'] = ['enabled'=>true,'public_key'=>$p['pbk'],'short_id'=>$p['sid'] ?? ''];
                $tls['utls'] ??= ['enabled'=>true,'fingerprint'=>'chrome'];
                if (!empty($p['spx']) && $p['spx'] !== '/') self::fail('当前转换不支持非默认 spiderX，请使用原链接');
            }
            if ($sb) $out['tls'] = $tls;
            else {
                $out['tls'] = true; $out[in_array($type, ['vless','vmess'], true) ? 'servername' : 'sni'] = $sni;
                $out['skip-cert-verify'] = $insecure;
                if (isset($tls['alpn'])) $out['alpn'] = $tls['alpn'];
                if (isset($tls['utls'])) $out['client-fingerprint'] = $tls['utls']['fingerprint'];
                if ($security === 'reality') $out['reality-opts'] = ['public-key'=>$p['pbk'],'short-id'=>$p['sid'] ?? ''];
            }
        }
        $network = ['raw'=>'tcp','splithttp'=>'xhttp'][$p['type'] ?? ''] ?? ($p['type'] ?? 'tcp');
        if (($p['headerType'] ?? 'none') !== 'none') self::fail('该 TCP 伪装不支持转换');
        if (!in_array($network, ['tcp','ws','grpc','http','h2','httpupgrade','xhttp'], true)) self::fail('不支持该传输类型');
        if ($network === 'tcp' && array_intersect(['path','host','serviceName','mode','extra'], array_keys($p))) self::fail('TCP 链接包含不支持的传输参数');
        if ($network !== 'tcp' && !in_array($type, ['vless','vmess','trojan'], true)) self::fail('该协议不支持 V2Ray 传输参数');
        if ($network !== 'xhttp' && (isset($p['extra']) || isset($p['mode']))) self::fail('非 XHTTP 链接包含 XHTTP 参数');
        if ($network === 'grpc' && (isset($p['path']) || isset($p['host']))) self::fail('gRPC 链接包含无法转换的 path/host 参数');
        if ($network !== 'grpc' && isset($p['serviceName'])) self::fail('非 gRPC 链接包含 serviceName 参数');
        if ($network !== 'tcp') {
            if ($sb && $network === 'xhttp') self::fail('标准 sing-box 不支持 XHTTP，请使用 Mihomo 或通用链接');
            $transport = ['type'=>$network === 'h2' ? 'http' : $network];
            if ($network === 'grpc') $transport['service_name'] = $p['serviceName'] ?? '';
            else {
                $transport['path'] = $p['path'] ?? '/';
                if (isset($p['host'])) {
                    if ($network === 'httpupgrade') $transport['host'] = $p['host'];
                    elseif (in_array($network, ['http','h2'], true)) $transport['host'] = [$p['host']];
                    else $transport['headers'] = ['Host'=>$p['host']];
                }
            }
            if ($sb) $out['transport'] = $transport;
            else {
                $out['network'] = $network === 'http' ? 'h2' : ($network === 'httpupgrade' ? 'ws' : $network);
                if ($network === 'ws' || $network === 'httpupgrade') $out['ws-opts'] = ['path'=>$p['path'] ?? '/', 'headers'=>['Host'=>$p['host'] ?? $n['host']]] + ($network === 'httpupgrade' ? ['v2ray-http-upgrade'=>true] : []);
                elseif ($network === 'grpc') $out['grpc-opts'] = ['grpc-service-name'=>$p['serviceName'] ?? ''];
                elseif ($network === 'xhttp') {
                    if ($type !== 'vless') self::fail('Mihomo XHTTP 仅支持 VLESS');
                    $extra = isset($p['extra']) ? json_decode($p['extra'], false, 24, JSON_THROW_ON_ERROR) : new \stdClass();
                    if (!is_object($extra)) self::fail('XHTTP extra 必须为对象');
                    $mode = $p['mode'] ?? 'auto';
                    if (!in_array($mode,['auto','packet-up','stream-up','stream-one'],true)) self::fail('XHTTP mode 无效');
                    $options = ['path'=>$p['path'] ?? '/', 'host'=>$p['host'] ?? $n['host'],'mode'=>$mode];
                    foreach (get_object_vars($extra) as $key=>$value) {
                        if ($key === 'headers') {
                            if (!is_object($value)) self::fail('XHTTP headers 必须为对象');
                            if (get_object_vars($value)) $options['headers'] = get_object_vars($value);
                        } elseif ($key === 'xPaddingBytes' && is_string($value)) $options['x-padding-bytes'] = $value;
                        elseif ($key === 'noGRPCHeader' && is_bool($value)) $options['no-grpc-header'] = $value;
                        elseif ($key === 'noSSEHeader' && $value === false) { /* default client behavior */ }
                        else self::fail('Mihomo 暂不能转换 XHTTP extra.'.$key.'，请使用通用链接');
                    }
                    $out['xhttp-opts'] = $options;
                } else $out['h2-opts'] = ['path'=>$p['path'] ?? '/', 'host'=>[$p['host'] ?? $n['host']]];
            }
        }
        if ($type === 'hysteria') {
            if (isset($p['obfs'])) {
                if ($p['obfs'] !== 'salamander') self::fail('不支持该 Hysteria2 混淆');
                if ($sb) $out['obfs'] = ['type'=>'salamander','password'=>$p['obfs-password'] ?? ''];
                else { $out['obfs'] = 'salamander'; $out['obfs-password'] = $p['obfs-password'] ?? ''; }
            }
            foreach (['upmbps'=>'up','downmbps'=>'down'] as $from=>$to) if (isset($p[$from])) {
                if (filter_var($p[$from],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000000]])===false) self::fail('Hysteria2 速率参数无效');
                $out[$sb ? ($to.'_mbps') : $to] = (int)$p[$from];
            }
        }
        if ($type === 'tuic') {
            $out[$sb?'congestion_control':'congestion-controller'] = $p['congestion_control'] ?? $p['congestion-controller'] ?? 'cubic';
            if (isset($p['udp_relay_mode'])) $out[$sb?'udp_relay_mode':'udp-relay-mode'] = $p['udp_relay_mode'];
            if (isset($p['disable_sni'])) self::fail('该 TUIC disable_sni 参数暂不支持转换');
        }
        return $out;
    }
}
