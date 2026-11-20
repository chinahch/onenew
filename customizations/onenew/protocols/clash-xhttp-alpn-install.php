<?php

declare(strict_types=1);

/** Preserve an explicitly configured XHTTP ALPN in Mihomo/Clash subscriptions. */
function applyOnenewClashXhttpAlpnPatch(string $root): void
{
    $path = $root.'/app/Protocols/ClashMeta.php';
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Missing Clash XHTTP ALPN patch target');
    }
    $source = str_replace("\r\n", "\n", $source);
    $anchor = "                if (\$mode = data_get(\$protocol_settings, 'network_settings.mode'))\n                    \$xhttpOpts['mode'] = \$mode;";
    if (substr_count($source, $anchor) !== 1) {
        throw new RuntimeException('Pinned Clash XHTTP ALPN anchor changed');
    }
    $replacement = $anchor."\n                if (\$alpn = data_get(\$protocol_settings, 'network_settings.alpn')) {\n"
        . "                    \$array['alpn'] = is_array(\$alpn) ? array_values(\$alpn) : array_values(array_filter(array_map('trim', explode(',', (string) \$alpn))));\n"
        . "                }";
    if (file_put_contents($path, str_replace($anchor, $replacement, $source)) === false) {
        throw new RuntimeException('Cannot apply Clash XHTTP ALPN patch');
    }
}
