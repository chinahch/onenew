<?php
require __DIR__.'/../../runtime/app/Onenew/UserFrontendDomains.php';
use App\Onenew\UserFrontendDomains;
function check($condition) { if(!$condition) throw new RuntimeException('Domain assertion failed'); }
check(UserFrontendDomains::normalize(['https://A.example/','https://a.example:443','http://b.example:80/'])===['https://a.example','http://b.example']);
check(UserFrontendDomains::normalize([])===[]);
foreach (['javascript:alert(1)','https://user:pass@example.com','https://a.example/path','https://a.example?','https://a.example#x','https://a.example/a/..',"https://a.exa\nmple",'https://*.example.com'] as $bad) {
    try { UserFrontendDomains::normalize([$bad]); throw new LogicException('Accepted invalid origin'); } catch (InvalidArgumentException $e) {}
}
try { UserFrontendDomains::normalize(array_fill(0,21,'https://a.example')); throw new LogicException('Accepted oversized list'); } catch(InvalidArgumentException $e) {}
echo "Domain normalization passed\n";
