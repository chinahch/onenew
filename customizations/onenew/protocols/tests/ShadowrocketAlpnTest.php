<?php

namespace Tests\Feature\Onenew;

use App\Models\Server;
use App\Protocols\Shadowrocket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ShadowrocketAlpnTest extends TestCase
{
    use RefreshDatabase;

    public function test_xhttp_alpn_is_preserved_in_all_three_shadowrocket_builders(): void
    {
        foreach (['vmess', 'vless', 'trojan'] as $type) {
            foreach (['h3', ['h2', 'http/1.1']] as $alpn) {
                $node = Server::create(['name' => 'alpn-'.$type, 'type' => $type, 'host' => 'node.example',
                    'port' => 443, 'server_port' => 443, 'rate' => 1, 'protocol_settings' => [
                        'tls' => 1, 'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
                        'network' => 'xhttp', 'network_settings' => ['host' => 'tls.example', 'path' => '/xhttp',
                            'mode' => 'auto', 'alpn' => $alpn],
                        'tls_settings' => ['server_name' => 'tls.example', 'fragment' => [
                            'enabled' => true, 'length' => '40-60', 'interval' => '30-50']],
                    ]])->fresh();
                $method = 'build'.ucfirst($type);
                $link = Shadowrocket::$method('00000000-0000-4000-8000-000000000001', $node->toArray());
                parse_str(parse_url(trim($link), PHP_URL_QUERY), $query);
                $this->assertSame(is_array($alpn) ? implode(',', $alpn) : $alpn, $query['alpn']);
                $this->assertSame('1,40-60,30-50,tlshello', $query['fragment']);
                $this->assertSame('/xhttp', $query['path']);
            }
        }
    }

    public function test_missing_alpn_is_not_replaced_with_an_implicit_protocol(): void
    {
        foreach (['vmess', 'vless', 'trojan'] as $type) {
            $method = 'build'.ucfirst($type);
            $link = Shadowrocket::$method('00000000-0000-4000-8000-000000000001', [
                'type' => $type, 'name' => 'without-alpn', 'host' => 'node.example', 'port' => 443,
                'protocol_settings' => ['tls' => 1, 'network' => 'tcp',
                    'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
                    'tls_settings' => ['server_name' => 'tls.example']],
            ]);
            parse_str(parse_url(trim($link), PHP_URL_QUERY), $query);
            $this->assertArrayNotHasKey('alpn', $query);
            $this->assertArrayNotHasKey('fragment', $query);
        }
    }
}
