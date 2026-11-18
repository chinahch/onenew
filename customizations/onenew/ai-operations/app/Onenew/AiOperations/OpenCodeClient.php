<?php
namespace App\Onenew\AiOperations;

use Illuminate\Support\Facades\Http;

/** OpenCode 2.3.2 and legacy 1.19.6 HTTP APIs. No redirects, proxy inheritance or retries. */
class OpenCodeClient
{
    public function __construct(private Endpoint $endpoint){}
    public static function normalize(string $url):string
    {
        // Reuse the exact same public HTTPS host/path policy without the chat suffix.
        return substr(Endpoint::normalize($url),0,-strlen('/chat/completions'));
    }
    public function health(array $config,string $password):array
    {
        if(($config['opencode_protocol']??'v2')==='v2'){
            $health=$this->request($config,$password,'GET','/api/info');
            if(!is_array($health)||!is_string($health['version']??null)||!preg_match('/^2\.[0-9]+\.[0-9]+(?:[-+][a-zA-Z0-9.-]+)?$/D',$health['version'])||!is_int($health['pid']??null)||!is_array($health['urls']??null)||!is_string($health['paths']['tmp']??null))throw new \RuntimeException('opencode_incompatible');
            return ['version'=>$health['version']];
        }
        $health=$this->request($config,$password,'GET','/global/health');
        if(!is_array($health)||($health['healthy']??null)!==true||!is_string($health['version']??null)||!preg_match('/^1\.[0-9]+\.[0-9]+(?:[-+][a-zA-Z0-9.-]+)?$/D',$health['version']))throw new \RuntimeException('opencode_incompatible');
        return ['version'=>$health['version']];
    }
    public function request(array $config,string $password,string $method,string $path,?array $body=null):mixed
    {
        if(!extension_loaded('curl'))throw new \RuntimeException('transport_unavailable');
        $base=self::normalize($config['opencode_endpoint']);$host=parse_url($base,PHP_URL_HOST);$port=parse_url($base,PHP_URL_PORT)??443;
        $ips=$this->endpoint->addresses($host);$ip=$ips[0];$pin=$host.':'.$port.':'.(str_contains($ip,':')?'['.$ip.']':$ip);
        $query=($config['opencode_protocol']??'v2')==='v1'&&$path!=='/global/health'?(str_contains($path,'?')?'&':'?').http_build_query(['directory'=>$config['opencode_directory']],'','&',PHP_QUERY_RFC3986):'';
        try{
            $request=Http::withBasicAuth($config['opencode_username']??'opencode',$password)->acceptJson()->asJson()->connectTimeout(5)->timeout(10)->withOptions([
                'allow_redirects'=>false,'verify'=>true,'proxy'=>'','curl'=>[CURLOPT_RESOLVE=>[$pin],CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS],
                'on_headers'=>function($response){$length=$response->getHeaderLine('Content-Length');if($length!==''&&(int)$length>1048576)throw new \RuntimeException('response_too_large');},
                'progress'=>function($total,$downloaded){if($downloaded>1048576)throw new \RuntimeException('response_too_large');},
            ]);
            $response=$request->send($method,$base.$path.$query,$body===null?[]:['json'=>$body]);
        }catch(\Throwable $exception){throw new \RuntimeException($method==='POST'?'opencode_uncertain':'opencode_transport');}
        if(!$response->successful())throw new \RuntimeException(in_array($response->status(),[401,403],true)?'opencode_auth':($method==='POST'?'opencode_uncertain':'opencode_http'));
        if(strlen($response->body())>1048576)throw new \RuntimeException($method==='POST'?'opencode_uncertain':'opencode_incompatible');
        if(str_ends_with($path,'/prompt_async')){if($response->status()!==204)throw new \RuntimeException('opencode_uncertain');return null;}
        if($response->status()!==200)throw new \RuntimeException($method==='POST'?'opencode_uncertain':'opencode_incompatible');
        try{return json_decode($response->body(),true,64,JSON_THROW_ON_ERROR);}catch(\JsonException $exception){throw new \RuntimeException($method==='POST'?'opencode_uncertain':'opencode_incompatible');}
    }
}
