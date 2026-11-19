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
        $this->assertStringContainsString('mux=0', $s['plugin_opts']);
        $this->assertStringContainsString('host=cdn.example', $s['plugin_opts']);
        $this->assertStringContainsString('path=/ss', $s['plugin_opts']);
        $config = ServerService::buildNodeConfig($node);
        $this->assertSame('xray', $config['kernel_type']);
        $this->assertSame(1, $config['tls']);
        $this->assertSame('cdn.example', $config['tls_settings']['server_name']);
        $user = new User(['uuid'=>'00000000-0000-4000-8000-000000000001']);
        $payload = app(ProbeOutboundBuilder::class)->build($node, $user);
        $this->assertSame($node->host, $payload['outbound']['settings']['servers'][0]['address']);
        $this->assertSame('xray', $payload['kernel_type']);
        $this->assertSame('tls', $payload['outbound']['stream_settings']['security']);
        $uri = \App\Protocols\General::buildShadowsocks($user->uuid,
            ['name'=>'ss','host'=>$node->host,'port'=>443,'protocol_settings'=>$s]);
        $this->assertStringContainsString('v2ray-plugin', rawurldecode($uri));
        $server = ['name'=>'ss','host'=>$node->host,'port'=>443,'protocol_settings'=>$s];
        foreach ([\App\Protocols\Clash::class, \App\Protocols\ClashMeta::class] as $format) {
            $out = $format::buildShadowsocks($user->uuid, $server);
            $this->assertTrue($out['plugin-opts']['tls'], $format);
            $this->assertSame('/ss', $out['plugin-opts']['path'], $format);
            $this->assertFalse($out['udp'], $format);
            $this->assertFalse($out['plugin-opts']['mux'], $format);
        }
        $singbox = new \ReflectionMethod(\App\Protocols\SingBox::class, 'buildShadowsocks');
        $out = $singbox->invoke(app(ProbeOutboundBuilder::class), $user->uuid, $server);
        $this->assertSame('v2ray-plugin', $out['plugin']);
        $this->assertSame($s['plugin_opts'], $out['plugin_opts']);

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
    public function test_plain_ss_probes_local_listener_with_native_account_and_local_source(): void
    {
        $user=new User(['uuid'=>'00000000-0000-4000-8000-000000000001']);
        foreach(['2022-blake3-aes-256-gcm'=>32,'2022-blake3-aes-128-gcm'=>16,'aes-128-gcm'=>null] as $cipher=>$size){
            $node=Server::create(['name'=>'plain SS','type'=>'shadowsocks','host'=>'8.138.255.100','port'=>'30001','server_port'=>40558,'rate'=>1,'enabled'=>true,'protocol_settings'=>['cipher'=>$cipher]])->fresh();
            $before=$node->getRawOriginal();$payload=app(ProbeOutboundBuilder::class)->build($node,$user);$out=$payload['outbound']['settings'];
            $this->assertSame('127.0.0.1',$out['server']);$this->assertSame(40558,$out['server_port']);
            $this->assertSame($node->generateServerPassword($user),$out['password']);
            if($size!==null)foreach(explode(':',$out['password']) as $key)$this->assertSame($size,strlen(base64_decode($key)));
            $this->assertSame(ProbeOutboundBuilder::TARGET,$payload['target']);
            $this->assertSame('local_listener_roundtrip',\App\Onenew\ProbeStages::payload($node,'singbox',$payload)['entry_source']);
            $this->assertSame($before,$node->fresh()->getRawOriginal());$this->assertSame('8.138.255.100',$node->host);$this->assertSame(30001,(int)$node->port);
            foreach([null,0,-1,65536] as $invalid){
                $node->server_port=$invalid;
                try { app(ProbeOutboundBuilder::class)->build($node,$user); $this->fail('Invalid local port accepted'); }
                catch (\App\Onenew\ProbeUnavailable $e) { $this->assertSame('configuration_unavailable',$e->getMessage()); }
            }
        }
    }

}
