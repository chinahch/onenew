<?php

declare(strict_types=1);

function applyOnenewFragmentPatch(string $root): void
{
    $helper = '\\App\\Onenew\\Protocols\\TlsFragment';
    $patches = [
        'app/Models/Server.php' => [
            ["    private const TLS_SETTINGS_CONFIGURATION = [\n        'type' => 'object',\n        'fields' => [",
             "    private const TLS_SETTINGS_CONFIGURATION = [\n        'type' => 'object',\n        'fields' => [\n            'fragment' => ['type' => 'object', 'default' => null, 'fields' => [\n                'enabled' => ['type' => 'boolean', 'default' => false],\n                'length' => ['type' => 'string', 'default' => '100-200'],\n                'interval' => ['type' => 'string', 'default' => '10-20'],\n            ]],"],
        ],
        'app/Http/Requests/Admin/ServerSave.php' => [
            ["        return \$rules;\n    }\n\n    public function attributes()",
             "        if (in_array(\$type, ['vmess', 'vless', 'trojan'], true)) {\n            \$rules['protocol_settings.tls_settings.fragment'] = 'nullable|array';\n            \$rules['protocol_settings.tls_settings.fragment.enabled'] = 'nullable|boolean';\n            \$rules['protocol_settings.tls_settings.fragment.length'] = ['nullable', 'string', new \\App\\Onenew\\Protocols\\FragmentRange(1, 16384)];\n            \$rules['protocol_settings.tls_settings.fragment.interval'] = ['nullable', 'string', new \\App\\Onenew\\Protocols\\FragmentRange(0, 1000)];\n        }\n        return \$rules;\n    }\n\n    public function attributes()"],
        ],
        'app/Protocols/Shadowrocket.php' => [
            ["        \$query = http_build_query(\$config, '', '&', PHP_QUERY_RFC3986);",
             "        $helper::appendShadowrocket(\$config, \$server);\n        \$query = http_build_query(\$config, '', '&', PHP_QUERY_RFC3986);", 2],
            ["        \$query = http_build_query(\$params);\n        \$addr = Helper::wrapIPv6(\$server['host']);\n\n        \$uri = \"trojan://",
             "        $helper::appendShadowrocket(\$params, \$server);\n        \$query = http_build_query(\$params);\n        \$addr = Helper::wrapIPv6(\$server['host']);\n\n        \$uri = \"trojan://"],
        ],
    ];
    // The base protocol overlay has already normalized this assignment.
    $assignment = '$protocolSettings = \\App\\Onenew\\Protocols\\ProtocolSettings::normalize($node->protocol_settings);';
    $patches['app/Services/ServerService.php'] = [[$assignment,
        $assignment."\n        unset(\$protocolSettings['tls_settings']['fragment']);"]];
    $writes = [];
    foreach ($patches as $file => $replacements) {
        $source = str_replace("\r\n", "\n", (string) file_get_contents($root.'/'.$file));
        foreach ($replacements as $replacement) {
            [$old, $new] = $replacement;
            if (substr_count($source, $old) !== ($replacement[2] ?? 1)) {
                throw new RuntimeException('Pinned fragment anchor changed: '.$file);
            }
            $source = str_replace($old, $new, $source);
        }
        $writes[$root.'/'.$file] = $source;
    }
    foreach ($writes as $file => $source) {
        if (file_put_contents($file, $source) === false) {
            throw new RuntimeException('Cannot write fragment patch: '.$file);
        }
    }
}
