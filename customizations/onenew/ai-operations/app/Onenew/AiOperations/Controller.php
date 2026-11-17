<?php
namespace App\Onenew\AiOperations;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class Controller
{
    private function response(array $data){return response()->json($data)->header('Cache-Control','no-store')->header('X-Content-Type-Options','nosniff');}
    private function admin(Request $request):int{return (int)\Illuminate\Support\Facades\Auth::guard('sanctum')->id();}
    public function config(Config $config){return $this->response(['config'=>$config->view()]);}
    public function save(Request $request,Config $config)
    {
        $data=$request->validate(['enabled'=>'sometimes|boolean','endpoint'=>'sometimes|required|string|max:512','model'=>['sometimes','required','string','max:200','regex:/^[^\x00-\x20\x7f]+$/D'],'token_parameter'=>'sometimes|required|in:max_tokens,max_completion_tokens','api_key'=>['sometimes','nullable','string','max:16384','regex:/^[^\x00-\x20\x7f]*$/D'],'clear_key'=>'sometimes|boolean']);
        return $this->response(['config'=>$config->save($data)]);
    }
    public function preview(Request $request,Service $service)
    {
        $data=$request->validate(['question'=>'required|string|max:4000','scope'=>'required|in:panel,nodes,users,orders,backups,upgrades','node_id'=>'sometimes|nullable|integer|min:1','history'=>'sometimes|array|max:8','history.*'=>'array:role,content','history.*.role'=>'required|in:user,assistant','history.*.content'=>'required|string|max:4000']);
        return $this->response($service->preview($this->admin($request),$data));
    }
    public function chat(Request $request,Service $service)
    {
        $data=$request->validate(['preview_id'=>'required|uuid','request_id'=>'required|uuid','confirmed'=>'required|accepted']);
        return $this->response($service->chat($this->admin($request),$data['preview_id'],$data['request_id']));
    }
    public function test(Service $service){$ok=$service->test();return $this->response(['success'=>$ok,'message'=>$ok?'连接测试成功。':'连接测试失败，请检查已保存的地址、模型和密钥。']);}
    public function operatorCreate(Request $request,OperatorTasks $tasks)
    {
        $data=$request->validate(['message'=>'required|string|max:8000','request_id'=>'required|uuid','page_path'=>'sometimes|nullable|string|max:300','history'=>'sometimes|array|max:8','history.*'=>'array:role,content','history.*.role'=>'required|in:user,assistant','history.*.content'=>'required|string|max:4000']);
        return $this->response($tasks->create($this->admin($request),$data));
    }
    public function operatorView(Request $request,string $id,OperatorTasks $tasks){abort_unless(\Illuminate\Support\Str::isUuid($id),404);return $this->response($tasks->view($this->admin($request),$id));}
    public function execute(Request $request,string $id,OperatorActions $actions)
    {
        $data=$request->validate(['request_id'=>'required|uuid','confirmed'=>'required|accepted']);abort_unless($request->input('confirmed')===true,422,'请明确确认此操作。');
        return $this->response($actions->execute($this->admin($request),$id,$data['request_id']));
    }
    public function publish(Request $request,string $id,SourceTools $source)
    {
        $data=$request->validate(['request_id'=>'required|uuid','confirmed'=>'required|accepted']);abort_unless($request->input('confirmed')===true,422,'请明确确认此代码提交。');
        try{return $this->response($source->publish($this->admin($request),$id,$data['request_id']));}
        catch(\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e){throw $e;}
        catch(\Throwable $e){return response()->json(['message'=>'仓库提交失败，请检查独立仓库设置后使用原请求重试。'],502)->header('Cache-Control','no-store');}
    }
    public function repository(RepoSettings $settings){return $this->response(['repository'=>$settings->view()]);}
    public function saveRepository(Request $request,RepoSettings $settings)
    {
        $data=$request->validate(['repo_key'=>['sometimes','nullable','string','max:512','regex:/^[^\x00-\x20\x7f]*$/D'],'clear_key'=>'sometimes|boolean']);
        return $this->response(['repository'=>$settings->save($data)]);
    }
    public function options()
    {
        $nodes=DB::table('v2_server')->orderBy('id')->limit(200)->pluck('id')->map(fn($id)=>['id'=>(int)$id,'label'=>'节点 #'.(int)$id])->all();
        return $this->response(['nodes'=>$nodes]);
    }
}
