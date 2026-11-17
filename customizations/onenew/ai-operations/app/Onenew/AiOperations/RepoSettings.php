<?php
namespace App\Onenew\AiOperations;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
final class RepoSettings
{
    private function row():array {abort_unless(Schema::hasColumn('onenew_ai_config','encrypted_repo_key'),503,'请先运行数据库迁移。');(new Config)->row();return (array)DB::table('onenew_ai_config')->where('id',1)->first();}
    public function view():array {return ['repository'=>'chinahch/onenew','base_branch'=>'master','key_configured'=>!empty($this->row()['encrypted_repo_key']),'required_permissions'=>['Contents: read and write','Pull requests: read and write']];}
    public function token():string {$r=$this->row();return empty($r['encrypted_repo_key'])?'':Crypt::decryptString($r['encrypted_repo_key']);}
    public function save(array $input):array {$input['api_key']=$input['repo_key']??$input['api_key']??'';return DB::transaction(function()use($input){$this->row();DB::table('onenew_ai_config')->where('id',1)->lockForUpdate()->first();$patch=[];if(!empty($input['api_key'])){abort_unless(is_string($input['api_key'])&&strlen($input['api_key'])<=512&&!preg_match('/[\s\x00-\x1f]/',$input['api_key']),422,'无效的仓库密钥。');$patch['encrypted_repo_key']=Crypt::encryptString($input['api_key']);}if(!empty($input['clear_key']))$patch['encrypted_repo_key']=null;if($patch)DB::table('onenew_ai_config')->where('id',1)->update($patch);return $this->view();});}
}
