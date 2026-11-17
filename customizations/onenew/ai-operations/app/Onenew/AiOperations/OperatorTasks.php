<?php
namespace App\Onenew\AiOperations;

use Illuminate\Support\Facades\{Crypt,DB,Schema};
use Illuminate\Support\Str;

final class OperatorTasks
{
    private const TABLE='onenew_ai_operator_tasks';
    public function __construct(private Config $config){}
    private function schema():void {abort_unless(Schema::hasTable(self::TABLE),503,'AI 操作任务尚未初始化，请完成数据库迁移。');}
    public function fingerprint(?array $row=null):string
    {
        $row??=$this->config->row();$values=[];foreach(['enabled','endpoint','model','token_parameter','encrypted_key','encrypted_repo_key'] as $field)$values[$field]=$row[$field]??null;
        return hash('sha256',json_encode($values,JSON_THROW_ON_ERROR));
    }
    public function current(string $fingerprint):array
    {
        $row=$this->config->row();if(!$row['enabled']||empty($row['encrypted_key'])||!hash_equals($fingerprint,$this->fingerprint($row)))throw new \RuntimeException('configuration_changed');return $row;
    }
    public function create(int $admin,array $input):array
    {
        $this->schema();$prior=DB::table(self::TABLE)->where('admin_id',$admin)->where('request_id',$input['request_id'])->first();if($prior)return $this->view($admin,$prior->id);
        $row=$this->config->row();abort_unless($row['enabled']&&!empty($row['encrypted_key'])&&$row['endpoint']!==''&&$row['model']!=='',422,'请先启用并配置 AI 服务。');
        $id=(string)Str::uuid();$payload=['message'=>$input['message'],'history'=>$input['history']??[],'page_path'=>$input['page_path']??'','config_fingerprint'=>$this->fingerprint($row)];
        $inserted=DB::table(self::TABLE)->insertOrIgnore(['id'=>$id,'admin_id'=>$admin,'request_id'=>$input['request_id'],'state'=>'queued','encrypted_payload'=>Crypt::encryptString(json_encode($payload,JSON_THROW_ON_ERROR)),'created_at'=>time(),'expires_at'=>time()+86400,'progress'=>'等待后台任务']);
        if(!$inserted){$prior=DB::table(self::TABLE)->where('admin_id',$admin)->where('request_id',$input['request_id'])->first();abort_unless($prior,409,'任务无法创建，请稍后重试。');return $this->view($admin,$prior->id);}
        try{OperatorJob::dispatch($id);}catch(\Throwable $e){$this->fail($id);}
        return $this->view($admin,$id);
    }
    public function view(int $admin,string $id):array
    {
        $this->schema();$row=DB::table(self::TABLE)->where('id',$id)->where('admin_id',$admin)->first();abort_unless($row&&$row->expires_at>time(),404,'任务不存在或已过期。');
        if($row->state==='running'&&$row->started_at<time()-300){$this->fail($id);$row=DB::table(self::TABLE)->where('id',$id)->first();}
        $out=['id'=>$row->id,'state'=>$row->state,'progress'=>$row->progress];
        if($row->state==='done'&&is_string($row->encrypted_result))$out['result']=json_decode(Crypt::decryptString($row->encrypted_result),true,64,JSON_THROW_ON_ERROR);
        if($row->state==='failed')$out['error']=$row->error??'AI 后台任务失败，未自动重试。';return $out;
    }
    public function progress(string $id,string $message):void {DB::table(self::TABLE)->where('id',$id)->where('state','running')->update(['progress'=>mb_substr($message,0,180)]);}
    public function run(string $id):void
    {
        $row=DB::table(self::TABLE)->where('id',$id)->first();if(!$row)return;
        if($row->state==='running'&&$row->started_at<time()-300){$this->fail($id);return;}
        if($row->expires_at<=time()){$this->fail($id);return;}
        if(!DB::table(self::TABLE)->where('id',$id)->where('state','queued')->update(['state'=>'running','started_at'=>time(),'progress'=>'正在准备安全上下文']))return;
        try{
            $payload=json_decode(Crypt::decryptString($row->encrypted_payload),true,64,JSON_THROW_ON_ERROR);$this->current($payload['config_fingerprint']);
            $result=app(OperatorEngine::class)->run((int)$row->admin_id,$payload,fn(string $message)=>$this->progress($id,$message));
            DB::table(self::TABLE)->where('id',$id)->where('state','running')->update(['state'=>'done','encrypted_payload'=>null,'encrypted_result'=>Crypt::encryptString(json_encode($result,JSON_THROW_ON_ERROR)),'progress'=>'已完成；待确认的操作尚未执行','finished_at'=>time()]);
        }catch(\Throwable $e){$this->fail($id);}
    }
    public function fail(string $id):void {DB::table(self::TABLE)->where('id',$id)->whereIn('state',['queued','running'])->update(['state'=>'failed','encrypted_payload'=>null,'encrypted_result'=>null,'error'=>'AI 后台任务失败或设置已变化，未自动重试。请检查设置后重新发送。','progress'=>'任务已停止','finished_at'=>time()]);}
    public function prune():void
    {
        if(!Schema::hasTable(self::TABLE))return;
        DB::table(self::TABLE)->where('state','running')->where('started_at','<',time()-300)->update(['state'=>'failed','encrypted_payload'=>null,'error'=>'任务超时，未自动重试。','finished_at'=>time(),'progress'=>'任务已停止']);
        DB::table(self::TABLE)->where('state','queued')->where('created_at','<',time()-900)->update(['state'=>'failed','encrypted_payload'=>null,'error'=>'后台队列未及时启动，请检查后台任务服务。','finished_at'=>time(),'progress'=>'任务已停止']);
        DB::table(self::TABLE)->where('expires_at','<=',time())->delete();
    }
}
