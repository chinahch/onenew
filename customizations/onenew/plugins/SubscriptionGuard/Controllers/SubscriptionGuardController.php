<?php
namespace Plugin\SubscriptionGuard\Controllers;

// Adapted from SubscriptionGuard 1.0.0 by XBoard Plugin.
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class SubscriptionGuardController extends Controller
{
    public function __construct(){$this->middleware('admin');}
    private function inputs(Request $request):array
    {
        return $request->validate([
            'user_id'=>'sometimes|integer|min:1','email'=>'sometimes|nullable|string|max:254',
            'ip'=>'sometimes|nullable|string|max:128','search'=>'sometimes|nullable|string|max:254',
            'page'=>'sometimes|integer|min:1|max:100000','current'=>'sometimes|integer|min:1|max:100000',
            'pageSize'=>'sometimes|integer|min:1|max:100','page_size'=>'sometimes|integer|min:1|max:100',
            'hours'=>'sometimes|integer|min:1|max:168','min_ips'=>'sometimes|integer|min:1|max:1000',
        ]);
    }
    private function listing($query,array $input)
    {
        $result=$query->paginate($input['pageSize']??$input['page_size']??20,['*'],'page',$input['current']??$input['page']??1);
        return response()->json(['data'=>$result->items(),'total'=>$result->total()])->header('Cache-Control','no-store');
    }
    public function getLogs(Request $request)
    {
        $v=$this->inputs($request);
        $q=DB::table('v2_subscribe_log as logs')->leftJoin('v2_user as users','logs.user_id','=','users.id')
            ->select('logs.id','logs.user_id','logs.ip','logs.ip_region','logs.user_agent','logs.created_at','users.email');
        if(isset($v['user_id']))$q->where('logs.user_id',$v['user_id']);
        if(!empty($v['email']))$q->where('users.email','like','%'.$v['email'].'%');
        if(!empty($v['ip']))$q->where('logs.ip','like','%'.$v['ip'].'%');
        if(!empty($v['search']))$q->where(fn($s)=>$s->where('users.email','like','%'.$v['search'].'%')->orWhere('logs.ip','like','%'.$v['search'].'%'));
        return $this->listing($q->orderByDesc('logs.id'),$v);
    }
    public function getAbuseList(Request $request)
    {
        $v=$this->inputs($request);
        $q=DB::table('v2_subscribe_abuse as abuse')->leftJoin('v2_user as users','abuse.user_id','=','users.id')
            ->select('abuse.id','abuse.user_id','abuse.unique_ip_count','abuse.ip_list','abuse.action_taken','abuse.created_at','abuse.reviewed_at','users.email','users.banned');
        if(!empty($v['email']))$q->where('users.email','like','%'.$v['email'].'%');
        if(!empty($v['search']))$q->where('users.email','like','%'.$v['search'].'%');
        return $this->listing($q->orderByDesc('abuse.created_at'),$v);
    }
    public function getUserStats(Request $request)
    {
        $v=$this->inputs($request);
        $q=DB::table('v2_subscribe_log as logs')->leftJoin('v2_user as users','logs.user_id','=','users.id')
            ->where('logs.created_at','>=',now()->subHours($v['hours']??24))->groupBy('logs.user_id','users.email','users.banned')
            ->havingRaw('COUNT(DISTINCT logs.ip) >= ?',[$v['min_ips']??2])
            ->select('logs.user_id','users.email','users.banned',DB::raw('COUNT(DISTINCT logs.ip) as unique_ips'),DB::raw('COUNT(*) as total_pulls'),DB::raw('MAX(logs.created_at) as last_pull'));
        if(!empty($v['search']))$q->where('users.email','like','%'.$v['search'].'%');
        return $this->listing($q->orderByDesc('unique_ips'),$v);
    }
    public function getUserIpDetail(Request $request)
    {
        $v=$this->inputs($request);$request->validate(['user_id'=>'required|integer|min:1']);
        $q=DB::table('v2_subscribe_log')->where('user_id',$v['user_id'])->where('created_at','>=',now()->subHours($v['hours']??24))
            ->select('ip','user_agent',DB::raw('COUNT(*) as pull_count'),DB::raw('MAX(created_at) as last_pull'))->groupBy('ip','user_agent');
        return $this->listing($q->orderByDesc('last_pull'),$v);
    }
    private function target(Request $request):User
    {
        $v=$request->validate(['user_id'=>'required|integer|min:1','confirmed'=>'required|accepted']);
        $user=User::query()->lockForUpdate()->find($v['user_id']);abort_unless($user,404);
        abort_if($user->is_admin||$user->is_staff,403,'管理账号不可由订阅防护修改');return $user;
    }
    public function banUser(Request $request)
    {
        DB::transaction(function()use($request){
            $u=$this->target($request);$u->banned=true;$u->token=bin2hex(random_bytes(16));$u->save();
            DB::table('v2_subscribe_abuse')->updateOrInsert(['user_id'=>$u->id],[
                'unique_ip_count'=>0,'ip_list'=>'[]','action_taken'=>'manual_ban','details'=>'{}','created_at'=>now(),
            ]);
        });
        return response()->json(['message'=>'用户已封禁并重置订阅']);
    }
    public function unbanUser(Request $request)
    {
        DB::transaction(function()use($request){
            $u=$this->target($request);$u->banned=false;$u->save();
            DB::table('v2_subscribe_abuse')->updateOrInsert(['user_id'=>$u->id],[
                'unique_ip_count'=>0,'ip_list'=>'[]','action_taken'=>'unbanned','details'=>'{}','created_at'=>now(),'reviewed_at'=>now(),
            ]);
            Cache::forget('sub_guard:abused:'.$u->id);
        });
        return response()->json(['message'=>'用户已解封，已确认的历史拉取不会再次触发处罚']);
    }
    public function dashboard(Request $request)
    {
        return response()->json(['data'=>[
            'today_pulls'=>DB::table('v2_subscribe_log')->where('created_at','>=',now()->startOfDay())->count(),
            'today_users'=>DB::table('v2_subscribe_log')->where('created_at','>=',now()->startOfDay())->distinct()->count('user_id'),
            'total_abuse'=>DB::table('v2_subscribe_abuse')->count(),'total_logs'=>DB::table('v2_subscribe_log')->count(),
        ]])->header('Cache-Control','no-store');
    }
    public function cleanLogs(Request $request)
    {
        $v=$request->validate(['days'=>'required|integer|min:1|max:365','confirmed'=>'required|accepted']);
        $deleted=DB::table('v2_subscribe_log')->where('created_at','<',now()->subDays($v['days']))->delete();
        return response()->json(['message'=>'已清理 '.$deleted.' 条日志']);
    }
}
