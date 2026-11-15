<?php
namespace App\Onenew;
use App\Models\Server;
use Illuminate\Http\Request;
final class NodeBandwidth
{
    public static function capabilityRule(Request $request): \Closure
    {
        return function($attribute,$value,$fail)use($request){
            if((int)$value<=0)return;
            $id=(int)$request->input('id');$machine=(int)$request->input('machine_id');
            $node=$id?Server::find($id):null;
            $nodes=$machine?Server::where('machine_id',$machine)->get():collect($node?[$node]:[]);
            foreach($nodes as $n){$metrics=$n->metrics;if(($metrics['onenew_node_bandwidth_v1']??false)===true && in_array($metrics['kernel_type']??null,['xray','singbox'],true) && (int)$n->last_check_at>=time()-120)return;}
            $fail('请先将归属服务器的 caddy 升级至支持节点总限速的版本，并等待上报');
        };
    }
}
