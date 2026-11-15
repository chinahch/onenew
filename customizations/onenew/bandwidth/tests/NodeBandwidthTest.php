<?php
namespace Tests\Feature\Onenew;
use App\Models\Server;
use App\Onenew\NodeBandwidth;
use App\Utils\CacheKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;
final class NodeBandwidthTest extends TestCase
{
    use RefreshDatabase;
    public function test_limit_requires_current_native_capability_and_persists_as_integer(): void {
        $n=Server::create(['name'=>'limited','type'=>'vless','host'=>'example.test','port'=>443,'server_port'=>443,'rate'=>1,'protocol_settings'=>['tls'=>0,'network'=>'tcp']]);
        self::assertSame(0,$n->fresh()->node_speed_limit_mbps);
        $r=Request::create('/','POST',['id'=>$n->id]);
        $rules=['value'=>['required','integer','min:0','max:100000',NodeBandwidth::capabilityRule($r)]];
        self::assertFalse(Validator::make(['value'=>20],$rules)->passes());self::assertTrue(Validator::make(['value'=>0],$rules)->passes());
        Cache::put(CacheKey::get('SERVER_VLESS_METRICS',$n->id),['onenew_node_bandwidth_v1'=>true,'kernel_type'=>'xray'],300);
        Cache::put(CacheKey::get('SERVER_VLESS_LAST_CHECK_AT',$n->id),time(),300);
        self::assertTrue(Validator::make(['value'=>20],$rules)->passes());
        self::assertFalse(Validator::make(['value'=>-1],$rules)->passes());self::assertFalse(Validator::make(['value'=>1.5],$rules)->passes());self::assertFalse(Validator::make(['value'=>100001],$rules)->passes());
        $n->update(['node_speed_limit_mbps'=>20]);self::assertSame(20,$n->fresh()->node_speed_limit_mbps);
        Cache::put(CacheKey::get('SERVER_VLESS_LAST_CHECK_AT',$n->id),time()-121,300);self::assertFalse(Validator::make(['value'=>20],$rules)->passes());
    }
}
