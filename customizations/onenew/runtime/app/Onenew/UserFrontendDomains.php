<?php
declare(strict_types=1);
namespace App\Onenew;

final class UserFrontendDomains
{
    public static function normalize(array $origins): array
    {
        if(count($origins)>20) throw new \InvalidArgumentException('用户端域名最多20个。');
        $result=[];
        foreach($origins as $value) {
            if(!is_string($value)) throw new \InvalidArgumentException('用户端域名必须为网址。');
            $value=trim($value);
            $url=parse_url($value);
            if(strlen($value)>255 || !filter_var($value,FILTER_VALIDATE_URL) || !$url
                || !in_array(strtolower($url['scheme']??''),['http','https'],true)
                || preg_match('/\s/',$value) || isset($url['user']) || isset($url['pass'])
                || isset($url['query']) || isset($url['fragment']) || !in_array($url['path']??'',['','/'],true)
                || str_contains($url['host']??'','*')) {
                throw new \InvalidArgumentException('请填写完整 HTTP(S) 域名，不带路径、账号、参数或片段。');
            }
            $scheme=strtolower($url['scheme']); $host=strtolower($url['host']); $port=$url['port']??null;
            $origin=$scheme.'://'.$host.(($port!==null && !(($scheme==='https'&&$port===443)||($scheme==='http'&&$port===80)))?':'.$port:'');
            if(!in_array($origin,$result,true)) $result[]=$origin;
        }
        return $result;
    }
}
