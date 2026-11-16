<?php
function applyOnenewShadowsocksTransport(string $root): void
{
    replaceOnce($root.'/app/Models/Server.php',
        "        self::TYPE_SHADOWSOCKS => [",
        "        self::TYPE_SHADOWSOCKS => [\n            'onenew_transport_managed' => ['type' => 'boolean', 'default' => false],\n            'tls' => ['type' => 'integer', 'default' => 0],\n            'network' => ['type' => 'string', 'default' => 'tcp'],\n            'network_settings' => ['type' => 'array', 'default' => null],\n            'tls_settings' => self::TLS_SETTINGS_CONFIGURATION,");
    replaceOnce($root.'/app/Http/Requests/Admin/ServerSave.php',
        "            'cipher' => 'required|string',",
        "            'onenew_transport_managed' => 'sometimes|boolean',\n            'tls' => 'sometimes|integer|in:0,1',\n            'network' => 'sometimes|string|in:tcp,ws',\n            'network_settings' => 'nullable|array',\n            'tls_settings' => 'nullable|array',\n            'cipher' => 'required|string',");
    replaceOnce($root.'/app/Onenew/Protocols/ProtocolSettings.php',
        "    public static function normalize(array \$settings): array\n    {",
        "    public static function normalize(array \$settings): array\n    {\n        \$settings = \\App\\Onenew\\ShadowsocksTransport::normalize(\$settings);");
    replaceOnce($root.'/app/Services/ServerService.php',
        "        return \$response;\n    }",
        "        return \\App\\Onenew\\ShadowsocksTransport::nodeConfig(\$response, \$node);\n    }");

    $controller = $root.'/app/Http/Controllers/V2/Admin/Server/ManageController.php';
    $text = file_get_contents($controller);
    $old = 'if ($e instanceof \\Symfony\\Component\\HttpKernel\\Exception\\HttpExceptionInterface) throw $e;';
    $new = 'if ($e instanceof \\Illuminate\\Validation\\ValidationException) throw $e; '.$old;
    if (substr_count($text, $old) !== 2) throw new RuntimeException('SS validation controller anchor changed');
    file_put_contents($controller, str_replace($old, $new, $text));

    foreach (['Clash.php', 'ClashMeta.php'] as $name) {
        replaceOnce($root.'/app/Protocols/'.$name,
            "        \$array['udp'] = true;\n        if (data_get(\$protocol_settings, 'plugin')",
            "        \$array['udp'] = data_get(\$protocol_settings, 'plugin') !== 'v2ray-plugin';\n        if (data_get(\$protocol_settings, 'plugin')");
    }

    replaceOnce($root.'/app/Protocols/Clash.php',
        "if (!str_contains(\$pair, '=')) {\n                        return [];",
        "if (!str_contains(\$pair, '=')) {\n                        return [trim(\$pair) => 'true'];");

    replaceOnce($root.'/app/Protocols/ClashMeta.php',
        "'mux' => isset(\$parsedOpts['mux']) ? true : null,",
        "'mux' => isset(\$parsedOpts['mux']) ? !in_array(strtolower((string) \$parsedOpts['mux']), ['0', 'false', 'off'], true) : null,");
    replaceOnce($root.'/app/Protocols/Clash.php',
        "'tls' => isset(\$parsedOpts['tls']) && \$parsedOpts['tls'] == 'true',",
        "'tls' => isset(\$parsedOpts['tls']) && \$parsedOpts['tls'] == 'true',\n                        'mux' => isset(\$parsedOpts['mux']) && !in_array(strtolower((string) \$parsedOpts['mux']), ['0', 'false', 'off'], true),");
}
