<?php
namespace App\Onenew\LandingLibrary;
use App\Models\Server;
use App\Onenew\External\ShareLink;
use App\Onenew\ExitProbePayload;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
final class Library
{
    public const TABLE='onenew_landings';
    /** A single lock order for library edits, deletes, and native node saves. */
    public static function lock(): void { DB::table('onenew_landing_lock')->where('id',1)->update(['revision'=>DB::raw('revision + 1')]); }
    public static function payload(object $row): array { return json_decode(Crypt::decryptString($row->link_ciphertext),true,32,JSON_THROW_ON_ERROR); }
    public static function references(int $id,bool $lock=false) {
        $query=Server::whereNotNull('onenew_landing_bindings')->orderBy('id');
        if($lock)$query->lockForUpdate();
        return $query->get()->filter(fn($node)=>collect($node->onenew_landing_bindings??[])->contains(fn($binding)=>(int)($binding['landing_id']??0)===$id));
    }
    public static function summary(object $row): array {
        $link=ShareLink::parse(self::payload($row)['link']);
        return ['id'=>(int)$row->id,'name'=>$row->name,'protocol'=>$link['type'],'host'=>$link['host'],'port'=>(int)$link['port'],
            'enabled'=>(bool)$row->enabled,'sort'=>(int)$row->sort,'version'=>(int)$row->version,'reference_count'=>self::references((int)$row->id)->count()];
    }
    public static function validatePayload(string $link,array $variants): array {
        try{$parsed=ShareLink::parse($link);}catch(\InvalidArgumentException $e){abort(422,$e->getMessage());}
        abort_unless($variants && count($variants)<=3 && !array_diff(array_keys($variants),['singbox','xray','xray-caddy']),422,'至少需要一种受支持的内核配置');
        $protocol=$parsed['type']==='hysteria'?'hysteria2':$parsed['type'];
        foreach($variants as $core=>$out){
            abort_unless(is_array($out),422,'内核配置格式无效');ExitProbePayload::build($core,$out);
            $endpoint=$core==='singbox'?['address'=>$out['settings']['server'],'port'=>$out['settings']['server_port']]:$out['settings'][in_array($out['protocol'],['vmess','vless'],true)?'vnext':'servers'][0];
            abort_unless($out['protocol']===$protocol && strtolower(trim($endpoint['address'],'[]'))===strtolower(trim($parsed['host'],'[]')) && (int)$endpoint['port']===(int)$parsed['port'],422,'内核协议、地址或端口与原链接不一致');
        }
        return ['link'=>$link,'variants'=>$variants];
    }
    public static function core(Server $node): string {
        $m=$node->metrics??[];$time=$m['updated_at']??null;
        abort_unless(is_numeric($time) && (int)$time>=now()->timestamp-600 && (int)$time<=now()->timestamp+60,422,'节点 '.$node->id.' 缺少最新内核上报');
        $core=match($m['kernel_type']??null){'singbox'=>'singbox','xray'=>($m['onenew_runtime']??false)===true?'xray-caddy':'xray',default=>null};
        abort_unless($core,422,'节点 '.$node->id.' 内核未知');
        abort_if($core==='singbox' && in_array(data_get($node->protocol_settings,'network'),['xhttp','splithttp'],true),422,'传输方式与实际上报内核不一致，请等待运行内核更新');
        abort_if($node->exists && $node->isDirty('machine_id'),422,'请先保存归属服务器变更，等待新服务器上报内核后再设置落地');
        return $core;
    }
    public static function compile(Server $node,?array $override=null): array {
        $bindings=$node->onenew_landing_bindings;Compiler::bindings($bindings);
        if(!$bindings)return ['custom_outbounds'=>[],'custom_routes'=>[]];
        try{return Compiler::compile($bindings,self::core($node),function($id)use($override){
            if($override && $id===$override['id'])return $override;
            $row=DB::table(self::TABLE)->find($id);return $row?self::payload($row)+['enabled'=>(bool)$row->enabled]:null;
        });}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){abort($e->getStatusCode(),'节点 '.$node->id.'：'.$e->getMessage());}
    }
}
