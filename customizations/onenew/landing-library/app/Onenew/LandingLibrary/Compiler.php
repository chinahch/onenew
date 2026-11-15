<?php
namespace App\Onenew\LandingLibrary;

/** Produces the existing native node schema; never guesses a runtime from input. */
final class Compiler
{
    public static function bindings(array $bindings): array
    {
        abort_unless(array_is_list($bindings) && count($bindings)<=50,422,'落地绑定最多 50 项');
        $ids=[];$domains=[];$globals=0;
        foreach($bindings as $index=>$binding){
            abort_unless(is_array($binding) && !array_diff(array_keys($binding),['landing_id','global','domains']) &&
                is_int($binding['landing_id']??null) && $binding['landing_id']>0 && is_bool($binding['global']??null) &&
                is_array($binding['domains']??null) && array_is_list($binding['domains']) && count($binding['domains'])<=500,422,'落地绑定格式无效');
            abort_if(isset($ids[$binding['landing_id']]),422,'不能重复选择同一落地');$ids[$binding['landing_id']]=true;
            $globals+=(int)$binding['global'];abort_if($globals>1 || ($binding['global'] && $index!==count($bindings)-1),422,'全局落地只能设置一个并放在最后');
            abort_if($binding['global'] && $binding['domains'],422,'全局落地不能同时设置域名');
            abort_if(!$binding['global'] && !$binding['domains'],422,'请选择全局或填写域名');
            foreach($binding['domains'] as $domain){
                abort_unless(is_string($domain) && strlen($domain)<=253 && $domain===strtolower($domain) &&
                    preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',$domain),422,'请填写小写域名后缀，不含协议、端口或通配符');
                foreach($domains as $previous=>$owner){
                    abort_if($owner!==$binding['landing_id'] && ($domain===$previous || str_ends_with($domain,'.'.$previous) || str_ends_with($previous,'.'.$domain)),422,'不同落地的域名规则重叠');
                }
                $domains[$domain]=$binding['landing_id'];
            }
        }
        return $bindings;
    }
    public static function compile(array $bindings,string $core,callable $resolve): array
    {
        self::bindings($bindings);$outbounds=[];$rules=[];$global=[];
        abort_unless(in_array($core,['singbox','xray','xray-caddy'],true),422,'节点内核未知');
        foreach($bindings as $binding){
            $record=$resolve($binding['landing_id']);
            abort_unless($record && $record['enabled'],422,'落地不存在或已停用：'.$binding['landing_id']);
            $out=$record['variants'][$core]??null;
            abort_unless(is_array($out),422,'落地 '.$binding['landing_id'].' 缺少 '.$core.' 内核配置');
            $out=\App\Onenew\ExitProbePayload::build($core,$out)['outbound'];$tag='landing_'.$binding['landing_id'];$out['tag']=$tag;$outbounds[]=$out;
            if($binding['domains'])$rules[]=$core==='singbox'?['outbound'=>$tag,'domain_suffix'=>$binding['domains']]:['type'=>'field','outboundTag'=>$tag,'domain'=>array_map(fn($d)=>'domain:'.$d,$binding['domains'])];
            if($binding['global'])$global[]=$core==='singbox'?['outbound'=>$tag]:['type'=>'field','outboundTag'=>$tag,'network'=>'tcp,udp'];
        }
        return ['custom_outbounds'=>$outbounds,'custom_routes'=>array_merge($rules,$global)];
    }
}
