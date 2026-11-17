<?php
namespace App\Onenew;

use App\Http\Controllers\Controller;
use App\Http\Controllers\V1\User\UserController;
use App\Http\Controllers\V1\User\InviteController;
use App\Http\Controllers\V1\User\OrderController;
use App\Models\InviteCode;
use App\Models\Order;
use App\Models\User;
use App\Models\StatUser;
use App\Services\Auth\RegisterService;
use App\Services\OrderService;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Carbon\CarbonImmutable;

final class PortalController extends Controller
{
    private function subscription(Request $request): array
    {
        $response = app(UserController::class)->getSubscribe($request);
        $data = $response->getData(true);
        abort_unless($response->isSuccessful() && isset($data['data']), 422, $data['message'] ?? '无法读取订阅');
        return $data['data'];
    }

    public function me(Request $request)
    {
        $sub = $this->subscription($request);
        $user = $request->user();
        $data = ['id'=>$user->id,'email'=>$user->email,'role'=>'user','is_admin'=>(bool)$user->is_admin,
            'quota_bytes'=>$sub['transfer_enable'],'used_bytes'=>(int)$sub['u']+(int)$sub['d'],
            'expires_at'=>PortalData::iso($sub['expired_at']), 'next_reset_at'=>PortalData::iso($sub['next_reset_at'] ?? null),
            'balance_cents'=>(int)$user->balance, 'banned'=>(bool)$user->banned];
        if ($user->is_admin) {
            $path = admin_setting('secure_path', admin_setting('frontend_admin_path', hash('crc32b', config('app.key'))));
            // Only a local path can be supplied to the profile menu.
            if (is_string($path) && preg_match('~^[a-zA-Z0-9/_-]+$~', $path)) $data['admin_path']='/'.ltrim($path,'/');
        }
        return response()->json(['user'=>$data,'timezone'=>config('app.timezone','UTC')]);
    }

    private function stableLink(Request $request): string
    {
        return DB::transaction(function() use ($request) {
            $user=User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $hash=hash('sha256',$user->token);
            $record=DB::table('onenew_user_subscription_links')->where('user_id',$user->id)->first();
            if ($record && hash_equals($record->token_hash,$hash)) {
                try { return Crypt::decryptString($record->encrypted_url); } catch (\Illuminate\Contracts\Encryption\DecryptException $e) { /* app-key rotation */ }
            }
            // Helper::getSubscribeUrl can randomly choose configured domains/patterns.
            // Persist one native-generated URL until the actual subscription token changes.
            $sub=$this->subscription($request);
            $url=$sub['subscribe_url'];
            abort_unless(is_string($url) && in_array(strtolower(parse_url($url,PHP_URL_SCHEME)??''),['http','https'],true),422,'面板未提供有效的订阅地址');
            DB::table('onenew_user_subscription_links')->updateOrInsert(['user_id'=>$user->id],['token_hash'=>$hash,'encrypted_url'=>Crypt::encryptString($url),'updated_at'=>time()]);
            return $url;
        },3);
    }

    public function link(Request $request)
    {
        return response()->json(['path'=>$this->stableLink($request),'needs_recovery'=>false]);
    }

    public function rotate(Request $request)
    {
        return DB::transaction(function() use ($request) {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $response=app(UserController::class)->resetSecurity($request);
            $data=$response->getData(true);
            abort_unless($response->isSuccessful(),422,$data['message']??'重置失败');
            return response()->json(['path'=>$this->stableLink($request)]);
        },3);
    }

    public function logout(Request $request)
    {
        $token=$request->user()->currentAccessToken();
        abort_unless($token && method_exists($token,'delete'),401,'当前会话无效');
        $token->delete();
        return response()->json(['success'=>true]);
    }

    public function renewalPlan(Request $request)
    {
        $user=$request->user();
        $last=Order::where('user_id',$user->id)->where('plan_id',$user->plan_id)->where('status',3)->latest('id')->first();
        return response()->json(['plan'=>PortalData::plan($user->plan,$last?->period), 'expires_at'=>PortalData::iso($user->expired_at)]);
    }

    public function orders(Request $request)
    {
        return response()->json(Order::with('plan')->where('user_id',$request->user()->id)->latest('id')->get()->map(fn($order)=>PortalData::order($order))->values());
    }

    public function renew(Request $request)
    {
        $input=$request->validate(['request_id'=>'required|string|regex:/^[a-f0-9]{32}$/','period'=>'required|string']);
        return DB::transaction(function() use ($request,$input) {
            $user=User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $hash=hash('sha256',$input['request_id']);
            $record=DB::table('onenew_user_requests')->where('user_id',$user->id)->where('request_hash',$hash)->first();
            if ($record) {
                abort_unless($record->period===$input['period'],409,'此请求标识已用于其他续期周期');
                return response()->json(PortalData::order(Order::with('plan')->where('user_id',$user->id)->where('trade_no',$record->trade_no)->firstOrFail()));
            }
            $plan=$user->plan;
            abort_unless($plan && $user->expired_at !== null && $plan->renew,422,'当前套餐不能续期');
            $allowed=PortalData::plan($plan)['periods'];
            abort_unless(in_array($input['period'],array_column($allowed,'period'),true),422,'请选择可用的续期周期');
            $pending=Order::where('user_id',$user->id)->whereIn('status',[0,1])->first();
            abort_if($pending,409,'已有待支付或处理中的订单，请先处理或取消');
            // Native pricing reserves confirmed balance first. Only a fully covered order may be paid automatically.
            $order=OrderService::createFromRequest($user,$plan,$input['period']);
            DB::table('onenew_user_requests')->insert(['user_id'=>$user->id,'request_hash'=>$hash,'period'=>$input['period'],'trade_no'=>$order->trade_no,'created_at'=>time()]);
            if ((int)$order->total_amount === 0) {
                abort_unless((new OrderService($order))->paid($order->trade_no),500,'能量续期未完成，请重试');
                $order->refresh();
                abort_unless((int)$order->status === Order::STATUS_COMPLETED,500,'续期未生效，请重试');
            }
            return response()->json(PortalData::order($order->load('plan')));
        },3);
    }

    public function cancel(Request $request,string $trade)
    {
        $request->merge(['trade_no'=>$trade]);
        return app(OrderController::class)->cancel($request);
    }

    public function invite(Request $request)
    {
        $native=app(InviteController::class)->fetch($request)->getData(true)['data'];
        $codes=InviteCode::where('user_id',$request->user()->id)->where('status',0)->orderBy('id')->get()->map(fn($code)=>[
            'code'=>$code->code,'path'=>'/onenew-user/?invite='.rawurlencode($code->code),'created_at'=>PortalData::iso($code->created_at)]);
        $invites=User::where('invite_user_id',$request->user()->id)->select('email','created_at')->orderByDesc('created_at')->get()->map(fn($u)=>['email'=>$u->email,'created_at'=>PortalData::iso($u->created_at)]);
        return response()->json(['codes'=>$codes,'path'=>$codes->first()['path']??null,'invites'=>$invites,'invited_count'=>$native['stat'][0],'stat'=>$native['stat']]);
    }

    public function createInvite(Request $request)
    {
        return DB::transaction(function()use($request){User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();return app(InviteController::class)->save($request);});
    }

    public function verifyInvite(string $code)
    {
        return response()->json(['valid'=>!(int)admin_setting('stop_register',0)&&InviteCode::where('code',$code)->where('status',0)->exists(),
            'email_verify'=>(bool)admin_setting('email_verify',0),'captcha_enable'=>(bool)admin_setting('captcha_enable',0)]);
    }

    public function register(Request $request)
    {
        $request->validate(['email'=>'required|email:strict|max:64','password'=>'required|string|min:12','invite_code'=>'required|string|max:32']);
        return DB::transaction(function()use($request){
            $invite=InviteCode::where('code',$request->input('invite_code'))->where('status',0)->lockForUpdate()->first();
            abort_unless($invite,422,'邀请链接已失效');
            [$ok,$result]=app(RegisterService::class)->register($request);
            if(!$ok)throw new \App\Exceptions\ApiException($result[1],$result[0]);
            return response()->json(['success'=>true]);
        });
    }

    public function usage(Request $request)
    {
        $params=$request->validate(['from'=>'required|date_format:Y-m-d','to'=>'required|date_format:Y-m-d|after_or_equal:from','timezone'=>'nullable|timezone','grain'=>'required|in:day,month']);
        // StatUserJob archives at PANEL calendar midnight. These aggregate rows
        // cannot be reconstructed in another timezone; from/to are archive dates.
        $archiveZone=config('app.timezone','UTC');
        $from=CarbonImmutable::parse($params['from'],$archiveZone)->startOfDay();
        $until=CarbonImmutable::parse($params['to'],$archiveZone)->addDay()->startOfDay();
        abort_if($from->diffInDays($until)>366,422,'最多查询 366 天');
        // XB daily u/d are ALREADY billed (StatUserJob); never multiply by rate twice.
        // Monthly rows are duplicate aggregates and must not enter this sum.
        $records=StatUser::where('user_id',$request->user()->id)->where('record_type','d')->where('record_at','>=',$from->timestamp)->where('record_at','<',$until->timestamp)->orderByDesc('record_at')->get();
        $rows=[];
        foreach($records as $r){
            $date=CarbonImmutable::createFromTimestamp($r->record_at)->setTimezone($archiveZone);
            $bucket=$date->format($params['grain']==='month'?'Y-m':'Y-m-d');
            $key=$bucket.':'.$r->server_rate;
            if(!isset($rows[$key]))$rows[$key]=['bucket'=>$bucket,'node_name'=>'按倍率汇总 ×'.$r->server_rate,'up_bytes'=>0,'down_bytes'=>0,'billed_bytes'=>0,'legacy'=>false,'rate'=>$r->server_rate];
            $rows[$key]['up_bytes']+=(int)$r->u;$rows[$key]['down_bytes']+=(int)$r->d;$rows[$key]['billed_bytes']+=(int)$r->u+(int)$r->d;
        }
        return response()->json(['rows'=>array_values($rows),'updated_at'=>now()->toIso8601String(),'truncated'=>false,'billed_only'=>true,'source_timezone'=>$archiveZone,'note'=>'日期按面板归档时区 '.$archiveZone.' 查询。XB 保存每日计费上传和下载，未保存原始字节、逐节点或小时明细，不能按其他时区重建每日用量。']);
    }
}
