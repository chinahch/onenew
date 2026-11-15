<?php
declare(strict_types=1);

namespace App\Onenew;

final class NodeInstallCommand
{
    public static function make(string $panel, string $token, int $machineId): string
    {
        $url = parse_url($panel);
        if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || $machineId < 1) {
            throw new \InvalidArgumentException('请先将面板站点 URL 配置为有效的 HTTPS 地址。');
        }
        $installer = 'https://raw.githubusercontent.com/chinahch/onenew/node-v0.1.4/customizations/onenew/node/install.sh';
        // Works when pasted into either a root shell or a sudo-enabled account.
        return sprintf('curl -fsSL %s | %s -- --mode machine --panel %s --token %s --machine-id %d',
            escapeshellarg($installer), '$(if [ "$(id -u)" -eq 0 ]; then printf "bash -s"; else printf "sudo bash -s"; fi)',
            escapeshellarg(rtrim($panel, '/')), escapeshellarg($token), $machineId);
    }
}
