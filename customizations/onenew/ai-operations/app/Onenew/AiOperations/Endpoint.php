<?php
namespace App\Onenew\AiOperations;

use Illuminate\Validation\ValidationException;

class Endpoint
{
    public static function normalize(string $url):string
    {
        $p=parse_url($url);
        if(!$p||($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||isset($p['port'])&&($p['port']<1||$p['port']>65535))
            throw ValidationException::withMessages(['endpoint'=>'请输入不含认证信息、参数的 HTTPS API 地址。']);
        $host=strtolower($p['host']);
        if(!preg_match('/^(?=.{1,253}$)[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/D',$host)||!str_contains($host,'.')||filter_var($host,FILTER_VALIDATE_IP)||preg_match('/(?:^|\.)(?:localhost|local|internal|test|invalid)$/D',$host))
            throw ValidationException::withMessages(['endpoint'=>'API 地址必须是公网域名。']);
        $path=rtrim($p['path']??'','/');
        if(!preg_match('~^/[A-Za-z0-9/_-]*$~D',$path)&&$path!=='')throw ValidationException::withMessages(['endpoint'=>'API 路径无效。']);
        if(!str_ends_with($path,'/chat/completions'))$path.='/chat/completions';
        return 'https://'.$host.(isset($p['port'])?':'.$p['port']:'').$path;
    }
    public static function publicIp(string $ip):bool
    {
        if(str_contains($ip,':')&&(str_starts_with(strtolower($ip),'::ffff:')||!preg_match('/^[23][0-9a-f]{0,3}:/i',$ip)))return false;
        if(filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4)){
            $n=unpack('N',inet_pton($ip))[1];
            foreach([['100.64.0.0',10],['192.0.0.0',24],['192.88.99.0',24],['198.18.0.0',15]] as [$base,$bits]){
                $mask=(0xffffffff<<(32-$bits))&0xffffffff;if(($n&$mask)===(unpack('N',inet_pton($base))[1]&$mask))return false;
            }
        }
        if(str_contains($ip,':')&&preg_match('/^(?:2001:(?:0{0,3}[0-9a-f]{1,2}|0?1[0-9a-f]{2}|db8)|2002|3fff):/i',$ip))return false;
        return filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)!==false;
    }
    public function addresses(string $host):array
    {
        $records=@dns_get_record($host,DNS_A|DNS_AAAA);$ips=[];
        foreach($records?:[] as $record){$ip=$record['ip']??$record['ipv6']??null;if($ip!==null){if(!self::publicIp($ip))throw new \RuntimeException('unsafe_endpoint');$ips[]=$ip;}}
        if(!$ips)throw new \RuntimeException('unresolved_endpoint');return array_values(array_unique($ips));
    }
}
