<?php
namespace Tests\Feature\Onenew;

use App\Models\{Server,User};
use App\Onenew\{ProbeOutboundBuilder,ProbeStages};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HysteriaLocalProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_hysteria2_uses_local_quic_listener_preserving_account_obfs_and_certificate_name(): void
    {
        $user=new User(['uuid'=>'00000000-0000-4000-8000-000000000001']);
        foreach([null,'certificate.example'] as $serverName){
            $settings=['version'=>2,'obfs'=>['open'=>true,'type'=>'salamander','password'=>'fixture-obfs']];
            if($serverName!==null)$settings['tls']=['server_name'=>$serverName];
            $node=Server::create(['name'=>'Hysteria2','type'=>'hysteria','host'=>'hkbn-1.ddns-go.de','port'=>'20201','server_port'=>20201,'rate'=>1,'enabled'=>true,'protocol_settings'=>$settings])->fresh();
            $before=$node->getRawOriginal();$payload=app(ProbeOutboundBuilder::class)->build($node,$user);$out=$payload['outbound'];
            $this->assertSame('singbox',$payload['kernel_type']);$this->assertSame('hysteria2',$out['protocol']);
            $this->assertSame('127.0.0.1',$out['settings']['server']);$this->assertSame(20201,$out['settings']['server_port']);
            $this->assertSame($user->uuid,$out['settings']['password']);
            $this->assertSame(['type'=>'salamander','password'=>'fixture-obfs'],$out['settings']['obfs']);
            $this->assertTrue($out['settings']['tls']['enabled']);$this->assertFalse($out['settings']['tls']['insecure']);
            $this->assertSame($serverName??'hkbn-1.ddns-go.de',$out['settings']['tls']['server_name']);
            $this->assertSame(ProbeOutboundBuilder::TARGET,$payload['target']);
            $this->assertSame('local_listener_roundtrip',ProbeStages::payload($node,'singbox',$payload)['entry_source']);
            $this->assertSame($before,$node->fresh()->getRawOriginal());$this->assertSame('hkbn-1.ddns-go.de',$node->host);$this->assertSame(20201,(int)$node->port);
            foreach([null,0,-1,65536] as $invalid){
                $node->server_port=$invalid;
                try { app(ProbeOutboundBuilder::class)->build($node,$user); $this->fail('Invalid local port accepted'); }
                catch (\App\Onenew\ProbeUnavailable $e) { $this->assertSame('configuration_unavailable',$e->getMessage()); }
            }
        }
    }
}
