<?php
namespace Tests\Feature\Onenew;
use App\Models\Server;
use App\Models\User;
use App\Services\ServerService;
use App\Onenew\ProbeOutboundBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
final class ShadowsocksTransportTest extends TestCase
{
    use RefreshDatabase;
    public function test_ss_ws_tls_persists_reaches_xray_and_exports_plugin_and_probe(): void
    {
        $node = Server::create(['name'=>'ss-ws','type'=>'shadowsocks','host'=>'101.69.181.108',
            'port'=>'443','server_port'=>28001,'rate'=>1,'enabled'=>true,
            'protocol_settings'=>['cipher'=>'aes-128-gcm','tls'=>1,'network'=>'ws',
                'tls_settings'=>['server_name'=>'cdn.example'],
                'network_settings'=>['path'=>'/ss','headers'=>['Host'=>'cdn.example']]]]);
        $node = $node->fresh();
        $s = $node->protocol_settings;
        $this->assertSame(1, $s['tls'] ?? null);
        $this->assertSame('ws', $s['network'] ?? null);
        $this->assertSame('v2ray-plugin', $s['plugin']);
        $this->assertStringContainsString('tls', $s['plugin_opts']);
        $this->assertStringContainsString('host=cdn.example', $s['plugin_opts']);
        $this->assertStringContainsString('path=/ss', $s['plugin_opts']);
        $config = ServerService::buildNodeConfig($node);
        $this->assertSame('xray', $config['kernel_type']);
        $this->assertSame(1, $config['tls']);
        $this->assertSame('cdn.example', $config['tls_settings']['server_name']);
        $user = new User(['uuid'=>'00000000-0000-4000-8000-000000000001']);
        $payload = app(ProbeOutboundBuilder::class)->build($node, $user);
        $this->assertSame('ip.sb', $payload['outbound']['settings']['servers'][0]['address']);
        $this->assertSame('xray', $payload['kernel_type']);
        $this->assertSame('tls', $payload['outbound']['stream_settings']['security']);
        $uri = \App\Protocols\General::buildShadowsocks($user->uuid,
            ['name'=>'ss','host'=>$node->host,'port'=>443,'protocol_settings'=>$s]);
        $this->assertStringContainsString('v2ray-plugin', rawurldecode($uri));
    }
    public function test_switching_managed_ws_back_to_tcp_removes_generated_plugin(): void
    {
        $node = new Server(['type'=>'shadowsocks','protocol_settings'=>[
            'cipher'=>'aes-128-gcm','network'=>'ws','tls'=>1,
            'tls_settings'=>['server_name'=>'cdn.example'],
            'network_settings'=>['path'=>'/ss','headers'=>['Host'=>'cdn.example']]]]);
        $settings = $node->protocol_settings;
        $this->assertTrue($settings['onenew_transport_managed']);
        $settings['network'] = 'tcp';
        $settings['tls'] = 0;
        $node->protocol_settings = $settings;
        $this->assertEmpty($node->protocol_settings['plugin']);
        $this->assertEmpty($node->protocol_settings['plugin_opts']);
    }

    public function test_plain_ss_keeps_native_cipher_and_has_no_implicit_plugin(): void
    {
        $node = new Server(['type'=>'shadowsocks','protocol_settings'=>['cipher'=>'aes-128-gcm']]);
        $this->assertSame(0, (int)($node->protocol_settings['tls'] ?? 0));
        $this->assertEmpty($node->protocol_settings['plugin']);
    }
}
