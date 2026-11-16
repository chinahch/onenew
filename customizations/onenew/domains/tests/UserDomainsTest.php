<?php
namespace Tests\Feature;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use App\Http\Controllers\V2\Admin\ConfigController;
use App\Http\Requests\Admin\ConfigSave;
final class UserDomainsTest extends TestCase
{
    use RefreshDatabase;
    public function test_save_reload_clear_and_subscription_remains_independent(): void
    {
        admin_setting(['subscribe_url'=>'https://subscription.example','app_url'=>'https://admin.example']);
        $data=['user_frontend_urls'=>['https://A.example/','https://a.example:443','https://b.example']];
        $request=\Mockery::mock(ConfigSave::class)->makePartial();
        $request->shouldReceive('validated')->andReturn($data);
        $controller=app(ConfigController::class);$controller->save($request);
        $site=$controller->fetch(Request::create('/','GET',['key'=>'site']))->getData(true)['data']['site'];
        self::assertSame(['https://a.example','https://b.example'],$site['user_frontend_urls']);
        self::assertSame('https://subscription.example',$site['subscribe_url']);
        self::assertSame('https://admin.example',$site['app_url']);
        $request=\Mockery::mock(ConfigSave::class)->makePartial();
        $request->shouldReceive('validated')->andReturn(['user_frontend_urls'=>[]]);$controller->save($request);
        self::assertSame([],admin_setting('user_frontend_urls'));
    }
    public function test_native_request_validation_rejects_malformed_origins(): void
    {
        foreach(['https://a.example/path','https://u:p@a.example','javascript:alert(1)','https://*.example.com'] as $value) {
            $request=ConfigSave::create('/','POST',['user_frontend_urls'=>[$value]]);
            $validator=validator($request->all(),$request->rules());$request->withValidator($validator);
            self::assertTrue($validator->fails());
        }
        $request=ConfigSave::create('/','POST',['user_frontend_urls'=>[]]);
        $validator=validator($request->all(),$request->rules());$request->withValidator($validator);self::assertFalse($validator->fails());
    }
}
