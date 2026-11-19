<?php

namespace Tests\Feature\Onenew;

use App\Http\Controllers\V2\Admin\Server\ManageController;
use App\Models\Server;
use App\Models\User;
use App\Onenew\Protocols\TlsFragment;
use App\Protocols\ClashMeta;
use App\Protocols\General;
use App\Protocols\Shadowrocket;
use App\Services\ServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TlsFragmentTest extends TestCase
{
    use RefreshDatabase;

    private function settings(bool $enabled = true): array
    {
        return ['tls' => 1, 'network' => 'tcp', 'utls' => ['enabled' => true, 'fingerprint' => 'chrome'],
            'tls_settings' => ['server_name' => 'tls.example',
            'fragment' => ['enabled' => $enabled, 'length' => '100-200', 'interval' => '10-20']]];
    }

    private function query(string $link): array
    {
        parse_str(parse_url(trim($link), PHP_URL_QUERY) ?: '', $query);
        return $query;
    }

    public function test_database_roundtrip_shadowrocket_exports_and_other_clients_are_independent(): void
    {
        foreach (['vmess', 'vless', 'trojan'] as $type) {
            $node = Server::create(['name' => 'fragment-'.$type, 'type' => $type, 'host' => 'node.example',
                'port' => 443, 'server_port' => 443, 'rate' => 1, 'protocol_settings' => $this->settings()])->fresh();
            $server = $node->toArray();
            $this->assertTrue($server['protocol_settings']['tls_settings']['fragment']['enabled']);
            $method = 'build'.ucfirst($type);
            $password = '00000000-0000-4000-8000-000000000001';
            $query = $this->query(Shadowrocket::$method($password, $server));
            $this->assertSame('1,100-200,10-20', $query['reserved']);
            $without = $server;
            unset($without['protocol_settings']['tls_settings']['fragment']);
            $this->assertSame(General::$method($password, $without), General::$method($password, $server));
            $this->assertSame(ClashMeta::$method($password, $without), ClashMeta::$method($password, $server));
            $config = ServerService::buildNodeConfig($node);
            $this->assertArrayNotHasKey('fragment', $config['tls_settings']);
            $node->protocol_settings = $this->settings(false);
            $node->save();
            $this->assertSame(Shadowrocket::$method($password, $without), Shadowrocket::$method($password, $node->fresh()->toArray()));
        }
    }

    public function test_fragment_switch_is_saved_through_the_real_admin_endpoint_and_validated(): void
    {
        Sanctum::actingAs(User::create(['email' => Str::uuid().'@example.test', 'password' => 'unused',
            'uuid' => (string) Str::uuid(), 'token' => bin2hex(random_bytes(16)), 'is_admin' => true]));
        $route = collect(app('router')->getRoutes()->getRoutes())->first(fn ($route) =>
            $route->getActionName() === ManageController::class.'@save');
        $this->assertNotNull($route);
        $endpoint = '/'.$route->uri();
        $payload = ['name' => 'fragment-http', 'type' => 'vless', 'host' => 'node.example',
            'port' => 443, 'server_port' => 443, 'rate' => 1, 'group_ids' => [], 'protocol_settings' => $this->settings()];
        $this->postJson($endpoint, $payload)->assertOk();
        $node = Server::where('name', 'fragment-http')->firstOrFail();
        $this->assertTrue($node->protocol_settings['tls_settings']['fragment']['enabled']);
        foreach ([['length', '0'], ['length', '200-100'], ['length', '16385'], ['interval', '-1'], ['interval', '1001']] as [$field, $value]) {
            $invalid = $payload;
            $invalid['id'] = $node->id;
            $invalid['protocol_settings']['tls_settings']['fragment'][$field] = $value;
            $this->postJson($endpoint, $invalid)->assertUnprocessable();
        }
        $payload['id'] = $node->id;
        $payload['protocol_settings'] = $this->settings(false);
        $this->postJson($endpoint, $payload)->assertOk();
        $this->assertFalse($node->fresh()->protocol_settings['tls_settings']['fragment']['enabled']);
    }

    public function test_non_tls_quic_and_invalid_ranges_never_emit_a_shadowrocket_fragment_flag(): void
    {
        $server = ['type' => 'vless', 'protocol_settings' => $this->settings()];
        foreach ([['tls', 0], ['network', 'quic']] as [$field, $value]) {
            $candidate = $server;
            $candidate['protocol_settings'][$field] = $value;
            $query = [];
            TlsFragment::appendShadowrocket($query, $candidate);
            $this->assertSame([], $query);
        }
        $server['protocol_settings']['tls_settings']['fragment']['length'] = '1,2,3';
        $query = [];
        TlsFragment::appendShadowrocket($query, $server);
        $this->assertSame([], $query);
    }
}
