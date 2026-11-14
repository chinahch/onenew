<?php
require __DIR__ . '/../app/Onenew/EgressPolicy.php';
use App\Onenew\EgressPolicy;
function check($condition, $message) { if (!$condition) { throw new RuntimeException($message); } }
check(EgressPolicy::effective('inherit', []) === null, 'absent policy preserves local YAML');
check(EgressPolicy::effective('prefer_ipv6', []) === 'prefer_ipv6', 'machine priority');
check(EgressPolicy::effective('prefer_ipv6', ['mode' => 'default', 'identity' => 'a'], 'a') === 'default', 'explicit system default');
check(EgressPolicy::effective('prefer_ipv6', ['mode' => 'prefer_ipv4', 'identity' => 'a'], 'a') === 'prefer_ipv4', 'node override');
check(EgressPolicy::effective('prefer_ipv6', ['mode' => 'prefer_ipv4', 'identity' => 'old'], 'new') === 'prefer_ipv6', 'reused node id must not reuse policy');
check(EgressPolicy::effective('default', ['mode' => 'inherit', 'identity' => 'a'], 'a') === 'default', 'inherit machine');
try { EgressPolicy::validateMode('ipv4_only'); throw new RuntimeException('accepted strict mode'); } catch (InvalidArgumentException $expected) {}
echo "egress policy tests passed\n";
