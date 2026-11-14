<?php
// Dependency-free schema checks; authentication/Redis/leases use ExitProbeTest.php.
function abort_if($test, $status, $message = '') { if ($test) throw new RuntimeException($message ?: (string)$status); }
function abort_unless($test, $status, $message = '') { abort_if(!$test, $status, $message); }
require __DIR__ . '/../app/Onenew/ExitProbePayload.php';
require __DIR__ . '/../app/Onenew/ExitProbeService.php';
use App\Onenew\ExitProbePayload;
function reject(callable $test): void { try {$test();} catch (RuntimeException $e) {return;} throw new RuntimeException('Accepted unsafe configuration'); }
$out=['tag'=>'landing','protocol'=>'socks','settings'=>['server'=>'landing.example','server_port'=>1080,'username'=>'user','password'=>'secret']];
$payload=ExitProbePayload::build('singbox',$out);
if ($payload['target']!=='https://www.cloudflare.com/cdn-cgi/trace'||$payload['outbound']!==$out) throw new RuntimeException('Payload altered proxy');
reject(fn()=>ExitProbePayload::build('singbox',array_replace($out,['protocol'=>'direct'])));
reject(fn()=>ExitProbePayload::build('singbox',array_replace($out,['proxy_tag'=>'direct'])));
reject(fn()=>ExitProbePayload::build('singbox',array_replace($out,['settings'=>$out['settings']+['detour'=>'direct']])));
$native=['tag'=>'landing','protocol'=>'vless','settings'=>['vnext'=>[['address'=>'edge.example','port'=>443,'users'=>[['id'=>'uuid','encryption'=>'none']]]]],
    'stream_settings'=>['network'=>'xhttp','security'=>'tls','tlsSettings'=>['serverName'=>'edge.example'],
    'xhttpSettings'=>['host'=>'edge.example','path'=>'/','mode'=>'packet-up','extra'=>['noGRPCHeader'=>false,'noSSEHeader'=>true,'xPaddingBytes'=>'100-1000','headers'=>['X-Test'=>'safe']]]]];
ExitProbePayload::build('xray-caddy',$native);
reject(fn()=>ExitProbePayload::build('xray',$native));
$native['stream_settings']['xhttpSettings']['extra']['downloadSettings']=['address'=>'elsewhere.example'];
reject(fn()=>ExitProbePayload::build('xray-caddy',$native));
unset($native['stream_settings']['xhttpSettings']['extra']['downloadSettings']);
$native['stream_settings']['xhttpSettings']['extra']['headers']['X-Test']="bad\r\nInjected: x";
reject(fn()=>ExitProbePayload::build('xray-caddy',$native));
echo "Exit probe payload schema checks passed\n";
$maps=['tag'=>'landing','protocol'=>'vless','settings'=>['vnext'=>[['address'=>'edge.example','port'=>443,'users'=>[['id'=>'uuid','encryption'=>'none']]]]],
    'stream_settings'=>['network'=>'ws','security'=>'tls','tlsSettings'=>['serverName'=>'edge.example','alpn'=>[]], 'wsSettings'=>['path'=>'/','headers'=>[]]]];
$wire=json_decode(json_encode(ExitProbePayload::build('xray-caddy',$maps)));
if (!($wire->outbound->stream_settings->wsSettings->headers instanceof stdClass)) throw new RuntimeException('WS headers must encode as {}');
if ($wire->outbound->stream_settings->tlsSettings->alpn!==[]) throw new RuntimeException('ALPN list must remain []');
$maps['stream_settings']=['network'=>'xhttp','security'=>'none','xhttpSettings'=>['extra'=>['headers'=>[], 'xmux'=>[]]]];
$wire=json_decode(json_encode(ExitProbePayload::build('xray-caddy',$maps)));
if (!($wire->outbound->stream_settings->xhttpSettings->extra->headers instanceof stdClass)) throw new RuntimeException('XHTTP headers must encode as {}');
if (!($wire->outbound->stream_settings->xhttpSettings->extra->xmux instanceof stdClass)) throw new RuntimeException('XHTTP xmux must encode as {}');
$maps['stream_settings']['xhttpSettings']['extra']=[];
$wire=json_decode(json_encode(ExitProbePayload::build('xray-caddy',$maps)));
if (!($wire->outbound->stream_settings->xhttpSettings->extra instanceof stdClass)) throw new RuntimeException('XHTTP extra must encode as {}');
echo "Exit probe object/list wire checks passed\n";
