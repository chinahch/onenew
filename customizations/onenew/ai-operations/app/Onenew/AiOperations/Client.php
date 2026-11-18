<?php
namespace App\Onenew\AiOperations;

use Illuminate\Support\Facades\Http;

class Client
{
    public function __construct(private Endpoint $endpoint){}
    public function complete(array $config,string $key,array $messages):array
    {
        $result=$this->transport($config,$key,$messages,1500,35);
        $result['answer']=mb_substr(Redactor::text($result['answer'],[$key]),0,12000);return $result;
    }
    /** Internal JSON parser input only. Never return this unredacted content directly to the browser. */
    public function completeRaw(array $config,string $key,array $messages):array
    {
        return $this->transport($config,$key,$messages,6000,120);
    }
    private function transport(array $config,string $key,array $messages,int $limit,int $timeout):array
    {
        // Fail closed when socket pinning is unavailable; validation alone cannot stop DNS rebinding.
        if(!extension_loaded('curl'))throw new \RuntimeException('transport_unavailable');
        $url=Endpoint::normalize($config['endpoint']);$host=parse_url($url,PHP_URL_HOST);$ips=$this->endpoint->addresses($host);
        $ip=$ips[0];$port=parse_url($url,PHP_URL_PORT)??443;$pin=$host.':'.$port.':'.(str_contains($ip,':')?'['.$ip.']':$ip);
        $tokenParameter=$config['token_parameter']??'max_tokens';
        if(!in_array($tokenParameter,['max_tokens','max_completion_tokens'],true))throw new \RuntimeException('invalid_token_parameter');
        $started=hrtime(true);$timings=[];$connectionEstablished=null;
        try{
        $response=Http::withToken($key)->acceptJson()->asJson()->connectTimeout(5)->timeout($timeout)
            ->withOptions(['allow_redirects'=>false,'verify'=>true,'proxy'=>'','curl'=>[CURLOPT_RESOLVE=>[$pin],CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS],
                'on_headers'=>function($response){$length=$response->getHeaderLine('Content-Length');if($length!==''&&((int)$length>131072))throw new \RuntimeException('response_too_large');},
                'progress'=>function($total,$downloaded){if($downloaded>131072)throw new \RuntimeException('response_too_large');},
                'on_stats'=>function(\GuzzleHttp\TransferStats $stats)use(&$timings,&$connectionEstablished){
                    $values=$stats->getHandlerStats();$safe=[];foreach(['connect_time'=>'connect_ms','starttransfer_time'=>'starttransfer_ms','total_time'=>'total_ms'] as $source=>$target)if(isset($values[$source])&&(is_int($values[$source])||is_float($values[$source])))$safe[$target]=$values[$source]*1000;
                    if(!isset($safe['total_ms']))$safe['total_ms']=$stats->getTransferTime()*1000;$timings=ProviderFailure::timings($safe);
                    $connected=$values['appconnect_time']??$values['connect_time']??null;
                    if((is_int($connected)||is_float($connected))&&is_finite((float)$connected)&&$connected>=0)$connectionEstablished=$connected>0;
                }])
            ->post($url,['model'=>$config['model'],'messages'=>$messages,$tokenParameter=>$limit,'stream'=>false]);
        }catch(\Throwable $exception){
            // Handler errno is structured transport metadata; never inspect a provider body or exception text.
            $code='provider_transport';$cause=$exception;
            for($depth=0;$cause&&$depth<8;$depth++,$cause=$cause->getPrevious()){
                if($cause instanceof \GuzzleHttp\Exception\ConnectException && (int)($cause->getHandlerContext()['errno']??0)===28)$code='provider_timeout';
                if(get_class($cause)===\RuntimeException::class && $cause->getMessage()==='response_too_large')$code='invalid_response';
            }
            if($code==='provider_timeout'&&$connectionEstablished!==null)$code=$connectionEstablished?'provider_response_timeout':'provider_connect_timeout';
            if(!isset($timings['total_ms']))$timings['total_ms']=(int)round((hrtime(true)-$started)/1000000);
            throw new ProviderFailure($code,$timings);
        }
        if(!$response->successful()){
            $status=$response->status();
            $code=match(true){in_array($status,[401,403],true)=>'provider_auth',$status===429=>'provider_rate_limit',in_array($status,[400,404,405,422],true)=>'provider_request',in_array($status,[408,504],true)=>'provider_timeout',default=>'provider_http'};
            throw new ProviderFailure($code,$timings);
        }
        if(strlen($response->body())>131072)throw new \RuntimeException('invalid_response');
        $body=$response->json();$answer=$body['choices'][0]['message']['content']??null;
        if(!is_string($answer)||trim($answer)==='')throw new \RuntimeException('invalid_response');
        $usage=[];foreach(['prompt_tokens','completion_tokens','total_tokens'] as $field)if(isset($body['usage'][$field])&&is_int($body['usage'][$field])&&$body['usage'][$field]>=0)$usage[$field]=min($body['usage'][$field],100000000);
        if(!isset($timings['total_ms']))$timings['total_ms']=(int)round((hrtime(true)-$started)/1000000);
        return ['answer'=>$answer,'model'=>$config['model'],'usage'=>$usage,'timings'=>ProviderFailure::timings($timings)];
    }
}
