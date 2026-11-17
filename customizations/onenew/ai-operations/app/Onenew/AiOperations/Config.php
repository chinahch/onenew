<?php
namespace App\Onenew\AiOperations;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class Config
{
    public function row():array
    {
        if(!Schema::hasTable('onenew_ai_config')||!Schema::hasColumn('onenew_ai_config','token_parameter')) {
            abort(response()->json(['message'=>'AI 配置尚未初始化，请先完成数据库迁移。','code'=>'ai_schema_missing'],503)->header('Cache-Control','no-store'));
        }
        DB::table('onenew_ai_config')->insertOrIgnore(['id'=>1]);
        return (array)DB::table('onenew_ai_config')->where('id',1)->first();
    }
    public function key(?array $row=null):string
    {
        $row??=$this->row();return empty($row['encrypted_key'])?'':Crypt::decryptString($row['encrypted_key']);
    }
    public function view(?array $row=null):array
    {
        $row??=$this->row();return ['enabled'=>(bool)$row['enabled'],'endpoint'=>$row['endpoint'],'model'=>$row['model'],'token_parameter'=>$row['token_parameter'],'key_configured'=>!empty($row['encrypted_key'])];
    }
    public function save(array $input):array
    {
        if(isset($input['endpoint']))$input['endpoint']=Endpoint::normalize($input['endpoint']);
        if(isset($input['token_parameter'])&&!in_array($input['token_parameter'],['max_tokens','max_completion_tokens'],true))throw \Illuminate\Validation\ValidationException::withMessages(['token_parameter'=>'请选择支持的输出限制参数。']);
        return DB::transaction(function()use($input){
            $this->row();$row=(array)DB::table('onenew_ai_config')->where('id',1)->lockForUpdate()->first();$patch=[];
            foreach(['enabled','endpoint','model','token_parameter'] as $field)if(array_key_exists($field,$input))$patch[$field]=$input[$field];
            if(!empty($input['api_key']))$patch['encrypted_key']=Crypt::encryptString($input['api_key']);
            if(!empty($input['clear_key']))$patch['encrypted_key']=null;
            if($patch)DB::table('onenew_ai_config')->where('id',1)->update($patch);
            return $this->view();
        });
    }
}
