<?php
function applyOnenewExternalNodes(string $root): void
{
    $class = '\\App\\Onenew\\External\\ExternalNodes';
    replaceOnce($root.'/app/Http/Controllers/V1/Client/ClientController.php',
        '        if (!$userService->isAvailable($user)) {',
        "        if (!\$user->banned && \$user->expired_at !== null && (int)\$user->expired_at <= time()) {\n            return \\App\\Onenew\\External\\ExpiredSubscription::response(\$request, \$user);\n        }\n\n        if (!\$userService->isAvailable(\$user)) {");
    replaceOnce($root.'/app/Http/Controllers/V1/Client/ClientController.php',
        '$servers = ServerService::getAvailableServers($user);',
        '$servers = '.$class.'::append(ServerService::getAvailableServers($user), $user);');
    replaceOnce($root.'/app/Http/Controllers/V1/Client/ClientController.php',
        '$this->setSubscribeInfoToServers($serversFiltered, $user, count($servers) - count($serversFiltered));',
        $class.'::validateClient($serversFiltered, $protocolClassName);' . "\n        "
        . '$this->setSubscribeInfoToServers($serversFiltered, $user, count($servers) - count($serversFiltered));');
    replaceOnce($root.'/app/Support/AbstractProtocol.php', "    protected function isCompatible(\$server)\n    {",
        "    protected function isCompatible(\$server)\n    {\n        if (isset(\$server['onenew_external_link'])) return true;");
    replaceOnce($root.'/app/Protocols/General.php', 'foreach ($servers as $item) {',
        'foreach ($servers as $item) {' . "\n            if (isset(\$item['onenew_external_link'])) {\n"
        . "                \$uri .= \\App\\Onenew\\External\\ShareLink::renamed(\$item['onenew_external_link'], \$item['name']).\"\\r\\n\";\n                continue;\n            }");
    foreach (['Clash','ClashMeta'] as $name) {
        replaceOnce($root.'/app/Protocols/'.$name.'.php', 'foreach ($servers as $item) {',
            'foreach ($servers as $item) {' . "\n            if (isset(\$item['onenew_external_link'])) {\n"
            . "                \$proxy[] = $class::convert(\$item, 'clash');\n                \$proxies[] = \$item['name'];\n                continue;\n            }");
    }
    replaceOnce($root.'/app/Protocols/SingBox.php', 'foreach ($this->servers as $item) {',
        'foreach ($this->servers as $item) {' . "\n            if (isset(\$item['onenew_external_link'])) {\n"
        . "                \$proxies[] = $class::convert(\$item, 'singbox');\n                continue;\n            }");
}
