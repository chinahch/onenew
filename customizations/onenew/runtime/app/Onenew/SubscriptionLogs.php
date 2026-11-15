<?php
namespace App\Onenew;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
final class SubscriptionLogs
{
    public function users(Request $r)
    {
        $p=$r->validate(['search'=>'sometimes|nullable|string|max:254']);
        if(!Schema::hasTable('v2_subscribe_log'))return response()->json(['data'=>[],'available'=>false]);
        $latest=DB::table('v2_subscribe_log')->select('user_id',DB::raw('MAX(created_at) as last_pull'))->groupBy('user_id');
        $q=DB::table('v2_user as u')->joinSub($latest,'logs','logs.user_id','=','u.id')->select('u.id','u.email','logs.last_pull');
        if(!empty($p['search']))$q->where('u.email','like','%'.$p['search'].'%');
        return response()->json(['data'=>$q->orderByDesc('logs.last_pull')->limit(50)->get(),'available'=>true])->header('Cache-Control','no-store');
    }
    public function index(Request $r)
    {
        $p=$r->validate(['user_id'=>'sometimes|integer|min:1|exists:v2_user,id','page'=>'sometimes|integer|min:1','search'=>'sometimes|nullable|string|max:254']);
        if(empty($p['user_id'])||!Schema::hasTable('v2_subscribe_log'))return response()->json(['data'=>[],'total'=>0,'current_page'=>1,'last_page'=>1])->header('Cache-Control','no-store');
        $q=DB::table('v2_subscribe_log')->where('user_id',$p['user_id'])->select('id','user_id','ip','user_agent','created_at');
        if(!empty($p['search']))$q->where(fn($q)=>$q->where('ip','like','%'.$p['search'].'%')->orWhere('user_agent','like','%'.$p['search'].'%'));
        return response()->json($q->orderByDesc('id')->paginate(20))->header('Cache-Control','no-store');
    }
}
