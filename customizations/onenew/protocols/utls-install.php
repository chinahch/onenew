<?php

declare(strict_types=1);

function applyOnenewUtlsFingerprintPatch(string $root): void
{
    $helper = '\\App\\Onenew\\Protocols\\UtlsFingerprint';
    $fragmentCallConfig = "        \\App\\Onenew\\Protocols\\TlsFragment::appendShadowrocket(\$config, \$server);";
    $fragmentCallParams = "        \\App\\Onenew\\Protocols\\TlsFragment::appendShadowrocket(\$params, \$server);";

    $targets = [
        'app/Utils/Helper.php',
        'app/Http/Requests/Admin/ServerSave.php',
        'app/Protocols/Shadowrocket.php',
        'app/Protocols/ClashMeta.php',
    ];
    $sources = [];
    foreach ($targets as $file) {
        $raw = file_get_contents($root.'/'.$file);
        if ($raw === false) {
            throw new RuntimeException('Missing uTLS fingerprint patch target: '.$file);
        }
        $sources[$file] = str_replace("\r\n", "\n", $raw);
    }

    $helperAnchor = "            \$fingerprint = data_get(\$utls, 'fingerprint', 'chrome');\n            if (\$fingerprint !== 'random') {\n                return \$fingerprint;\n            }";
    if (substr_count($sources['app/Utils/Helper.php'], $helperAnchor) !== 1) {
        throw new RuntimeException('Pinned Helper uTLS anchor changed');
    }
    $sources['app/Utils/Helper.php'] = str_replace(
        $helperAnchor,
        "            \$fingerprint = data_get(\$utls, 'fingerprint', 'chrome');\n            if (\$fingerprint !== 'random') {\n                return {$helper}::compatible((string) \$fingerprint);\n            }",
        $sources['app/Utils/Helper.php']
    );

    $saveAnchor = "        'utls.fingerprint' => 'nullable|string',";
    if (substr_count($sources['app/Http/Requests/Admin/ServerSave.php'], $saveAnchor) !== 1) {
        throw new RuntimeException('Pinned ServerSave uTLS anchor changed');
    }
    $sources['app/Http/Requests/Admin/ServerSave.php'] = str_replace(
        $saveAnchor,
        "        'utls.fingerprint' => 'nullable|string|in:chrome,chrome140,firefox,safari,ios,edge,qq,360,android,random,randomized',",
        $sources['app/Http/Requests/Admin/ServerSave.php']
    );

    if (substr_count($sources['app/Protocols/Shadowrocket.php'], $fragmentCallConfig) !== 2 ||
        substr_count($sources['app/Protocols/Shadowrocket.php'], $fragmentCallParams) !== 1) {
        throw new RuntimeException('Pinned Shadowrocket uTLS anchor changed');
    }
    $posFirst = strpos($sources['app/Protocols/Shadowrocket.php'], $fragmentCallConfig);
    $sources['app/Protocols/Shadowrocket.php'] = substr_replace(
        $sources['app/Protocols/Shadowrocket.php'],
        "        {$helper}::appendShadowrocket(\$config, \$server, 'vmess');\n".$fragmentCallConfig,
        $posFirst,
        strlen($fragmentCallConfig)
    );
    $posSecond = strpos($sources['app/Protocols/Shadowrocket.php'], $fragmentCallConfig, $posFirst + strlen($fragmentCallConfig) + 60);
    if ($posSecond === false) {
        throw new RuntimeException('Pinned Shadowrocket VLESS uTLS anchor changed');
    }
    $sources['app/Protocols/Shadowrocket.php'] = substr_replace(
        $sources['app/Protocols/Shadowrocket.php'],
        "        {$helper}::appendShadowrocket(\$config, \$server, 'vless');\n".$fragmentCallConfig,
        $posSecond,
        strlen($fragmentCallConfig)
    );
    $sources['app/Protocols/Shadowrocket.php'] = str_replace(
        $fragmentCallParams,
        "        {$helper}::appendShadowrocket(\$params, \$server, 'trojan');\n".$fragmentCallParams,
        $sources['app/Protocols/Shadowrocket.php']
    );

    $clashVlessAnchor = "                \$array['reality-opts'] = [\n                    'public-key' => data_get(\$protocol_settings, 'reality_settings.public_key'),\n                    'short-id' => data_get(\$protocol_settings, 'reality_settings.short_id')\n                ];\n                self::appendUtls(\$array, \$protocol_settings);";
    $clashTrojanAnchor = "                \$array['reality-opts'] = [\n                    'public-key' => data_get(\$protocol_settings, 'reality_settings.public_key'),\n                    'short-id' => data_get(\$protocol_settings, 'reality_settings.short_id'),\n                ];";
    if (substr_count($sources['app/Protocols/ClashMeta.php'], $clashVlessAnchor) !== 1 ||
        substr_count($sources['app/Protocols/ClashMeta.php'], $clashTrojanAnchor) !== 1) {
        throw new RuntimeException('Pinned ClashMeta REALITY uTLS anchor changed');
    }
    $sources['app/Protocols/ClashMeta.php'] = str_replace(
        [$clashVlessAnchor, $clashTrojanAnchor],
        [
            $clashVlessAnchor."\n                {$helper}::appendClashReality(\$array, \$protocol_settings);",
            $clashTrojanAnchor."\n                {$helper}::appendClashReality(\$array, \$protocol_settings);",
        ],
        $sources['app/Protocols/ClashMeta.php']
    );

    foreach ($sources as $file => $content) {
        if (file_put_contents($root.'/'.$file, $content) === false) {
            throw new RuntimeException('Cannot write uTLS fingerprint patch: '.$file);
        }
    }
}
