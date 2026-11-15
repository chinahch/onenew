<?php
namespace App\Onenew\LandingLibrary;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
final class LandingController extends Controller
{
    private function response(array $data) {return response()->json($data)->header('Cache-Control','no-store');}
    public function index() {return $this->response(['data'=>DB::table(Library::TABLE)->orderBy('sort')->orderBy('id')->get()->map(fn($row)=>Library::summary($row))->all()]);}
    public function show(int $id) {
        $row=DB::table(Library::TABLE)->find($id);abort_unless($row,404);
        return $this->response(Library::summary($row)+Library::payload($row));
    }
    public function save(Request $request,?int $id=null) {
        abort_if(strlen($request->getContent())>120000,413);
        $data=$request->validate(['name'=>'required|string|max:160','link'=>'required|string|max:16384','variants'=>'required|array|min:1|max:3','enabled'=>'required|boolean','sort'=>'required|integer|min:-2147483648|max:2147483647','version'=>($id?'required':'nullable').'|integer|min:1']);
        $payload=Library::validatePayload($data['link'],$data['variants']);
        $result=DB::transaction(function()use($id,$data,$payload){
            Library::lock();$row=$id?DB::table(Library::TABLE)->where('id',$id)->lockForUpdate()->first():null;
            if($id){abort_unless($row,404);abort_unless((int)$row->version===(int)$data['version'],409,'落地已被修改，请刷新');}
            $nodes=$id?Library::references($id,true):collect();
            abort_if(!$data['enabled'] && $nodes->isNotEmpty(),409,'落地正在被节点引用，不能停用');
            // Validate every affected node before changing any durable configuration.
            $compiled=[];
            foreach($nodes as $node)$compiled[$node->id]=Library::compile($node,$payload+['id'=>$id,'enabled'=>(bool)$data['enabled']]);
            $version=$row?(int)$row->version+1:1;
            $values=['name'=>trim($data['name']),'enabled'=>(bool)$data['enabled'],'sort'=>(int)$data['sort'],'version'=>$version,
                'link_ciphertext'=>Crypt::encryptString(json_encode($payload,JSON_THROW_ON_ERROR)),'updated_at'=>now()];
            abort_if($values['name']==='',422,'名称不能为空');
            if($row)DB::table(Library::TABLE)->where('id',$id)->update($values);
            else{$values['created_at']=now();$id=DB::table(Library::TABLE)->insertGetId($values);}
            foreach($nodes as $node){$node->forceFill($compiled[$node->id]);$node->save();}
            return ['id'=>(int)$id,'version'=>$version];
        });return $this->response($result);
    }
    public function delete(Request $request,int $id) {
        $p=$request->validate(['version'=>'required|integer|min:1']);
        DB::transaction(function()use($id,$p){Library::lock();$row=DB::table(Library::TABLE)->where('id',$id)->lockForUpdate()->first();abort_unless($row,404);
            abort_unless((int)$row->version===(int)$p['version'],409,'落地已被修改，请刷新');
            abort_if(Library::references($id,true)->isNotEmpty(),409,'落地正在被节点引用，不能删除');DB::table(Library::TABLE)->where('id',$id)->delete();});
        return $this->response(['ok'=>true]);
    }
}
