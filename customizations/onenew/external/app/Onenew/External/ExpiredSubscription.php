<?php
namespace App\Onenew\External;

use App\Protocols\General;
use Illuminate\Http\Request;

final class ExpiredSubscription
{
    public const LINK = 'ss://YWVzLTI1Ni1nY206ZXhwaXJlZA@127.0.0.1:65535?#%E8%AE%A2%E9%98%85%E5%B7%B2%E8%BF%87%E6%9C%9F';

    public static function response(Request $request, $user): mixed
    {
        $flag = strtolower((string)($request->input('flag') ?: $request->userAgent()));
        $class = app('protocols.manager')->matchProtocolClassName($flag) ?? General::class;
        $node = ['id'=>0,'type'=>'shadowsocks','name'=>'订阅已过期','host'=>'127.0.0.1','port'=>65535,
            'password'=>'expired','protocol_settings'=>['cipher'=>'aes-256-gcm'],'rate'=>1,'tags'=>[]];
        if ($class === General::class) {
            $response = response(base64_encode(self::LINK."\r\n"))->header('Content-Type','text/plain');
        } else {
            $response = app()->make($class,['user'=>$user,'servers'=>[$node]])->handle();
        }
        return $response->header('Cache-Control','no-store')
            ->header('subscription-userinfo',"upload={$user->u}; download={$user->d}; total={$user->transfer_enable}; expire={$user->expired_at}");
    }
}
