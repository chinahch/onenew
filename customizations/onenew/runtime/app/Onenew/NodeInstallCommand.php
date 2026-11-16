<?php
declare(strict_types=1);

namespace App\Onenew;

final class NodeInstallCommand
{
    private const INSTALLER = 'https://raw.githubusercontent.com/chinahch/onenew/node-v0.1.10/customizations/onenew/node/install.sh';

    public static function make(string $panel, string $token, int $machineId): string
    {
        return self::command($panel, $token, $machineId, false);
    }

    public static function upgrade(string $panel, int $machineId, string $token): string
    {
        return self::command($panel, $token, $machineId, true);
    }

    private static function command(string $panel, string $token, int $machineId, bool $upgrade): string
    {
        self::validatePanel($panel, $machineId);
        if ($token === '') {
            throw new \InvalidArgumentException('服务器 Token 不能为空。');
        }
        // Download must succeed before execution; token travels over stdin.
        $template = <<<'SH'
(
f=$(mktemp) || exit; trap 'rm -f "$f"' EXIT
r=; if [ "$(id -u)" != 0 ]; then sudo -v </dev/tty || exit; r="sudo -n"; fi
curl -fsSL __INSTALLER__ -o "$f" && printf '%s\n' __TOKEN__ | $r bash "$f" --token-stdin __UPGRADE__--mode machine --panel __PANEL__ --machine-id __MACHINE__
)
SH;
        return strtr($template, [
            '__INSTALLER__' => escapeshellarg(self::INSTALLER),
            '__TOKEN__' => escapeshellarg($token),
            '__UPGRADE__' => $upgrade ? '--upgrade ' : '',
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
