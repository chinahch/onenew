<?php
declare(strict_types=1);

namespace App\Onenew;

final class NodeInstallCommand
{
    public static function make(string $panel, string $token, int $machineId): string
    {
        self::validatePanel($panel, $machineId);
        $installer = 'https://raw.githubusercontent.com/chinahch/onenew/node-v0.1.4/customizations/onenew/node/install.sh';
        // Works when pasted into either a root shell or a sudo-enabled account.
        return sprintf('curl -fsSL %s | %s -- --mode machine --panel %s --token %s --machine-id %d',
            escapeshellarg($installer), '$(if [ "$(id -u)" -eq 0 ]; then printf "bash -s"; else printf "sudo bash -s"; fi)',
            escapeshellarg(rtrim($panel, '/')), escapeshellarg($token), $machineId);
    }

    public static function upgrade(string $panel, int $machineId): string
    {
        self::validatePanel($panel, $machineId);
        // The latest reviewed installer selects a checksummed release package.
        // Read the token on the node machine so the admin page never exposes it.
        $installer = 'https://raw.githubusercontent.com/chinahch/onenew/master/customizations/onenew/node/install.sh';
        return sprintf(
            "ONENEW_INSTALLER=\$(mktemp /tmp/onenew-node-upgrade.XXXXXX) &&\n" .
            "curl -fsSL %s -o \"\$ONENEW_INSTALLER\" &&\n" .
            "read -rsp '服务器 Token：' NODE_TOKEN && echo &&\n" .
            "\$(if [ \"\$(id -u)\" -eq 0 ]; then printf 'bash'; else printf 'sudo bash'; fi) \"\$ONENEW_INSTALLER\" --upgrade --mode machine --panel %s --token \"\$NODE_TOKEN\" --machine-id %d;\n" .
            "unset NODE_TOKEN; rm -f -- \"\$ONENEW_INSTALLER\"; unset ONENEW_INSTALLER",
            escapeshellarg($installer), escapeshellarg(rtrim($panel, '/')), $machineId
        );
    }

    private static function validatePanel(string $panel, int $machineId): void
    {
        $url = parse_url($panel);
        if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || $machineId < 1) {
            throw new \InvalidArgumentException('请先将面板站点 URL 配置为有效的 HTTPS 地址。');
        }
    }
}

