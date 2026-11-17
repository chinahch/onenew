<?php
namespace App\Onenew\Energy;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
final class EnergyController
{
    private const FIELDS=['e.id','e.user_id','e.amount_cents','e.status','e.created_at','e.reviewed_at','e.reviewer_id','e.note','e.balance_before','e.balance_after'];
    public function attention()
    {
        return response()->json([
            'orders'=>\App\Models\Order::whereIn('status',[0,1])->count(),
            'energy'=>DB::table('onenew_energy_requests')->where('status','pending')->count(),
        ])->header('Cache-Control','no-store');
    }
    public function index(Request $r)
    {
        $r->validate(['page'=>'sometimes|integer|min:1']);
        return response()->json(DB::table('onenew_energy_requests as e')->where('user_id',Auth::guard('sanctum')->id())->select(self::FIELDS)->orderByDesc('created_at')->orderByDesc('id')->paginate(20))->header('Cache-Control','no-store');
    }
    public function create(Request $r)
    {
        $p=$r->validate(['amount'=>'required|string|max:20','request_id'=>'required|string|regex:/^[a-f0-9]{32}$/D']);
        try{$amount=Amount::parse($p['amount']);}catch(\InvalidArgumentException $e){abort(422,$e->getMessage());}
        $row=DB::transaction(function()use($amount,$p){
            $user=User::whereKey(Auth::guard('sanctum')->id())->lockForUpdate()->firstOrFail();
            $hash=hash('sha256',$p['request_id']);$q=DB::table('onenew_energy_requests');
            $old=(clone $q)->where('user_id',$user->id)->where('request_hash',$hash)->first();
            if($old){abort_unless((int)$old->amount_cents===$amount,409,'此请求已用于其他数量');return $old;}
            abort_if((clone $q)->where('user_id',$user->id)->where('status','pending')->count()>=5,409,'请先处理已有补充申请');
            $id=(string)Str::uuid();$q->insert(['id'=>$id,'user_id'=>$user->id,'request_hash'=>$hash,'amount_cents'=>$amount,'status'=>'pending','created_at'=>time(),'note'=>'']);
            return $q->where('id',$id)->first();
        },3);
        unset($row->request_hash);return response()->json($row)->header('Cache-Control','no-store');
    }
    public function cancel(string $id)
    {
        abort_unless(Str::isUuid($id),404);
        return DB::transaction(function()use($id){
            $q=DB::table('onenew_energy_requests')->where('id',$id)->where('user_id',Auth::guard('sanctum')->id());$row=$q->lockForUpdate()->first();abort_unless($row,404);
            abort_unless(in_array($row->status,['pending','cancelled'],true),409,'该申请已完成审核');
            if($row->status==='pending')$q->update(['status'=>'cancelled','reviewed_at'=>time()]);
            return response()->json(['status'=>'cancelled']);
        },3);
    }
    public function adminIndex(Request $r)
    {
        $p=$r->validate(['page'=>'sometimes|integer|min:1','status'=>'sometimes|in:pending,approved,rejected,cancelled','search'=>'sometimes|nullable|string|max:255']);
        $q=DB::table('onenew_energy_requests as e')->leftJoin('v2_user as u','u.id','=','e.user_id')->select([...self::FIELDS,'u.email']);
        if(!empty($p['status']))$q->where('e.status',$p['status']);
        if(!empty($p['search']))$q->where('u.email','like','%'.$p['search'].'%');
        return response()->json($q->orderByDesc('e.created_at')->orderByDesc('e.id')->paginate(20))->header('Cache-Control','no-store');
    }
    public function review(Request $r,string $id)
    {
        abort_unless(Str::isUuid($id),404);$p=$r->validate(['decision'=>'required|in:approved,rejected','note'=>'nullable|string|max:500']);
        return DB::transaction(function()use($id,$p){
            $q=DB::table('onenew_energy_requests')->where('id',$id);$row=$q->lockForUpdate()->first();abort_unless($row,404);
            if($row->status!== 'pending'){abort_unless($row->status===$p['decision'],409,'申请已取消或按其他结果处理');return response()->json(['status'=>$row->status]);}
            $user=User::whereKey($row->user_id)->lockForUpdate()->firstOrFail();$before=(int)$user->balance;$after=$before;
            if($p['decision']==='approved'){
                abort_unless($before>=0&&$before<=2147483647-(int)$row->amount_cents,422,'账户能量已达上限');
                $after=$before+(int)$row->amount_cents;$user->balance=$after;$user->saveOrFail();
            }
            $q->update(['status'=>$p['decision'],'reviewed_at'=>time(),'reviewer_id'=>Auth::guard('sanctum')->id(),'note'=>$p['note']??'','balance_before'=>$before,'balance_after'=>$after]);
            return response()->json(['status'=>$p['decision']]);
        },3);
    }
}
