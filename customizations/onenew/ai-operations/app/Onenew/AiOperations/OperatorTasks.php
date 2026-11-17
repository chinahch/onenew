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
        try{OperatorJob::dispatch($id);}catch(\Throwable $e){$this->fail($id,$e,'queue_failed');}
        return $this->view($admin,$id);
    }
    public function view(int $admin,string $id):array
    {
        $this->schema();$row=DB::table(self::TABLE)->where('id',$id)->where('admin_id',$admin)->first();abort_unless($row&&$row->expires_at>time(),404,'任务不存在或已过期。');
        if($row->state==='running'&&$row->started_at<time()-300){$this->fail($id,null,'task_timeout');$row=DB::table(self::TABLE)->where('id',$id)->first();}
        $out=['id'=>$row->id,'state'=>$row->state,'progress'=>$row->progress];
        if($row->state==='done'&&is_string($row->encrypted_result))$out['result']=json_decode(Crypt::decryptString($row->encrypted_result),true,64,JSON_THROW_ON_ERROR);
        if($row->state==='failed'){
            $failure=self::failure();foreach(self::FAILURES as $code=>$entry)if(($row->error??null)===$entry[1]){$failure=['code'=>$code,'stage'=>$entry[0],'message'=>$entry[1]];break;}
            $out['error']=$failure['message'];$out['error_code']=$failure['code'];$out['error_stage']=$failure['stage'];
        }return $out;
    }
    public function progress(string $id,string $message):void {DB::table(self::TABLE)->where('id',$id)->where('state','running')->update(['progress'=>mb_substr($message,0,180)]);}
    public function run(string $id):void
    {
        $row=DB::table(self::TABLE)->where('id',$id)->first();if(!$row)return;
        if($row->state==='running'&&$row->started_at<time()-300){$this->fail($id,null,'task_timeout');return;}
        if($row->expires_at<=time()){$this->fail($id,null,'task_expired');return;}
        if(!DB::table(self::TABLE)->where('id',$id)->where('state','queued')->update(['state'=>'running','started_at'=>time(),'progress'=>'正在准备安全上下文']))return;
        try{
            $payload=json_decode(Crypt::decryptString($row->encrypted_payload),true,64,JSON_THROW_ON_ERROR);$this->current($payload['config_fingerprint']);
            $result=app(OperatorEngine::class)->run((int)$row->admin_id,$payload,fn(string $message)=>$this->progress($id,$message));
            DB::table(self::TABLE)->where('id',$id)->where('state','running')->update(['state'=>'done','encrypted_payload'=>null,'encrypted_result'=>Crypt::encryptString(json_encode($result,JSON_THROW_ON_ERROR)),'progress'=>'已完成；待确认的操作尚未执行','finished_at'=>time()]);
        }catch(\Throwable $e){$this->fail($id,$e);}
    }
    private const FAILURES=[
        'config_changed'=>['configuration','AI 设置已变化或已停用，请检查设置后重新发送。'],
        'provider_auth'=>['provider','AI 服务认证失败，请检查 API 密钥及访问权限。'],
        'provider_rate_limit'=>['provider','AI 服务限流或额度不足，请检查额度后稍后重试。'],
        'provider_request'=>['provider','AI 服务拒绝请求，请检查模型名称及参数兼容性。'],
        'provider_http'=>['provider','AI 服务返回异常状态，请稍后重试或检查服务状态。'],
        'provider_timeout'=>['provider','连接 AI 服务超时，请检查网络或稍后重试。'],
        'provider_transport'=>['provider','无法连接 AI 服务，请检查网络、域名及证书。'],
        'transport_unavailable'=>['provider','服务器缺少安全连接所需的 cURL 扩展，请联系管理员。'],
        'invalid_response'=>['response','AI 返回内容格式无效，请重新发送或检查模型兼容性。'],
        'context_budget'=>['context','安全上下文超过大小限制，请缩小问题范围后重试。'],
        'read_budget'=>['context','读取内容超过安全限制，请缩小文件或查询范围。'],
        'time_budget'=>['execution','AI 处理达到时间限制，请缩小问题范围后重试。'],
        'task_timeout'=>['queue','后台任务执行超时，未自动重试。'],
        'task_expired'=>['queue','后台任务已过期，请重新发送。'],
        'queue_failed'=>['queue','后台任务服务启动或执行失败，请检查队列服务。'],
        'queue_timeout'=>['queue','后台队列未及时启动，请检查后台任务服务。'],
        'schema_missing'=>['storage','AI 操作任务尚未初始化，请完成数据库迁移。'],
        'internal_error'=>['execution','AI 后台任务处理失败，未自动重试。请联系管理员检查服务。'],
    ];
    /** Only exact internal tokens are classified; exception text is never persisted or displayed. */
    public static function failure(?\Throwable $exception=null,string $fallback='internal_error'):array
    {
        $code=isset(self::FAILURES[$fallback])?$fallback:'internal_error';
        if($exception){
            $tokens=['configuration_changed'=>'config_changed','context_too_large'=>'context_budget','file_budget'=>'read_budget','invalid_envelope'=>'invalid_response','invalid_answer'=>'invalid_response','invalid_token_parameter'=>'provider_request','response_too_large'=>'invalid_response','unsafe_endpoint'=>'provider_transport','unresolved_endpoint'=>'provider_transport','provider_failed'=>'provider_http'];
            if(get_class($exception)===\RuntimeException::class){$token=$exception->getMessage();if(isset(self::FAILURES[$token]))$code=$token;elseif(isset($tokens[$token]))$code=$tokens[$token];}
            if($exception instanceof \JsonException)$code='invalid_response';
            if($exception instanceof \Illuminate\Database\QueryException && in_array($exception->errorInfo[0]??null,['42S02','42P01','42S22','42703'],true))$code='schema_missing';
            if($exception instanceof \Illuminate\Queue\TimeoutExceededException)$code='task_timeout';
        }
        return ['code'=>$code,'stage'=>self::FAILURES[$code][0],'message'=>self::FAILURES[$code][1]];
    }
    public function fail(string $id,?\Throwable $exception=null,string $fallback='internal_error'):void
    {
        $failure=self::failure($exception,$fallback);
        $changed=DB::table(self::TABLE)->where('id',$id)->whereIn('state',['queued','running'])->update(['state'=>'failed','encrypted_payload'=>null,'encrypted_result'=>null,'error'=>$failure['message'],'progress'=>'任务已停止','finished_at'=>time()]);
        if($changed)\Illuminate\Support\Facades\Log::warning('AI operator task failed',['task_id'=>$id,'reason'=>$failure['code'],'stage'=>$failure['stage']]);
    }
    public function prune():void
    {
        if(!Schema::hasTable(self::TABLE))return;
        DB::table(self::TABLE)->where('state','running')->where('started_at','<',time()-300)->update(['state'=>'failed','encrypted_payload'=>null,'error'=>self::FAILURES['task_timeout'][1],'finished_at'=>time(),'progress'=>'任务已停止']);
        DB::table(self::TABLE)->where('state','queued')->where('created_at','<',time()-900)->update(['state'=>'failed','encrypted_payload'=>null,'error'=>self::FAILURES['queue_timeout'][1],'finished_at'=>time(),'progress'=>'任务已停止']);
        DB::table(self::TABLE)->where('expires_at','<=',time())->delete();
    }
}
