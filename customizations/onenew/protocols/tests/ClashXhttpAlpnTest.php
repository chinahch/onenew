<?php

namespace Tests\Feature\Onenew;

use App\Models\Server;
use App\Protocols\ClashMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ClashXhttpAlpnTest extends TestCase
{
    use RefreshDatabase;

    private function build(string|array|null $alpn): array
    {
        $node = Server::create(['name' => 'XHTTP H3 fixture', 'type' => 'vless', 'host' => '2001:db8::5',
            'port' => 25000, 'server_port' => 25000, 'rate' => 1, 'protocol_settings' => array_filter([
                'tls' => 1,
                'tls_settings' => ['server_name' => 'edge.example'],
                'network' => 'xhttp',
                'network_settings' => array_filter(['path' => '/h3', 'host' => 'edge.example', 'mode' => 'auto', 'alpn' => $alpn],
                    static fn ($value) => $value !== null),
            ])]);
        return ClashMeta::buildVless('00000000-0000-4000-8000-000000000001', array_replace($node->toArray(), [
            'protocol_settings' => $node->protocol_settings,
        ]));
    }

    public function test_explicit_h3_and_ordered_alpn_lists_are_emitted_at_proxy_level(): void
    {
        $this->assertSame(['h3'], $this->build('h3')['alpn']);
        $this->assertSame(['h2', 'http/1.1'], $this->build(['h2', 'http/1.1'])['alpn']);
        $this->assertSame(['h2', 'http/1.1'], $this->build('h2,http/1.1')['alpn']);
    }

    public function test_absent_alpn_is_not_silently_changed_to_h3(): void
    {
        $this->assertArrayNotHasKey('alpn', $this->build(null));
    }

    public function test_h3_setting_does_not_change_ws_or_non_vless_exporters(): void
    {
        $h3 = $this->build('h3');
        $this->assertSame('xhttp', $h3['network']);
        $this->assertSame('/h3', $h3['xhttp-opts']['path']);
        $this->assertSame('edge.example', $h3['xhttp-opts']['host']);
        $this->assertSame('auto', $h3['xhttp-opts']['mode']);
        $this->assertArrayNotHasKey('alpn', ClashMeta::buildVless('00000000-0000-4000-8000-000000000001', [
            'name' => 'ws', 'host' => 'edge.example', 'port' => 443,
            'protocol_settings' => ['tls' => 1, 'network' => 'ws', 'network_settings' => ['alpn' => 'h3']],
        ]));
    }
}
