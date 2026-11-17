<?php

/**
 * Builder-only smoke check for PHP installations without Composer dependencies.
 * Loads actual upstream Server, User, Helper and SingBox builders with a minimal
 * attribute-container shim. This is NOT a Laravel/database/authentication test.
 * Full behavior is covered separately by ProbeHealthTest.php in the installed app.
 */
namespace Illuminate\Database\Eloquent {
    class Model
    {
        protected $attributes = [];

        public function __construct(array $attributes = [])
        {
            foreach ($attributes as $key => $value) {
                $this->__set($key, $value);
            }
        }

        public function __get($key)
        {
            $method = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $key))) . 'Attribute';
            return method_exists($this, $method) ? $this->$method($this->attributes[$key] ?? null) : ($this->attributes[$key] ?? null);
        }

        public function __set($key, $value): void
        {
            $method = 'set' . str_replace(' ', '', ucwords(str_replace('_', ' ', $key))) . 'Attribute';
            if (method_exists($this, $method)) {
                $this->$method($value);
            } else {
                $this->attributes[$key] = $value;
            }
        }

        public function __isset($key): bool
        {
            return $this->__get($key) !== null;
        }

        public function getAttribute($key)
        {
            return $this->__get($key);
        }
    }
}
namespace Illuminate\Foundation\Auth {
    class User extends \Illuminate\Database\Eloquent\Model {}
}
namespace Laravel\Sanctum {
    trait HasApiTokens {}
}
namespace App\Services {
    class ServerService { public static function getRoutes(array $ids): array { return []; } }
}
namespace {
    function data_get($target, $key, $default = null)
    {
        foreach (explode('.', $key) as $segment) {
            if (is_array($target) && array_key_exists($segment, $target)) {
                $target = $target[$segment];
            } elseif (is_object($target) && isset($target->$segment)) {
                $target = $target->$segment;
            } else {
                return $default;
            }
        }
        return $target;
    }

    function check(bool $value, string $message): void
    {
        if (!$value) {
            throw new \RuntimeException($message);
        }
    }

    $repo = dirname(__DIR__, 4);
    require __DIR__ . '/../app/Onenew/NodeHealthAttribute.php';
    require $repo . '/app/Models/Server.php';
    require $repo . '/app/Models/User.php';
    require $repo . '/app/Utils/Helper.php';
    require $repo . '/app/Support/AbstractProtocol.php';
    require $repo . '/app/Protocols/SingBox.php';
    require __DIR__ . '/../app/Onenew/ProbeUnavailable.php';
    require __DIR__ . '/../app/Onenew/ShadowsocksTransport.php';
    require __DIR__ . '/../app/Onenew/ProbeOutboundBuilder.php';
    require __DIR__ . '/../app/Onenew/ProbeStore.php';
    require __DIR__ . '/../app/Onenew/ProbeService.php';
    require __DIR__ . '/../app/Onenew/ProbeStages.php';

    $uuid = '00000000-0000-4000-8000-000000000001';
    $user = new \App\Models\User(['id' => 1, 'uuid' => $uuid]);
    $tls = ['tls' => 1, 'tls_settings' => ['server_name' => 'certificate.example']];
    $reality = ['tls' => 2, 'reality_settings' => ['server_name' => 'reality.example',
        'public_key' => rtrim(strtr(base64_encode(str_repeat('p', 32)), '+/', '-_'), '='),
        'short_id' => 'abcd', 'private_key' => 'private-never-export']];
    $enc = ['encryption' => ['enabled' => true, 'encryption' => 'mlkem768x25519plus.native-public-test-key',
        'decryption' => 'private-never-export']];
    $cases = [
        ['vmess-tcp', 'vmess', ['tls' => 0, 'network' => 'tcp']],
        ['vmess-ws-tls', 'vmess', $tls + ['network' => 'ws', 'network_settings' => ['path' => '/ws', 'headers' => ['Host' => 'edge.example']]]],
        ['vmess-grpc-tls', 'vmess', $tls + ['network' => 'grpc', 'network_settings' => ['serviceName' => 'edge']]],
        ['vless-tcp', 'vless', ['tls' => 0, 'network' => 'tcp']],
        ['vless-vision-reality', 'vless', $reality + ['network' => 'tcp', 'flow' => 'xtls-rprx-vision']],
        ['vless-vision-reality-native-tcp-template', 'vless', $reality + ['network' => 'tcp', 'flow' => 'xtls-rprx-vision',
            'network_settings' => ['acceptProxyProtocol' => false, 'header' => ['type' => 'none']]]],
        ['vless-enc-native-tcp-template', 'vless', $enc + $reality + ['network' => 'tcp', 'flow' => 'xtls-rprx-vision',
            'network_settings' => ['acceptProxyProtocol' => false, 'header' => ['type' => 'none']]]],
        ['vless-ws-tls', 'vless', $tls + ['network' => 'ws', 'network_settings' => ['path' => '/edge']]],
        ['vless-grpc-tls', 'vless', $tls + ['network' => 'grpc', 'network_settings' => ['serviceName' => 'edge']]],
        ['vless-tcp-enc', 'vless', $enc + ['tls' => 0, 'network' => 'tcp']],
        ['vless-xhttp', 'vless', ['tls' => 0, 'network' => 'xhttp', 'network_settings' => ['path' => '/edge', 'mode' => 'packet-up']]],
        ['vless-xhttp-tls-enc', 'vless', $enc + $tls + ['network' => 'xhttp', 'network_settings' => ['path' => '/edge', 'alpn' => 'h2']]],
        ['vless-xhttp-h3', 'vless', $tls + ['network' => 'xhttp', 'network_settings' => ['host' => 'certificate.example', 'path' => '/', 'mode' => 'auto', 'alpn' => 'h3',
            'extra' => ['headers' => new \stdClass(), 'xPaddingBytes' => '100-1000', 'noGRPCHeader' => false, 'noSSEHeader' => false]]]],
        ['vless-xhttp-reality-enc', 'vless', $enc + $reality + ['network' => 'xhttp', 'network_settings' => ['path' => '/edge', 'extra' => ['xmux' => ['maxConcurrency' => '16-32']]]]],
        ['trojan-tls', 'trojan', $tls + ['network' => 'tcp']],
        ['ss2022', 'shadowsocks', ['cipher' => '2022-blake3-aes-128-gcm']],
        ['hysteria2', 'hysteria', ['version' => 2, 'tls' => ['server_name' => 'certificate.example'], 'obfs' => ['open' => true, 'type' => 'salamander', 'password' => 'test-obfs']]],
        ['anytls', 'anytls', ['tls' => ['server_name' => 'certificate.example']]],
        ['tuic5', 'tuic', ['version' => 5, 'tls' => ['server_name' => 'certificate.example']]],
        ['socks', 'socks', ['tls' => 0]],
        ['http', 'http', ['tls' => 1, 'tls_settings' => ['server_name' => 'certificate.example']]],
    ];
    $builder = new \App\Onenew\ProbeOutboundBuilder();
    foreach ($cases as [$name, $type, $settings]) {
        $node = new \App\Models\Server(['id' => 1, 'type' => $type, 'name' => $name, 'host' => 'public.example',
            'port' => 443, 'server_port' => 12345, 'created_at' => 1700000000, 'protocol_settings' => $settings]);
        $payload = $builder->build($node, $user);
        $out = $payload['outbound'];
        check($payload['target'] === \App\Onenew\ProbeOutboundBuilder::TARGET, $name . ': wrong target');
        check(!str_contains(json_encode($payload), 'private-never-export'), $name . ': private value exported');
        if ($payload['kernel_type'] === 'xray') {
            check(data_get($out, 'settings.vnext.0.address') === 'public.example', $name . ': wrong public ingress');
            check(data_get($out, 'settings.vnext.0.port') === 443, $name . ': wrong public port');
            check(data_get($out, 'settings.vnext.0.users.0.id') === $uuid, $name . ': wrong user');
            check(isset($out['stream_settings']), $name . ': stream_settings missing');
        } else {
            $localReality = ($type === 'shadowsocks' || (in_array($type, ['vless', 'trojan'], true) && (int) ($settings['tls'] ?? 0) === 2))
                && ($settings['network'] ?? 'tcp') === 'tcp';
            check(data_get($out, 'settings.server') === ($localReality ? '127.0.0.1' : 'public.example'), $name . ': wrong probe ingress');
            check(data_get($out, 'settings.server_port') === ($localReality ? 12345 : 443), $name . ': wrong probe port');
        }
        if ($type === 'shadowsocks') {
            check(data_get($out, 'settings.password') === \App\Utils\Helper::getServerKey(1700000000, 16) . ':' . \App\Utils\Helper::uuidToBase64($uuid, 16), 'SS2022 native password mismatch');
        }
        if ($name === 'vless-xhttp-h3') {
            check(data_get($out, 'stream_settings.tlsSettings.alpn') === ['h3'], 'H3 probe lost ALPN');
            check(str_contains(json_encode($out), '"headers":{}'), 'H3 probe headers must be an object');
            check(data_get($out, 'stream_settings.xhttpSettings.extra.noSSEHeader') === false, 'H3 tuning was dropped');
        }
    }
    // Machine-local plain SS round trips must keep native subscriber credentials.
    foreach (['2022-blake3-aes-256-gcm'=>32,'2022-blake3-aes-128-gcm'=>16,'aes-128-gcm'=>null] as $cipher=>$size) {
        $node = new \App\Models\Server(['id'=>43,'type'=>'shadowsocks','host'=>'8.138.255.100','port'=>30001,
            'server_port'=>40558,'created_at'=>1700000000,'protocol_settings'=>['cipher'=>$cipher]]);
        $payload = $builder->build($node,$user);$settings=$payload['outbound']['settings'];
        check($settings['server']==='127.0.0.1' && $settings['server_port']===40558,$cipher.': plain SS did not use local listener');
        $expected=$size===null?$uuid:\App\Utils\Helper::getServerKey(1700000000,$size).':'.\App\Utils\Helper::uuidToBase64($uuid,$size);
        check($settings['password']===$expected,$cipher.': native account key changed');
        if($size!==null)foreach(explode(':',$settings['password']) as $key)check(strlen(base64_decode($key))===$size,$cipher.': native key length mismatch');
        check($payload['target']===\App\Onenew\ProbeOutboundBuilder::TARGET,'SS target changed');
        check(\App\Onenew\ProbeStages::payload($node,'singbox',$payload)['entry_source']==='local_listener_roundtrip','SS stage mislabeled public');
        check($node->host==='8.138.255.100' && $node->port===30001,'SS subscription endpoint mutated');
        foreach([null,0,-1,65536,'invalid',40558.5,'40558x'] as $invalidPort){
            $node->server_port=$invalidPort;$fallback=$builder->build($node,$user)['outbound']['settings'];
            check($fallback['server']==='8.138.255.100' && $fallback['server_port']===30001,'Invalid SS listener port must preserve public fallback');
        }
    }
    // The production install patch extends SS's native settings schema. This
    // transport-only fixture retains those fields without applying that patch.
    $wsNode=new class(['type'=>'shadowsocks','host'=>'101.69.181.108','port'=>443,'server_port'=>28001,
        'protocol_settings'=>['cipher'=>'aes-128-gcm','network'=>'ws','tls'=>1,'tls_settings'=>['server_name'=>'cdn.example'],
            'network_settings'=>['path'=>'/ss','headers'=>['Host'=>'cdn.example']]]]) extends \App\Models\Server {
        public function setProtocolSettingsAttribute($value) { $this->attributes['protocol_settings']=$value; }
        public function getProtocolSettingsAttribute($value) { return $value; }
    };
    $wsPayload=$builder->build($wsNode,$user);
    check(data_get($wsPayload,'outbound.settings.servers.0.address')==='ip.sb' && data_get($wsPayload,'outbound.settings.servers.0.port')===443,'SS WS/TLS lost CDN ingress');
    check(data_get($wsPayload,'outbound.stream_settings.security')==='tls','SS WS/TLS lost TLS');
    check($wsNode->host==='101.69.181.108' && $wsNode->port===443,'SS WS/TLS subscription mutated');
    foreach ([
        ['network'  => 'xhttp', 'network_settings' => ['extra' => ['downloadSettings' => ['address' => 'bypass.example']]]],
        ['network' => 'xhttp', 'encryption' => ['enabled' => true]],
        ['network' => 'ws', 'network_settings' => ['headers' => ['Host' => "bad\r\nInjected: yes"]]],
        ['network' => 'tcp', 'network_settings' => ['acceptProxyProtocol' => true]],
        ['network' => 'tcp', 'network_settings' => ['acceptProxyProtocol' => 'false']],
    ] as $settings) {
        $node = new \App\Models\Server(['type' => 'vless', 'host' => 'public.example', 'port' => 443,
            'server_port' => 12345, 'protocol_settings' => $settings]);
        $refused = false;
        try {
            $builder->build($node, $user);
        } catch (\App\Onenew\ProbeUnavailable $e) {
            $refused = true;
        }
        check($refused, 'Unsafe/incomplete native configuration was accepted');
    }
    // Native Server::port is a VARCHAR without an Eloquent scalar cast. Newly
    // created/in-memory subscriber models may contain integer ports, while the
    // same persisted listener reloads as a string. They must share a revision.
    $service = new \App\Onenew\ProbeService(new \App\Onenew\ProbeStore(), $builder);
    $revision = new \ReflectionMethod($service, 'revision');
    $attributes = ['id' => 1, 'type' => 'vmess', 'enabled' => true, 'machine_id' => 1, 'host' => 'public.example',
        'server_port' => 12345, 'protocol_settings' => ['tls' => 0, 'network' => 'tcp']];
    $created = new \App\Models\Server($attributes + ['port' => 443]);
    $reloaded = new \App\Models\Server($attributes + ['port' => '443']);
    check($revision->invoke($service, $created) === $revision->invoke($service, $reloaded),
        'Equivalent integer/string native listener ports produced different health revisions');
    echo count($cases) . " native builder projections, 3 plain SS local/native-key/source checks, SS WS/TLS CDN regression, 5 refusal checks and port revision canonicalization passed (attribute shim, not Laravel/runtime validation)\n";
}

