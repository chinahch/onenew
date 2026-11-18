<?php
namespace App\Onenew\AiOperations;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class Config
{
    public function row():array
    {
        if(!Schema::hasTable('onenew_ai_config')||!Schema::hasColumn('onenew_ai_config','backend')) {
            abort(response()->json(['message'=>'AI 配置尚未初始化，请先完成数据库迁移。','code'=>'ai_schema_missing'],503)->header('Cache-Control','no-store'));
        }
        DB::table('onenew_ai_config')->insertOrIgnore(['id'=>1]);
        return (array)DB::table('onenew_ai_config')->where('id',1)->first();
    }
    public function key(?array $row=null):string
    {
        $row??=$this->row();return empty($row['encrypted_key'])?'':Crypt::decryptString($row['encrypted_key']);
    }
    public function opencodePassword(?array $row=null):string
    {
        $row??=$this->row();return empty($row['encrypted_opencode_password'])?'':Crypt::decryptString($row['encrypted_opencode_password']);
    }
    public function ready(array $row):bool
    {
        if(empty($row['enabled'])||!in_array($row['backend']??'chat_completions',['chat_completions','opencode'],true))return false;
        if(($row['backend']??'')==='opencode'&&!in_array($row['opencode_protocol']??'v2',['v1','v2'],true))return false;
        if(($row['backend']??'chat_completions')==='opencode')return !empty($row['opencode_endpoint'])&&!empty($row['opencode_directory'])&&!empty($row['opencode_username'])&&!empty($row['encrypted_opencode_password']);
        return !empty($row['encrypted_key'])&&!empty($row['endpoint'])&&!empty($row['model']);
    }
    public function view(?array $row=null):array
    {
        $row??=$this->row();return ['enabled'=>(bool)$row['enabled'],'endpoint'=>$row['endpoint'],'model'=>$row['model'],'token_parameter'=>$row['token_parameter'],'key_configured'=>!empty($row['encrypted_key']),'backend'=>$row['backend']??'chat_completions','opencode_protocol'=>$row['opencode_protocol']??'v2','opencode_endpoint'=>$row['opencode_endpoint']??'','opencode_directory'=>$row['opencode_directory']??'','opencode_username'=>$row['opencode_username']??'opencode','opencode_agent'=>$row['opencode_agent']??'','opencode_model'=>$row['opencode_model']??'','opencode_password_configured'=>!empty($row['encrypted_opencode_password'])];
    }
    public function save(array $input):array
    {
        if(isset($input['endpoint'])&&$input['endpoint']!=='')$input['endpoint']=Endpoint::normalize($input['endpoint']);
        if(isset($input['backend'])&&!in_array($input['backend'],['chat_completions','opencode'],true))throw \Illuminate\Validation\ValidationException::withMessages(['backend'=>'请选择支持的 AI 后端。']);
        if(isset($input['opencode_protocol'])&&!in_array($input['opencode_protocol'],['v1','v2'],true))throw \Illuminate\Validation\ValidationException::withMessages(['opencode_protocol'=>'请选择 V2 或兼容 V1 协议。']);
        if(isset($input['opencode_endpoint'])&&$input['opencode_endpoint']!=='')$input['opencode_endpoint']=OpenCodeClient::normalize($input['opencode_endpoint']);
        if(isset($input['opencode_directory'])&&$input['opencode_directory']!==''){
            $directory=$input['opencode_directory'];if(!str_starts_with($directory,'/')||preg_match('/[\\\\\x00-\x1f\x7f]/',$directory)||in_array('..',explode('/',$directory),true)||in_array('.',explode('/',$directory),true))throw \Illuminate\Validation\ValidationException::withMessages(['opencode_directory'=>'请输入绝对 Linux 项目目录。']);
            $input['opencode_directory']=rtrim($directory,'/')?:'/';
        }
        if(isset($input['opencode_username'])&&(!preg_match('/^[^:\x00-\x20\x7f]{1,100}$/D',$input['opencode_username'])))throw \Illuminate\Validation\ValidationException::withMessages(['opencode_username'=>'请输入有效的 Basic Auth 用户名。']);
        if(isset($input['opencode_agent'])&&$input['opencode_agent']!==''&&!preg_match('/^[^\x00-\x20\x7f]{1,100}$/D',$input['opencode_agent']))throw \Illuminate\Validation\ValidationException::withMessages(['opencode_agent'=>'请输入有效的代理名称。']);
        if(isset($input['opencode_model'])&&$input['opencode_model']!==''&&!preg_match('~^[^/\x00-\x20\x7f]+/[^\x00-\x20\x7f]+$~D',$input['opencode_model']))throw \Illuminate\Validation\ValidationException::withMessages(['opencode_model'=>'模型格式必须是 provider/model。']);
        if(isset($input['token_parameter'])&&!in_array($input['token_parameter'],['max_tokens','max_completion_tokens'],true))throw \Illuminate\Validation\ValidationException::withMessages(['token_parameter'=>'请选择支持的输出限制参数。']);
        return DB::transaction(function()use($input){
            $this->row();$row=(array)DB::table('onenew_ai_config')->where('id',1)->lockForUpdate()->first();$patch=[];
            foreach(['enabled','endpoint','model','token_parameter','backend','opencode_protocol','opencode_endpoint','opencode_directory','opencode_username','opencode_agent','opencode_model'] as $field)if(array_key_exists($field,$input))$patch[$field]=$input[$field]??'';
            if(!empty($input['api_key']))$patch['encrypted_key']=Crypt::encryptString($input['api_key']);
            if(!empty($input['clear_key']))$patch['encrypted_key']=null;
            if(isset($input['opencode_password'])&&$input['opencode_password']!=='')$patch['encrypted_opencode_password']=Crypt::encryptString($input['opencode_password']);
            if(!empty($input['clear_opencode_password']))$patch['encrypted_opencode_password']=null;
            if($patch)DB::table('onenew_ai_config')->where('id',1)->update($patch);
            return $this->view();
        });
    }
}
