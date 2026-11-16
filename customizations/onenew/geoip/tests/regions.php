<?php
require __DIR__.'/../../runtime/app/Onenew/IpRegion.php';
use App\Onenew\IpRegion;
function check($actual,$expected){if($actual!==$expected)throw new RuntimeException(json_encode([$actual,$expected],JSON_UNESCAPED_UNICODE));}
check(IpRegion::label('中国|广东省|深圳市|电信|CN'),'中国 · 广东省 · 深圳市');
check(IpRegion::label('United States|California|Los Angeles|0|US'),'美国');
check(IpRegion::label('中国|香港|0|0|CN'),'中国 · 香港');
check(IpRegion::label('0|0|0|0|0'),'未知');
$r=new IpRegion($argv[1]??dirname(__DIR__));
check($r->lookup('127.0.0.1'),'内网 IP');check($r->lookup('fd00::1'),'内网 IP');check($r->lookup('::ffff:192.168.1.1'),'内网 IP');check($r->lookup('not-an-ip'),'无效 IP');
check($r->lookup('120.229.45.2'),'中国 · 广东省 · 深圳市');
check($r->lookup('2604:a840:3::a04d'),'美国');
check($r->lookup('240e:3b7:3272:d8d0:db09:c067:8d59:539e'),'中国 · 广东省 · 深圳市');
$missing=new IpRegion('/missing-db');check($missing->lookup('8.8.8.8'),'未知');
echo "Local IPv4/IPv6 Chinese regions passed\n";
