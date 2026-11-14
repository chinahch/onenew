<?php
namespace App\Onenew;

use App\Models\Server;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class NodeAddresses extends Controller
{
    public function preview(Request $request): mixed
    {
        $p=$request->validate(['ip'=>'required|ip']);
        $packed=inet_pton($p['ip']);
        $nodes=Server::orderBy('id')->get(['id','name','type','host','port','onenew_identity'])
            ->filter(fn($node)=>@inet_pton($node->host)===$packed)->values()->map(fn($node)=>[
                'id'=>$node->id,'name'=>$node->name,'type'=>$node->type,'host'=>$node->host,
                'port'=>(string)$node->port,'identity'=>$node->onenew_identity]);
        return response()->json(['nodes'=>$nodes])->header('Cache-Control','no-store');
    }

    public function apply(Request $request): mixed
    {
        $p=$request->validate(['source_ip'=>'required|ip','target_ip'=>'nullable|ip',
            'target_port'=>'nullable|integer|min:1|max:65535','nodes'=>'required|array|min:1|max:1000',
            'nodes.*.id'=>'required|integer|min:1|distinct','nodes.*.identity'=>'required|uuid',
            'nodes.*.port'=>'required|string|max:255']);
        abort_unless(!empty($p['target_ip'])||isset($p['target_port']),422,'请填写新 IP 或连接端口');
        $count=DB::transaction(function()use($p){
            ResourceIds::lock('v2_server');
            $nodes=Server::whereIn('id',array_column($p['nodes'],'id'))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($nodes->count()===count($p['nodes']),409,'节点列表已变更，请重新搜索');
            foreach($p['nodes'] as $snapshot){
                $node=$nodes[$snapshot['id']];
                abort_unless($node->onenew_identity===$snapshot['identity'] &&
                    @inet_pton($node->host)===inet_pton($p['source_ip']) && (string)$node->port===$snapshot['port'],409,'节点地址或身份已变更，请重新搜索');
            }
            foreach($nodes as $node){
                $changes=[];
                if(!empty($p['target_ip']))$changes['host']=$p['target_ip'];
                if(isset($p['target_port']))$changes['port']=(string)$p['target_port'];
                $node->update($changes);
            }
            return $nodes->count();
        },3);
        return response()->json(['updated'=>$count])->header('Cache-Control','no-store');
    }
}
