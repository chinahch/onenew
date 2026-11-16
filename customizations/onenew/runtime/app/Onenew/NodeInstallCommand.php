<?php
declare(strict_types=1);

namespace App\Onenew;

final class NodeInstallCommand
{
    private const BASE = 'https://get.timi.baby';

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
        // The short entry atomically downloads and hashes the complete installer,
        // handles sudo using the terminal, and keeps the machine token on stdin.
        $entry = self::BASE . ($upgrade ? '/upgrade' : '/install');
        return "printf '%s\\n' " . self::quote($token)
            . " | bash <(curl -fsSL " . self::quote($entry) . " || printf '\\nexit 1\\n')"
            . " --token-stdin --panel " . self::quote(rtrim($panel, '/')) . " --machine-id " . $machineId;
    }

    private static function quote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }

    private static function validatePanel(string $panel, int $machineId): void
    {
        $url = parse_url($panel);
        if (!$url || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || $machineId < 1) {
            throw new \InvalidArgumentException('请先将面板站点 URL 配置为有效的 HTTPS 地址。');
        }
    }
}
