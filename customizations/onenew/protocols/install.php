<?php

declare(strict_types=1);

/** Called on the pinned fresh upstream by the root overlay installer.
 * Preflight every anchor before writing any target; never partially patch drift.
 */
function applyOnenewProtocolPatch(string $root): void
{
    $helper = '\\App\\Onenew\\Protocols\\ProtocolSettings';
    $patches = [
        'app/Models/Server.php' => [
            ['$settings = json_decode($value, true) ?? [];', '$settings = '.$helper.'::decode($value);'],
            ['return $this->castSettingsWithConfig($settings, $configs);', 'return '.$helper.'::normalize($this->castSettingsWithConfig($settings, $configs));'],
            ['$value = json_decode($value, true);', '$value = '.$helper.'::decode($value);'],
            ['$this->attributes[\'protocol_settings\'] = json_encode($castedSettings);', '$this->attributes[\'protocol_settings\'] = json_encode('.$helper.'::normalize($castedSettings));'],
        ],
        'app/Services/ServerService.php' => [
            ['$protocolSettings = $node->protocol_settings;', '$protocolSettings = '.$helper.'::normalize($node->protocol_settings);'],
        ],
        'app/Protocols/General.php' => [
            // Three Xray sharing builders: VMess, VLESS and Trojan.
            ["if (\$extra = data_get(\$protocol_settings, 'network_settings.extra'))\n                    \$config['extra'] = is_array(\$extra) && !empty(\$extra) ? json_encode(\$extra) : null;",
             "\$config['extra'] = $helper::encodeExtra(data_get(\$protocol_settings, 'network_settings.extra'));\n                if (\$alpn = data_get(\$protocol_settings, 'network_settings.alpn'))\n                    \$config['alpn'] = is_array(\$alpn) ? implode(',', \$alpn) : \$alpn;", 2],
            ["if (\$extra = data_get(\$protocol_settings, 'network_settings.extra'))\n                    \$array['extra'] = is_array(\$extra) && !empty(\$extra) ? json_encode(\$extra) : null;",
             "\$array['extra'] = $helper::encodeExtra(data_get(\$protocol_settings, 'network_settings.extra'));\n                if (\$alpn = data_get(\$protocol_settings, 'network_settings.alpn'))\n                    \$array['alpn'] = is_array(\$alpn) ? implode(',', \$alpn) : \$alpn;"],
        ],
    ];
    $writes = [];
    foreach ($patches as $file => $replacements) {
        $source = file_get_contents($root.'/'.$file);
        if ($source === false) throw new RuntimeException('Missing protocol patch target: '.$file);
        $source = str_replace("\r\n", "\n", $source);
        foreach ($replacements as $replacement) {
            [$old, $new] = $replacement;
            if (substr_count($source, $old) !== ($replacement[2] ?? 1)) throw new RuntimeException('Pinned protocol anchor changed: '.$file);
            $source = str_replace($old, $new, $source);
        }
        $writes[$root.'/'.$file] = $source;
    }
    foreach ($writes as $file => $source) {
        if (file_put_contents($file, $source) === false) throw new RuntimeException('Cannot write protocol patch: '.$file);
    }
}
