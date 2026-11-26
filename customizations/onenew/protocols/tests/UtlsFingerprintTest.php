<?php

namespace Tests\Feature\Onenew;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Models\User;
use App\Onenew\ProbeOutboundBuilder;
use App\Onenew\Protocols\UtlsFingerprint;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\Shadowrocket;
use App\Protocols\SingBox;
use App\Utils\Helper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class UtlsFingerprintTest extends TestCase
{
    use RefreshDatabase;

    private function query(string $link): array
    {
        parse_str(parse_url(trim($link), PHP_URL_QUERY) ?: '', $query);
        return $query;
    }

    public function test_chrome140_maps_cleanly_across_shadowrocket_mihomo_singbox_general_and_probes(): void
    {
        $uuid = '00000000-0000-4000-8000-000000000001';
        $realitySettings = [
            'tls' => 2,
            'network' => 'tcp',
            'flow' => 'xtls-rprx-vision',
            'utls' => ['enabled' => true, 'fingerprint' => 'chrome140'],
            'reality_settings' => [
                'server_name' => 'www.microsoft.com',
                'public_key' => str_repeat('A', 43),
                'short_id' => '1234',
            ],
        ];
        $node = Server::create([
            'name' => 'reality-chrome140',
            'type' => 'vless',
            'host' => 'node.example',
            'port' => 443,
            'server_port' => 443,
            'rate' => 1,
            'protocol_settings' => $realitySettings,
        ])->fresh();
        $server = $node->toArray();

        $this->assertSame('chrome', Helper::getTlsFingerprint($server['protocol_settings']['utls']));

        $srQuery = $this->query(Shadowrocket::buildVless($uuid, $server));
        $this->assertSame(UtlsFingerprint::SHADOWROCKET_CHROME_140, $srQuery['fingerprint'] ?? null);
        $this->assertArrayNotHasKey('fp', $srQuery);

        $clash = ClashMeta::buildVless($uuid, $server);
        $this->assertSame('chrome', $clash['client-fingerprint'] ?? null);
        $this->assertTrue($clash['reality-opts']['support-x25519mlkem768'] ?? false);

        $sb = (new SingBox($this->createMock(User::class), [$server]))->buildVless($uuid, $server);
        $this->assertSame('chrome', $sb['tls']['utls']['fingerprint'] ?? null);

        $genQuery = $this->query(General::buildVless($uuid, $server));
        $this->assertSame('chrome', $genQuery['fp'] ?? null);

        $user = User::create([
            'email' => Str::uuid().'@example.test',
            'password' => 'unused',
            'uuid' => $uuid,
            'token' => bin2hex(random_bytes(16)),
        ]);
        $probe = app(ProbeOutboundBuilder::class)->build($node, $user);
        $this->assertSame('chrome', $probe['outbound']['settings']['tls']['utls']['fingerprint'] ?? null);

        // Trojan TLS + REALITY
        $trojanTls = Server::create([
            'name' => 'trojan-tls-chrome140',
            'type' => 'trojan',
            'host' => 'node.example',
            'port' => 443,
            'server_port' => 443,
            'rate' => 1,
            'protocol_settings' => [
                'tls' => 1,
                'network' => 'tcp',
                'utls' => ['enabled' => true, 'fingerprint' => 'chrome140'],
                'tls_settings' => ['server_name' => 'tls.example'],
            ],
        ])->fresh()->toArray();
        $trojanQuery = $this->query(Shadowrocket::buildTrojan($uuid, $trojanTls));
        $this->assertSame('Chrome140', $trojanQuery['fingerprint'] ?? null);
        $this->assertArrayNotHasKey('fp', $trojanQuery);

        $trojanReality = $trojanTls;
        $trojanReality['protocol_settings']['tls'] = 2;
        $trojanReality['protocol_settings']['reality_settings'] = $realitySettings['reality_settings'];
        $clashTrojan = ClashMeta::buildTrojan($uuid, $trojanReality);
        $this->assertSame('chrome', $clashTrojan['client-fingerprint'] ?? null);
        $this->assertTrue($clashTrojan['reality-opts']['support-x25519mlkem768'] ?? false);
    }

    public function test_admin_save_accepts_chrome140_and_rejects_unknown_fingerprint(): void
    {
        Sanctum::actingAs(User::create([
            'email' => Str::uuid().'@example.test',
            'password' => 'unused',
            'uuid' => (string) Str::uuid(),
            'token' => bin2hex(random_bytes(16)),
            'is_admin' => true,
        ]));
        $route = collect(app('router')->getRoutes()->getRoutes())->first(
            fn ($route) => $route->getActionName() === ManageController::class.'@save'
        );
        $this->assertNotNull($route);
        $endpoint = '/'.$route->uri();

        $payload = [
            'name' => 'vless-chrome140-save',
            'type' => 'vless',
            'host' => 'node.example',
            'port' => 443,
            'server_port' => 443,
            'rate' => 1,
            'group_ids' => [],
            'protocol_settings' => [
                'tls' => 2,
                'network' => 'tcp',
                'utls' => ['enabled' => true, 'fingerprint' => 'chrome140'],
                'reality_settings' => [
                    'server_name' => 'www.microsoft.com',
                    'public_key' => str_repeat('A', 43),
                    'short_id' => '1234',
                ],
            ],
        ];
        $this->postJson($endpoint, $payload)->assertOk();
        $node = Server::where('name', 'vless-chrome140-save')->firstOrFail();
        $this->assertSame('chrome140', $node->protocol_settings['utls']['fingerprint']);

        $bad = $payload;
        $bad['id'] = $node->id;
        $bad['protocol_settings']['utls']['fingerprint'] = 'unknown_browser';
        $this->postJson($endpoint, $bad)->assertUnprocessable();
    }
}
