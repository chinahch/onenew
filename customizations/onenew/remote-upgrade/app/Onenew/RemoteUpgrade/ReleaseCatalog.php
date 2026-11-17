<?php
namespace App\Onenew\RemoteUpgrade;
class ReleaseCatalog
{
    public function all(): array {
        $raw = json_decode(file_get_contents(__DIR__.'/catalog.json'),true,16,JSON_THROW_ON_ERROR);
        $catalog=[];
        foreach ($raw as $version=>$assets) {
            if (!preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+$/D',$version)) throw new \RuntimeException('Invalid official version');
            foreach ($assets as $arch=>$asset) {
                if (!in_array($arch,['amd64','arm64'],true) || !preg_match('/^[a-f0-9]{64}$/D',$asset['sha256']??'') ||
                    ($asset['url']??'') !== 'https://github.com/chinahch/onenew-releases/releases/download/node-'.$version.'/onenew-node-linux-'.$arch.'.tar.gz') {
                    throw new \RuntimeException('Invalid official release catalog');
                }
                $catalog[$version][$arch]=['version'=>$version,'arch'=>$arch,'sha256'=>$asset['sha256'],'url'=>$asset['url']];
            }
        }
        return $catalog;
    }
    public function asset(string $version,string $arch): array { $all=$this->all(); abort_unless(isset($all[$version][$arch]),422,'Official version unavailable'); return $all[$version][$arch]; }
}
