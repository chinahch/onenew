<?php
require __DIR__.'/../app/Onenew/External/ShareLink.php';
use App\Onenew\External\ShareLink;
$socks = 'socks://'.rtrim(base64_encode('alice:separate-password'), '=').'@proxy.example:1080#Home';
foreach (['clash', 'singbox'] as $format) {
    $proxy = ShareLink::convert($socks, $format, 'Home');
    if ($proxy['username'] !== 'alice' || $proxy['password'] !== 'separate-password') throw new RuntimeException('SOCKS credentials changed');
}
$vless = 'vless://11111111-2222-4333-8444-555555555555@node.example:443?encryption=none&flow=xtls-rprx-vision&security=reality&sni=addons.mozilla.org&fp=chrome&pbk='.str_repeat('A',43).'&sid=1234&type=tcp&headerType=none#Test';
foreach (['clash', 'singbox'] as $format) {
    $proxy = ShareLink::convert($vless, $format, 'Reality');
    if ($proxy['uuid'] !== '11111111-2222-4333-8444-555555555555') throw new RuntimeException('VLESS credential changed');
}
$ss = 'ss://'.rtrim(base64_encode('2022-blake3-aes-128-gcm:secret'), '=').'@[2001:db8::1]:8388#SS';
if (ShareLink::convert($ss, 'clash', 'SS')['cipher'] !== '2022-blake3-aes-128-gcm') throw new RuntimeException('SS2022 lost');
if (ShareLink::parse($vless)['type'] !== 'vless') throw new RuntimeException('Wrong protocol');
foreach (['socks'=>'a:???','ss'=>'aes-128-gcm:???'] as $scheme=>$credentials) {
    $link=$scheme.'://'.base64_encode($credentials).'@proxy.example:1080';
    if (ShareLink::convert($link,'singbox','Slash')['password']!=='???') throw new RuntimeException('Base64 slash not preserved');
}
$withoutFingerprint=str_replace('&fp=chrome','',$vless);
if (ShareLink::convert($withoutFingerprint,'singbox','Reality')['tls']['utls']['fingerprint']!=='chrome') throw new RuntimeException('REALITY requires uTLS');
$chrome140=str_replace('&fp=chrome','&fingerprint=Chrome140',$vless);
$clash140=ShareLink::convert($chrome140,'clash','Reality140');
if (($clash140['client-fingerprint']??null)!=='chrome' || ($clash140['reality-opts']['support-x25519mlkem768']??false)!==true) throw new RuntimeException('Chrome140 must enable support-x25519mlkem768 for Clash REALITY');
if ((ShareLink::convert($chrome140,'singbox','Reality140')['tls']['utls']['fingerprint']??null)!=='chrome') throw new RuntimeException('Chrome140 must map to chrome for sing-box');
foreach (['trojan'=>'password','anytls'=>'password','tuic'=>'11111111-2222-4333-8444-555555555555:password'] as $scheme=>$auth) {
    if (ShareLink::convert($scheme.'://'.$auth.'@proxy.example:443?sni=certificate.example','clash','TLS')['sni']!=='certificate.example') throw new RuntimeException('Protocol SNI field wrong');
}
foreach (['type=grpc&path=%2Fbad','type=ws&serviceName=bad'] as $network) {
    try { ShareLink::convert('vless://11111111-2222-4333-8444-555555555555@proxy.example:443?'.$network,'clash','Bad'); throw new RuntimeException('Dropped transport field'); }
    catch (InvalidArgumentException $expected) {}
}
try { ShareLink::convert(str_replace('#Test', '&unknown=x#Test', $vless), 'singbox', 'Unsupported'); throw new RuntimeException('Did not refuse unknown parameter'); }
catch (InvalidArgumentException $expected) {}
echo "PASS: external SOCKS/REALITY/SS2022 conversion and refusal checks\n";
