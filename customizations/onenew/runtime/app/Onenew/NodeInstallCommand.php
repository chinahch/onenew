<?php
declare(strict_types=1);

namespace App\Onenew;

final class NodeInstallCommand
{
    private const INSTALLER = 'https://raw.githubusercontent.com/chinahch/onenew/node-v0.1.7/customizations/onenew/node/install.sh';

    public static function make(string $panel, string $token, int $machineId): string
    {
        self::validatePanel($panel, $machineId);
        // The token is carried over stdin, never as a process argument. A local
        // temporary script avoids competing with Bash's stdin for the token.
        $template = <<<'SH'
(
set -e
umask 077
ONENEW_INSTALLER=$(mktemp /tmp/onenew-node-install.XXXXXX)
trap 'rm -f -- "$ONENEW_INSTALLER"; unset NODE_TOKEN ONENEW_INSTALLER' EXIT
curl --proto '=https' --tlsv1.2 -fsSL __INSTALLER__ -o "$ONENEW_INSTALLER"
NODE_TOKEN=__TOKEN__
if [ "$(id -u)" -eq 0 ]; then
  printf '%s\n' "$NODE_TOKEN" | bash "$ONENEW_INSTALLER" --token-stdin --mode machine --panel __PANEL__ --machine-id __MACHINE__
else
  sudo -v </dev/tty
  printf '%s\n' "$NODE_TOKEN" | sudo -n bash "$ONENEW_INSTALLER" --token-stdin --mode machine --panel __PANEL__ --machine-id __MACHINE__
fi
)
SH;
        return strtr($template, [
            '__INSTALLER__' => escapeshellarg(self::INSTALLER),
            '__TOKEN__' => escapeshellarg($token),
            '__PANEL__' => escapeshellarg(rtrim($panel, '/')),
            '__MACHINE__' => (string) $machineId,
        ]);
    }

    public static function upgrade(string $panel, int $machineId): string
    {
        self::validatePanel($panel, $machineId);
        // Always download the reviewed release installer, not mutable master.
        // Authenticate the machine before touching binaries or credentials;
        // install.sh enforces this even when called independently.
        $template = <<<'SH'
(
set -e
umask 077
ONENEW_INSTALLER=$(mktemp /tmp/onenew-node-upgrade.XXXXXX)
trap 'rm -f -- "$ONENEW_INSTALLER"; unset NODE_TOKEN ONENEW_INSTALLER' EXIT
curl --proto '=https' --tlsv1.2 -fsSL __INSTALLER__ -o "$ONENEW_INSTALLER"
if [ "$(id -u)" -ne 0 ]; then sudo -v </dev/tty; fi
read -rsp '服务器 Token：' NODE_TOKEN </dev/tty
printf '\n'
if [ "$(id -u)" -eq 0 ]; then
  printf '%s\n' "$NODE_TOKEN" | bash "$ONENEW_INSTALLER" --token-stdin --upgrade --mode machine --panel __PANEL__ --machine-id __MACHINE__
else
  printf '%s\n' "$NODE_TOKEN" | sudo -n bash "$ONENEW_INSTALLER" --token-stdin --upgrade --mode machine --panel __PANEL__ --machine-id __MACHINE__
fi
)
SH;
        return strtr($template, [
            '__INSTALLER__' => escapeshellarg(self::INSTALLER),
            '__PANEL__' => escapeshellarg(rtrim($panel, '/')),
            '__MACHINE__' => (string) $machineId,
        ]);
    }

    private static function validatePanel(string $panel, int $machineId): void
    {
        $url = parse_url($panel);
        if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || $machineId < 1) {
            throw new \InvalidArgumentException('请先将面板站点 URL 配置为有效的 HTTPS 地址。');
        }
    }
}
