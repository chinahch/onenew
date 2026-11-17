<?php
namespace Tests\Feature;

use App\Http\Controllers\V2\Admin\PlanController;
use App\Http\Controllers\V2\Admin\UserController;
use App\Models\Plan;
use App\Models\User;
use App\Onenew\AiOperations\NativeAdminTools;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class NativeAdminToolsTest extends TestCase
{
    use RefreshDatabase;

    private function user(bool $admin=true): User
    {
        return User::create(['email'=>Str::random(12).'@example.test','password'=>'fixture-private-password','uuid'=>(string)Str::uuid(),'token'=>'fixture-private-token','is_admin'=>$admin,'banned'=>false]);
    }

    private function gateway(): NativeAdminTools
    {
        $router=$this->app['router'];
        $router->post('native-fixture/plan/save',[PlanController::class,'save'])->middleware('admin');
        $router->post('native-fixture/user/update',[UserController::class,'update'])->middleware('admin');
        $router->get('native-fixture/user/fetch',[UserController::class,'fetch'])->middleware('admin');
        $router->get('native-fixture/unsafe-get',[UserController::class,'resetSecret'])->middleware('admin');
        $router->post('native-fixture/public',[PlanController::class,'save']);
        $router->get('native-fixture/external','External\\AdminController@fetch')->middleware('admin');
        return app(NativeAdminTools::class);
    }

    private function capability(NativeAdminTools $tools,string $path): string
    {
        $catalog=$tools->catalog(['keyword'=>'native-fixture','limit'=>30]);
        foreach($catalog['items'] as $entry)if($entry['path']===$path)return $entry['capability'];
        self::fail('Fixture capability missing: '.$path);
    }

    public function test_catalog_is_bounded_and_forbids_public_external_and_arbitrary_get_reads(): void
    {
        $tools=$this->gateway(); $admin=$this->user();
        self::assertLessThanOrEqual(30,count($tools->catalog(['limit'=>999])['items']));
        $paths=array_column($tools->catalog(['keyword'=>'native-fixture','limit'=>30])['items'],'path');
        self::assertNotContains('native-fixture/public',$paths);self::assertNotContains('native-fixture/external',$paths);
        try{$tools->read($admin->id,['capability'=>$this->capability($tools,'native-fixture/unsafe-get'),'params'=>[]]);self::fail('Side-effecting GET accepted.');}catch(HttpException $e){self::assertSame(422,$e->getStatusCode());}
        try{$tools->prepare($admin->id,['capability'=>str_repeat('0',64),'params'=>[]]);self::fail();}catch(HttpException $e){self::assertSame(422,$e->getStatusCode());}
    }

    public function test_native_plan_form_request_prepare_does_not_write_and_execution_replays_once(): void
    {
        $tools=$this->gateway();$admin=$this->user();$capability=$this->capability($tools,'native-fixture/plan/save');
        $before=Plan::count();$card=$tools->prepare($admin->id,['capability'=>$capability,'params'=>['name'=>'Native fixture','transfer_enable'=>10]]);
        self::assertSame($before,Plan::count());self::assertSame('native.admin',$card['kind']);
        $request=(string)Str::uuid();$result=$tools->execute($admin->id,$card['id'],$request);
        self::assertSame('succeeded',$result['status']);self::assertSame($before+1,Plan::count());
        self::assertSame($result,$tools->execute($admin->id,$card['id'],$request));self::assertSame($before+1,Plan::count());
        self::assertSame('succeeded',DB::table('onenew_ai_native_actions')->where('id',$card['id'])->value('status'));
    }

    public function test_native_validation_rejects_invalid_plan_without_mutation(): void
    {
        $tools=$this->gateway();$admin=$this->user();$before=Plan::count();
        $card=$tools->prepare($admin->id,['capability'=>$this->capability($tools,'native-fixture/plan/save'),'params'=>['transfer_enable'=>-1]]);
        $result=$tools->execute($admin->id,$card['id'],(string)Str::uuid());
        self::assertContains($result['status'],['failed','uncertain']);self::assertSame($before,Plan::count());
    }

    public function test_native_user_form_request_and_request_guard_restoration(): void
    {
        $tools=$this->gateway();$admin=$this->user();$target=$this->user(false);$original=$this->app['request'];
        $guard=$this->app['auth']->guard('sanctum');$guard->setUser($target);
        $card=$tools->prepare($admin->id,['capability'=>$this->capability($tools,'native-fixture/user/update'),'params'=>['id'=>$target->id,'expired_at'=>time()+3600]]);
        $result=$tools->execute($admin->id,$card['id'],(string)Str::uuid());
        self::assertSame('succeeded',$result['status']);self::assertGreaterThan(time(),$target->fresh()->expired_at);
        self::assertSame($target,$guard->user());self::assertSame($original,$this->app['request']);
        $read=$tools->read($admin->id,['capability'=>$this->capability($tools,'native-fixture/user/fetch'),'params'=>['pageSize'=>999]]);
        $json=json_encode($read);foreach(['fixture-private-password','fixture-private-token',$target->uuid,$target->email] as $secret)self::assertStringNotContainsString($secret,$json);
        self::assertLessThanOrEqual(20000,strlen($json));
    }

    public function test_non_admin_revocation_ownership_request_identity_and_credentials_are_rejected(): void
    {
        $tools=$this->gateway();$admin=$this->user();$other=$this->user();$normal=$this->user(false);$args=['capability'=>$this->capability($tools,'native-fixture/plan/save'),'params'=>['name'=>'Owned','transfer_enable'=>1]];
        foreach(['read','prepare'] as $method){try{$tools->$method($normal->id,$args);self::fail();}catch(HttpException $e){self::assertSame(403,$e->getStatusCode());}}
        foreach(['password','private_key','is_admin','smtp_pass'] as $key){try{$tools->prepare($admin->id,['capability'=>$args['capability'],'params'=>[$key=>'secret']]);self::fail();}catch(HttpException $e){self::assertSame(422,$e->getStatusCode());}}
        $card=$tools->prepare($admin->id,$args);
        try{$tools->execute($other->id,$card['id'],(string)Str::uuid());self::fail();}catch(HttpException $e){self::assertSame(404,$e->getStatusCode());}
        $admin->is_admin=false;$admin->save();
        try{$tools->execute($admin->id,$card['id'],(string)Str::uuid());self::fail();}catch(HttpException $e){self::assertSame(403,$e->getStatusCode());}
    }

    public function test_reserved_unknown_outcome_never_retries_and_uuid_cannot_change(): void
    {
        $tools=$this->gateway();$admin=$this->user();$before=Plan::count();
        $card=$tools->prepare($admin->id,['capability'=>$this->capability($tools,'native-fixture/plan/save'),'params'=>['name'=>'No retry','transfer_enable'=>1]]);
        $request=(string)Str::uuid();DB::table('onenew_ai_native_actions')->where('id',$card['id'])->update(['status'=>'dispatching','request_id'=>$request]);
        self::assertSame('uncertain',$tools->execute($admin->id,$card['id'],$request)['status']);self::assertSame($before,Plan::count());
        try{$tools->execute($admin->id,$card['id'],(string)Str::uuid());self::fail();}catch(HttpException $e){self::assertSame(409,$e->getStatusCode());}
    }
}
