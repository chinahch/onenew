<?php
namespace App\Onenew\AiOperations;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class Controller
{
    private function response(array $data){return response()->json($data)->header('Cache-Control','no-store')->header('X-Content-Type-Options','nosniff');}
    private function admin(Request $request):int{return (int)\Illuminate\Support\Facades\Auth::guard('sanctum')->id();}
    public function config(Request $request,Config $config){return $this->response(['config'=>$config->view(),'storage_scope'=>hash_hmac('sha256','ai-chat:'.$this->admin($request),(string)config('app.key'))]);}
    public function save(Request $request,Config $config)
    {
        $data=$request->validate(['enabled'=>'sometimes|boolean','backend'=>'sometimes|required|in:chat_completions,opencode','opencode_protocol'=>'sometimes|required|in:v1,v2','endpoint'=>'sometimes|nullable|string|max:512','model'=>['sometimes','nullable','string','max:200','regex:/^[^\x00-\x20\x7f]*$/D'],'token_parameter'=>'sometimes|required|in:max_tokens,max_completion_tokens','api_key'=>['sometimes','nullable','string','max:16384','regex:/^[^\x00-\x20\x7f]*$/D'],'clear_key'=>'sometimes|boolean','opencode_endpoint'=>'sometimes|nullable|string|max:512','opencode_directory'=>'sometimes|nullable|string|max:1024','opencode_username'=>'sometimes|required|string|max:100','opencode_agent'=>'sometimes|nullable|string|max:100','opencode_model'=>'sometimes|nullable|string|max:300','opencode_password'=>['sometimes','nullable','string','max:16384','regex:/^[^\x00-\x1f\x7f]*$/D'],'clear_opencode_password'=>'sometimes|boolean']);
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
    public function executeNative(Request $request,string $id,NativeAdminTools $native)
    {
        $data=$request->validate(['request_id'=>'required|uuid','confirmed'=>'required|accepted']);
        abort_unless($request->input('confirmed')===true,422,'请明确确认此管理操作。');
        return $this->response($native->execute($this->admin($request),$id,$data['request_id']));
    }
    public function repository(RepoSettings $settings){return $this->response(['repository'=>$settings->view()]);}
    public function saveRepository(Request $request,RepoSettings $settings)
    {
        $data=$request->validate(['repo_key'=>['sometimes','nullable','string','max:512','regex:/^[^\x00-\x20\x7f]*$/D'],'clear_key'=>'sometimes|boolean']);
        return $this->response(['repository'=>$settings->save($data)]);
    }
    public function nodeLinks(Request $request,NodeLinks $links)
    {
        $data=$request->validate(['node_id'=>'required|integer|min:1','user_id'=>'required_without:email|prohibits:email|integer|min:1','email'=>'required_without:user_id|prohibits:user_id|email|max:254']);
        return $this->response($links->generate($this->admin($request),$data));
    }
    public function options()
    {
        $nodes=DB::table('v2_server')->orderBy('id')->limit(200)->pluck('id')->map(fn($id)=>['id'=>(int)$id,'label'=>'节点 #'.(int)$id])->all();
        return $this->response(['nodes'=>$nodes]);
    }
}
