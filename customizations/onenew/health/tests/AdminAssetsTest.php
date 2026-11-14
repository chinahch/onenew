<?php
namespace Tests\Feature\Onenew;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAssetsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_html_uses_the_current_fingerprinted_bundle_without_html_caching(): void
    {
        $secure = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
        $response = $this->get('/' . $secure)->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $manifest = json_decode(file_get_contents(public_path('assets/admin/manifest.json')), true);
        $entry = $manifest['index.html'];
        $this->assertMatchesRegularExpression('~^assets/onenew-[a-f0-9]{24}\.js$~', $entry['file']);
        $response->assertSee('/assets/admin/' . $entry['file'], false);
        $this->assertFileExists(public_path('assets/admin/' . $entry['file']));
        $this->assertStringNotContainsString('index-CEIYH7i8.js', $response->getContent());
    }
}
