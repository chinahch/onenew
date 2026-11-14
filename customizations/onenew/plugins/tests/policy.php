<?php
require_once __DIR__ . '/../SubscriptionGuard/GuardPolicy.php';
use Plugin\SubscriptionGuard\GuardPolicy;
function check(bool $value,string $message):void{if(!$value)throw new RuntimeException($message);}
$p=new GuardPolicy([]);
check($p->action==='alert_only'&&!$p->notify,'Defaults must never ban or send external messages');
check(!$p->exceeds(['192.0.2.1','192.0.2.2','192.0.2.3','192.0.2.4','192.0.2.5']),'Exactly the configured maximum is allowed');
check($p->exceeds(['192.0.2.1','192.0.2.2','192.0.2.3','192.0.2.4','192.0.2.5','192.0.2.6']),'The next unique address exceeds the maximum');
check(count($p->distinctIps(['2001:db8::1','2001:0db8:0:0:0:0:0:1','::ffff:192.0.2.1','192.0.2.1']))===2,'Canonical IPv6 and mapped IPv4 must not inflate counts');
$p=new GuardPolicy(['ip_whitelist'=>"192.0.2.8/32\n2001:db8::/32",'enable_subnet_grouping'=>true]);
check($p->whitelisted('2001:db8:ffff::1')&&!$p->whitelisted('2001:db9::1'),'IPv6 CIDR boundaries');
check($p->whitelisted('192.0.2.8')&&!$p->whitelisted('192.0.2.9'),'IPv4 /32 boundaries');
check($p->distinctIps(['192.0.2.8','192.0.2.9','192.0.2.10'])===['192.0.2.0/24'],'Exclude whitelisted addresses before subnet grouping');
check(count((new GuardPolicy(['ip_whitelist'=>'0.0.0.0/0']))->distinctIps(['192.0.2.1','2001:db8::1']))===1,'IPv4 /0 must not include IPv6');
foreach([['max_unique_ips'=>'0'],['max_unique_ips'=>'abc'],['time_window_hours'=>-1],['ip_whitelist'=>'192.0.2.0/33'],['ip_whitelist'=>'2001:db8::/129'],['ip_whitelist'=>'garbage'],['action_mode'=>'something']] as $config){
  try{new GuardPolicy($config);throw new RuntimeException('Invalid guard config accepted');}catch(InvalidArgumentException $expected){}
}
check((new GuardPolicy(['enable_subnet_grouping'=>'false','enable_telegram_notify'=>'false']))->notify===false,'String booleans must not opt into notifications');
$ua=GuardPolicy::safeAgent("client token=SECRET-EXAMPLE\n<script>alert(1)</script>",'SECRET-EXAMPLE');
check(!str_contains($ua,'SECRET-EXAMPLE')&&!str_contains($ua,"\n"),'Credential and controls must be redacted from UA logs');
echo "SubscriptionGuard policy checks passed\n";
