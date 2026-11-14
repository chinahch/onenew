<?php
namespace Tests\Feature;

use App\Onenew\NodeInstallCommand;
use PHPUnit\Framework\TestCase;

final class NodeInstallCommandTest extends TestCase
{
    public function test_own_installer_and_shell_safe_machine_arguments(): void
    {
        $command = NodeInstallCommand::make('https://panel.example.test/', "fixture'\$token", 7);
        self::assertStringContainsString('https://raw.githubusercontent.com/chinahch/onenew/node-v0.1.2/customizations/onenew/node/install.sh', $command);
        self::assertStringNotContainsString('cedar2025', $command);
        self::assertStringContainsString('--machine-id 7', $command);
        self::assertStringContainsString(escapeshellarg("fixture'\$token"), $command);
        self::assertStringContainsString('id -u', $command);
    }

    public function test_plaintext_panel_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NodeInstallCommand::make('http://panel.example.test', 'fixture', 1);
    }
}
