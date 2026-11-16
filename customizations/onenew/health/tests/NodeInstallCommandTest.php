<?php
namespace Tests\Feature;

use App\Onenew\NodeInstallCommand;
use PHPUnit\Framework\TestCase;

final class NodeInstallCommandTest extends TestCase
{
    public function test_own_installer_and_shell_safe_machine_arguments(): void
    {
        $command = NodeInstallCommand::make('https://panel.example.test/', "fixture'\$token", 7);
        self::assertStringContainsString('https://get.timi.baby/install', $command);
        self::assertStringNotContainsString('cedar2025', $command);
        self::assertStringContainsString('--machine-id 7', $command);
        self::assertStringContainsString('--token-stdin', $command);
        self::assertStringNotContainsString('--token \'', $command);
        self::assertStringContainsString(escapeshellarg("fixture'\$token"), $command);
        self::assertStringContainsString('bash <(curl -fsSL', $command);
        self::assertStringNotContainsString('github.com', $command);
    }

    public function test_plaintext_panel_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NodeInstallCommand::make('http://panel.example.test', 'fixture', 1);
    }

    public function test_upgrade_command_uses_current_installer_and_embeds_token_without_prompt(): void
    {
        $command = NodeInstallCommand::upgrade('https://panel.example.test/', 15, "fixture'private-token");
        self::assertStringContainsString('https://get.timi.baby/upgrade', $command);
        self::assertStringNotContainsString('/master/customizations/onenew/node/install.sh', $command);
        self::assertStringContainsString('/upgrade', $command);
        self::assertStringContainsString('--token-stdin', $command);
        self::assertStringContainsString('--machine-id 15', $command);
        self::assertStringContainsString("--panel 'https://panel.example.test'", $command);
        self::assertStringNotContainsString('read -rsp', $command);
        self::assertStringContainsString(escapeshellarg("fixture'private-token"), $command);
        self::assertStringContainsString("exit 1", $command);
    }
}

