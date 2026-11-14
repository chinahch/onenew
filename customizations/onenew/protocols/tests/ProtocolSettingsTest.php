<?php

namespace Tests\Feature\Onenew;

use App\Models\Server;
use App\Onenew\Protocols\ProtocolSettings;
use App\Protocols\General;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProtocolSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_associative_admin_save_database_reload_node_json_and_xray_links_keep_maps_and_lists(): void
    {
        foreach (['vmess', 'vless', 'trojan'] as $type) {
            // HTTP JSON input is already associative by the time validated() reaches the mutator.
            $settings = json_decode('{"tls":1,"utls":{"enabled":false},"network":"xhttp","network_settings":{"host":"example.com","path":"/","mode":"auto","alpn":"h3","extra":{"headers":{},"xPaddingBytes":"100-1000","noGRPCHeader":false,"noSSEHeader":false,"xmux":{},"downloadSettings":{"tlsSettings":{},"sockopt":{},"xhttpSettings":{"extra":{"headers":{}}}},"list":[],"hosts":["a","b"]}}}', true);
            $node = Server::create(['type'=>$type,'name'=>'h3','host'=>'example.com','port'=>443,'server_port'=>443,'rate'=>'1','protocol_settings'=>$settings]);
            $node = $node->fresh();
            $stored = json_decode($node->getRawOriginal('protocol_settings'));
            $this->assertInstanceOf(\stdClass::class, $stored->network_settings->extra->headers);
            $payload = json_decode(json_encode(ServerService::buildNodeConfig($node), JSON_THROW_ON_ERROR));
            $this->assertInstanceOf(\stdClass::class, $payload->networkSettings->extra->headers);
            $this->assertInstanceOf(\stdClass::class, $payload->networkSettings->extra->downloadSettings->sockopt);
            $this->assertSame([], $payload->networkSettings->extra->list);
            $this->assertSame(['a','b'], $payload->networkSettings->extra->hosts);
            $this->assertSame('h3', $payload->networkSettings->alpn);
            $method = 'build'.ucfirst($type);
            $link = General::$method('00000000-0000-4000-8000-000000000001', $node->toArray());
            if ($type === 'vmess') $query = json_decode(base64_decode(substr(trim($link),8)), true);
            else parse_str(parse_url(trim($link), PHP_URL_QUERY), $query);
            $this->assertSame('h3', $query['alpn']);
            $extra = json_decode($query['extra']);
            $this->assertInstanceOf(\stdClass::class, $extra->headers);
            $this->assertSame([], $extra->list);
        }
    }

    public function test_legacy_empty_maps_and_explicit_empty_extra_are_repaired_without_touching_other_transports(): void
    {
        $this->assertSame('{}', ProtocolSettings::encodeExtra([]));
        $fixed = ProtocolSettings::normalize(['network'=>'xhttp','network_settings'=>['extra'=>['headers'=>[],'list'=>[]]]]);
        $this->assertInstanceOf(\stdClass::class, $fixed['network_settings']['extra']['headers']);
        $this->assertSame([], $fixed['network_settings']['extra']['list']);
        $ws = ['network'=>'ws','network_settings'=>['headers'=>[],'path'=>[]]];
        $this->assertSame($ws, ProtocolSettings::normalize($ws));
        $raw = ProtocolSettings::decode('{"network":"xhttp","network_settings":{"extra":{"unknownObject":{},"unknownList":[]}}}');
        $this->assertInstanceOf(\stdClass::class, $raw['network_settings']['extra']['unknownObject']);
        $this->assertSame([], $raw['network_settings']['extra']['unknownList']);
    }
}
