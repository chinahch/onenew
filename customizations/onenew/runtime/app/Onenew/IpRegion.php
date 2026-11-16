<?php
namespace App\Onenew;

/** Local-only administrator lookup; never called on the subscription pull path. */
final class IpRegion
{
    public const VERSION='ip2region-200f4cc7-zh1';
    public function __construct(private ?string $databaseRoot=null){}
    private static function assets():string{return is_dir('/opt/onenew/geoip')?'/opt/onenew/geoip':dirname(__DIR__,3).'/geoip';}
    public static function label(string $region):string
    {
        $parts=explode('|',$region);$countries=json_decode(file_get_contents(self::assets().'/countries.zh-CN.json'),true);
        $code=strtoupper($parts[4]??'');$country=$countries[$code]??'';
        if(!$country&&preg_match('/[\x{4e00}-\x{9fff}]/u',$parts[0]??''))$country=$parts[0];
        $result=$country?[$country]:[];
        foreach(array_slice($parts,1,2) as $part)if(preg_match('/[\x{4e00}-\x{9fff}]/u',$part)&&!in_array($part,$result,true))$result[]=$part;
        return $result?implode(' · ',$result):'未知';
    }
    public function lookup(string $ip):string
    {
        $packed=@inet_pton($ip);if($packed===false)return '无效 IP';
        if(strlen($packed)===16&&substr($packed,0,12)===str_repeat("\0",10)."\xff\xff"){$packed=substr($packed,12);$ip=inet_ntop($packed);}
        if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE))return '内网 IP';
        $root=$this->databaseRoot??self::assets();$v=strlen($packed)===4?4:6;$db=$root.'/ip2region_v'.$v.'.xdb';
        if(!is_file($db)||is_link($db))return '未知';
        require_once self::assets().'/vendor/Searcher.class.php';$searcher=null;
        try{
            $version=$v===4?\ip2region\xdb\IPv4::default():\ip2region\xdb\IPv6::default();
            $searcher=\ip2region\xdb\Searcher::newWithFileOnly($version,$db);
            return self::label($searcher->search(inet_ntop($packed)));
        }catch(\Throwable){return '未知';}finally{if($searcher)$searcher->close();}
    }
    public function cached(string $ip):string
    {
        $key='onenew:ip-region:'.self::VERSION.':'.hash('sha256',@inet_pton($ip)?:$ip);
        try{return \Illuminate\Support\Facades\Cache::remember($key,86400,fn()=>$this->lookup($ip));}
        catch(\Throwable){return $this->lookup($ip);}
    }
}
