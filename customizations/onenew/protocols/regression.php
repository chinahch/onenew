<?php
/** Standalone regression: execute pinned model, node builder and subscription builders.
 * The small Eloquent storage adapter supplies attributes only; no DB/framework is simulated.
 */
namespace Illuminate\Database\Eloquent {
    class Model implements \ArrayAccess {
        protected $attributes = [];
        public function __get($key) { return $key === 'protocol_settings' ? $this->getProtocolSettingsAttribute($this->attributes[$key] ?? null) : ($this->attributes[$key] ?? null); }
        public function __set($key, $value) { if ($key === 'protocol_settings') $this->setProtocolSettingsAttribute($value); else $this->attributes[$key] = $value; }
        public function raw($key) { return $this->attributes[$key] ?? null; }
        public function offsetExists(mixed $key): bool { return isset($this->attributes[$key]); }
        public function offsetGet(mixed $key): mixed { return $this->__get($key); }
        public function offsetSet(mixed $key, mixed $value): void { $this->__set($key, $value); }
        public function offsetUnset(mixed $key): void { unset($this->attributes[$key]); }
    }
}
namespace App\Support { abstract class AbstractProtocol {} }
namespace {
    function data_get($target, $key, $default = null) { foreach (explode('.', $key) as $segment) { if (is_array($target) && array_key_exists($segment, $target)) $target=$target[$segment]; elseif (is_object($target) && isset($target->$segment)) $target=$target->$segment; else return $default; } return $target; }
    function expect($yes, $message) { if (!$yes) throw new \RuntimeException($message); }
    $root=dirname(__DIR__,3); $work=__DIR__.'/.test-work';
    foreach (['app/Models/Server.php','app/Services/ServerService.php','app/Protocols/General.php','app/Protocols/Shadowrocket.php','app/Protocols/ClashMeta.php','app/Utils/Helper.php','app/Http/Requests/Admin/ServerSave.php'] as $file) {
        @mkdir(dirname($work.'/'.$file),0777,true); copy($root.'/'.$file,$work.'/'.$file);
    }
    if (is_file(__DIR__.'/install.php')) { require __DIR__.'/install.php'; applyOnenewProtocolPatch($work); }
    if (is_file(__DIR__.'/fragment-install.php')) { require __DIR__.'/fragment-install.php'; applyOnenewFragmentPatch($work); }
    if (is_file(__DIR__.'/clash-xhttp-alpn-install.php')) { require __DIR__.'/clash-xhttp-alpn-install.php'; applyOnenewClashXhttpAlpnPatch($work); }
    if (is_file(__DIR__.'/utls-install.php')) { require __DIR__.'/utls-install.php'; applyOnenewUtlsFingerprintPatch($work); }
    if (is_file(__DIR__.'/app/Onenew/Protocols/ProtocolSettings.php')) require __DIR__.'/app/Onenew/Protocols/ProtocolSettings.php';
    if (is_file(__DIR__.'/app/Onenew/Protocols/TlsFragment.php')) require __DIR__.'/app/Onenew/Protocols/TlsFragment.php';
    if (is_file(__DIR__.'/app/Onenew/Protocols/UtlsFingerprint.php')) require __DIR__.'/app/Onenew/Protocols/UtlsFingerprint.php';
    require $work.'/app/Models/Server.php'; require $work.'/app/Utils/Helper.php';
    require $work.'/app/Services/ServerService.php'; require $work.'/app/Protocols/General.php'; require $work.'/app/Protocols/ClashMeta.php';
    require $work.'/app/Protocols/Shadowrocket.php';
    $settings=['utls'=>['enabled'=>false],'tls'=>1,'network'=>'xhttp','network_settings'=>['host'=>'example.com','path'=>'/','mode'=>'auto','alpn'=>'h3','extra'=>['headers'=>[],'xPaddingBytes'=>'100-1000','noGRPCHeader'=>false,'noSSEHeader'=>false,'xmux'=>[],'downloadSettings'=>['tlsSettings'=>[],'sockopt'=>[],'xhttpSettings'=>['extra'=>['headers'=>[]]]],'list'=>[],'hosts'=>['a','b'],'extensions'=>['ordered'=>[]]]]];
    foreach (['vmess','vless','trojan'] as $type) {
        $node=new \App\Models\Server; $node->type=$type; $node->host='example.com'; $node->port=443; $node->server_port=443; $node->name='h3';
        $node->protocol_settings=$settings;
        $stored=json_decode($node->raw('protocol_settings'));
        expect($stored->network_settings->extra->headers instanceof \stdClass, "$type save: headers must be object {}");
        expect(is_array($stored->network_settings->extra->list), "$type save: list must stay []");
        $read=$node->protocol_settings;
        $config=json_decode(json_encode(\App\Services\ServerService::buildNodeConfig($node)));
        expect($config->networkSettings->extra->headers instanceof \stdClass, "$type node config: headers must be {}");
        expect($config->networkSettings->extra->downloadSettings->sockopt instanceof \stdClass, "$type nested sockopt must be {}");
        expect(is_array($config->networkSettings->extra->downloadSettings->xhttpSettings->extra->headers) === false, "$type nested extra headers must be {}");
        expect($config->networkSettings->alpn==='h3', "$type node config: preserve H3 ALPN");
        expect($config->networkSettings->extra->hosts===['a','b'], "$type node config: preserve host list");
        $server=['protocol_settings'=>$read,'host'=>'example.com','port'=>443,'name'=>'h3'];
        $method='build'.ucfirst($type); $link=\App\Protocols\General::$method('00000000-0000-4000-8000-000000000001',$server);
        if ($type==='vmess') $query=json_decode(base64_decode(substr(trim($link),8)),true);
        else parse_str(parse_url(trim($link),PHP_URL_QUERY),$query);
        expect(isset($query['extra']), "$type share: extra must exist");
        $extra=json_decode($query['extra']);
        expect($extra->headers instanceof \stdClass, "$type share: headers must encode as {}");
        expect(is_array($extra->list), "$type share: list must stay []");
        expect(($query['alpn']??null)==='h3', "$type share: preserve H3 ALPN");
        $clash=\App\Protocols\ClashMeta::buildVless('00000000-0000-4000-8000-000000000001',$server);
        expect(($clash['alpn']??null)==='h3', "$type XHTTP VLESS: preserve H3 ALPN in Clash share");
        $shadowrocket=\App\Protocols\Shadowrocket::$method('00000000-0000-4000-8000-000000000001',$server);
        parse_str(parse_url(trim($shadowrocket),PHP_URL_QUERY),$shadowrocketQuery);
        expect(($shadowrocketQuery['alpn']??null)==='h3', "$type Shadowrocket share: preserve H3 ALPN");
        // The raw JSON setter must also preserve unknown object/list shape, not guess by value.
        $node->protocol_settings='{"network":"xhttp","network_settings":{"extra":{"headers":{},"unknownObject":{},"unknownList":[]}}}';
        $roundtrip=json_decode($node->raw('protocol_settings'));
        expect($roundtrip->network_settings->extra->unknownObject instanceof \stdClass, 'raw JSON object preserved');
        expect(is_array($roundtrip->network_settings->extra->unknownList), 'raw JSON list preserved');
        $node->protocol_settings=['network'=>'ws','network_settings'=>['headers'=>[],'path'=>[]]];
        expect(json_decode($node->raw('protocol_settings'))->network_settings->headers===[], 'non-XHTTP headers unchanged');
    }
    $reality = ['tls'=>2,'network'=>'tcp','utls'=>['enabled'=>true,'fingerprint'=>'chrome140'],'reality_settings'=>['server_name'=>'www.microsoft.com','public_key'=>str_repeat('A',43),'short_id'=>'1234']];
    expect(\App\Utils\Helper::getTlsFingerprint($reality['utls'])==='chrome', 'chrome140 maps to chrome for generic engines');
    $srLink = \App\Protocols\Shadowrocket::buildVless('00000000-0000-4000-8000-000000000001',['protocol_settings'=>$reality,'host'=>'example.com','port'=>443,'name'=>'r140']);
    parse_str(parse_url(trim($srLink),PHP_URL_QUERY),$srQ);
    expect(($srQ['fingerprint']??null)==='Chrome140' && !isset($srQ['fp']), 'Shadowrocket emits fingerprint=Chrome140');
    $cm = \App\Protocols\ClashMeta::buildVless('00000000-0000-4000-8000-000000000001',['protocol_settings'=>$reality,'host'=>'example.com','port'=>443,'name'=>'r140']);
    expect(($cm['client-fingerprint']??null)==='chrome' && ($cm['reality-opts']['support-x25519mlkem768']??false)===true, 'ClashMeta emits support-x25519mlkem768');
    echo "PASS model save/read, node payload, VMess/VLESS/Trojan shares, H3 ALPN, lists, raw JSON objects and chrome140 uTLS\n";
}
