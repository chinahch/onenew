<?php
namespace App\Onenew\AiOperations;

use Illuminate\Support\Facades\Http;

class Client
{
    public function __construct(private Endpoint $endpoint){}
    public function complete(array $config,string $key,array $messages):array
    {
        // Fail closed when socket pinning is unavailable; validation alone cannot stop DNS rebinding.
        if(!extension_loaded('curl'))throw new \RuntimeException('transport_unavailable');
        $url=Endpoint::normalize($config['endpoint']);$host=parse_url($url,PHP_URL_HOST);$ips=$this->endpoint->addresses($host);
        $ip=$ips[0];$pin=$host.':443:'.(str_contains($ip,':')?'['.$ip.']':$ip);
        $response=Http::withToken($key)->acceptJson()->asJson()->connectTimeout(5)->timeout(35)
            ->withOptions(['allow_redirects'=>false,'verify'=>true,'proxy'=>'','curl'=>[CURLOPT_RESOLVE=>[$pin],CURLOPT_PROXY=>'',CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS],
                'on_headers'=>function($response){$length=$response->getHeaderLine('Content-Length');if($length!==''&&((int)$length>131072))throw new \RuntimeException('response_too_large');},
                'progress'=>function($total,$downloaded){if($downloaded>131072)throw new \RuntimeException('response_too_large');}])
            ->post($url,['model'=>$config['model'],'messages'=>$messages,'max_tokens'=>1500,'stream'=>false]);
        if(!$response->successful()||strlen($response->body())>131072)throw new \RuntimeException('provider_failed');
        $body=$response->json();$answer=$body['choices'][0]['message']['content']??null;
        if(!is_string($answer)||trim($answer)==='')throw new \RuntimeException('invalid_response');
        $usage=[];foreach(['prompt_tokens','completion_tokens','total_tokens'] as $field)if(isset($body['usage'][$field])&&is_int($body['usage'][$field])&&$body['usage'][$field]>=0)$usage[$field]=min($body['usage'][$field],100000000);
        return ['answer'=>mb_substr(Redactor::text($answer,[$key]),0,12000),'model'=>$config['model'],'usage'=>$usage];
    }
}
