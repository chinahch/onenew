<?php
namespace Plugin\SubscriptionGuard;

// Based on SubscriptionGuard 1.0.0 by XBoard Plugin; Onenew hardening below.
use App\Models\User;
use App\Services\Plugin\AbstractPlugin;
use App\Services\TelegramService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
require_once __DIR__.'/GuardPolicy.php';

class Plugin extends AbstractPlugin
{
    public function setConfig(array $config):void
    {
        new GuardPolicy($config);
        parent::setConfig($config);
    }
    public function boot():void
    {
        new GuardPolicy($this->getConfig());
        $this->listen('client.subscribe.before',[$this,'onSubscribeBefore'],5);
    }
    public function schedule(\Illuminate\Console\Scheduling\Schedule $schedule):void
    {
        $days=(new GuardPolicy($this->getConfig()))->retentionDays;
        $schedule->call(fn()=>$this->cleanupOldLogs($days))->name('subscription-guard:cleanup')->dailyAt('03:00')->withoutOverlapping();
    }
    public function onSubscribeBefore():void
    {
        $request=request();$user=$request->user();
        // Management accounts are always protected, including with old stored configs.
        if(!$user instanceof User||$user->is_admin||$user->is_staff||$user->banned)return;
        $ip=GuardPolicy::canonical((string)$request->ip());if($ip===null)return;
        $lock=null;$acquired=false;$abuseClaim=false;$rateClaim=false;$decision=null;
        $abuseKey='sub_guard:abused:'.$user->id;
        $rateKey='sub_guard:rate:'.$user->id.':'.hash('sha256',$ip);
        try{
            $policy=new GuardPolicy($this->getConfig());if($policy->whitelisted($ip))return;
            $lock=Cache::lock('sub_guard:user:'.$user->id,15);$acquired=$lock->get();if(!$acquired)return;
            if($policy->rateSeconds>0){
                if(!Cache::add($rateKey,1,$policy->rateSeconds))return;
                $rateClaim=true;
            }
            $decision=DB::transaction(function()use($user,$ip,$request,$policy,$abuseKey,&$abuseClaim){
                $fresh=User::query()->lockForUpdate()->find($user->id);
                if(!$fresh||$fresh->is_admin||$fresh->is_staff||$fresh->banned)return null;
                DB::table('v2_subscribe_log')->insert([
                    'user_id'=>$fresh->id,'ip'=>$ip,'ip_region'=>'',
                    'user_agent'=>GuardPolicy::safeAgent((string)$request->header('User-Agent',''),(string)$fresh->token),
                    'created_at'=>now(),
                ]);
                $since=now()->subHours($policy->windowHours);
                $review=DB::table('v2_subscribe_abuse')->where('user_id',$fresh->id)->value('reviewed_at');
                $reviewed=$review&&\Carbon\Carbon::parse($review)->greaterThan($since);
                if($reviewed)$since=\Carbon\Carbon::parse($review);
                $ips=DB::table('v2_subscribe_log')->where('user_id',$fresh->id)->where('created_at',$reviewed?'>':'>=',$since)->distinct()->limit(10001)->pluck('ip')->all();
                $ips=$policy->distinctIps($ips);$count=count($ips);
                if($count<=$policy->maxIps||!Cache::add($abuseKey,1,3600))return null;
                $abuseClaim=true;
                DB::table('v2_subscribe_abuse')->updateOrInsert(['user_id'=>$fresh->id],[
                    'unique_ip_count'=>$count,'ip_list'=>json_encode($ips,JSON_THROW_ON_ERROR),
                    'action_taken'=>$policy->action,'details'=>json_encode(['window_hours'=>$policy->windowHours],JSON_THROW_ON_ERROR),
                    'created_at'=>now(),
                ]);
                if($policy->action!=='alert_only'){
                    if($policy->action==='auto_ban')$fresh->banned=true;
                    $fresh->token=bin2hex(random_bytes(16));
                    $fresh->remarks=($fresh->remarks?$fresh->remarks."\n":'').'['.now()->format('Y-m-d H:i').'] SubscriptionGuard: '.$policy->action.' (IP: '.$count.')';
                    $fresh->save();
                }
                return ['user_id'=>$fresh->id,'count'=>$count,'action'=>$policy->action,'notify'=>$policy->notify];
            });
        }catch(\Throwable $error){
            try{if($abuseClaim)Cache::forget($abuseKey);if($rateClaim)Cache::forget($rateKey);}catch(\Throwable $ignored){}
            // Never log exception messages: they may contain SQL, headers or secrets.
            Log::warning('[SubscriptionGuard] Guard unavailable; subscription unchanged',[
                'user_id'=>$user->id,'error_type'=>get_class($error),
                'error_source'=>basename($error->getFile()),'error_line'=>$error->getLine(),
            ]);
            return;
        }finally{
            if($lock&&$acquired){try{$lock->release();}catch(\Throwable $ignored){}}
        }
        if(!$decision)return;
        Log::warning('[SubscriptionGuard] Unique IP threshold exceeded',[
            'user_id'=>$decision['user_id'],'ip_count'=>$decision['count'],'action'=>$decision['action'],
        ]);
        if($decision['notify']){
            try{(new TelegramService())->sendMessageWithAdmin('SubscriptionGuard: user #'.$decision['user_id'].', IP count '.$decision['count'].', action '.$decision['action']);}
            catch(\Throwable $error){Log::warning('[SubscriptionGuard] Notification failed');}
        }
        // Interception is after the committed transaction and released lock.
        if($decision['action']!=='alert_only')$this->intercept(response('',403,['Content-Type'=>'text/plain']));
    }
    public function cleanupOldLogs(int $days):void
    {
        if($days<1||$days>365)throw new \InvalidArgumentException('Retention must be 1–365 days');
        $before=now()->subDays($days);
        DB::table('v2_subscribe_log')->where('created_at','<',$before)->delete();
        DB::table('v2_subscribe_abuse')->where('created_at','<',$before)->delete();
    }
}
