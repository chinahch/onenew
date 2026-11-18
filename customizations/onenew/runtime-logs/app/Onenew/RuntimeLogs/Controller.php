<?php
namespace App\Onenew\RuntimeLogs;
use App\Models\ServerMachine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
final class Controller
{
 private function json(array $data){return response()->json($data)->header('Cache-Control','no-store')->header('X-Content-Type-Options','nosniff');}
 public function upload(Request $request,Service $service){
  abort_if(strlen($request->getContent())>262144,413,'Runtime log batch exceeds 256KB.');$request->validate(['machine_id'=>'required|integer|min:1']);$machine=ServerMachine::find((int)$request->input('machine_id'));$token=$request->bearerToken();abort_unless($machine&&$machine->is_active&&$token&&strlen($token)<=1024&&hash_equals((string)$machine->token,$token),403);
  $data=$request->validate(['machine_id'=>'required|integer|min:1','deployment_id'=>'required|uuid','batch_id'=>'required|uuid','first_seq'=>'required|integer|min:1|max:9007199254740000','version'=>['required','string','max:40','regex:/^(?:onenew-)?v\d+\.\d+\.\d+$/D'],'dropped'=>'required|integer|min:0|max:1000000000','entries'=>'present|array|max:1000','entries.*'=>'array:seq,level,at,message','entries.*.seq'=>'required|integer|min:1|max:9007199254740000','entries.*.level'=>'required|in:debug,info,warn,error','entries.*.at'=>'required|integer|min:0|max:'.(time()+300),'entries.*.message'=>'required|string|max:8192']);
  return $this->json($service->accept($machine,$data));
 }
 public function index(Request $request,string $id,Service $service){
  abort_unless(ctype_digit($id),404);$machine=ServerMachine::findOrFail((int)$id);
  $filters=$request->validate(['level'=>'sometimes|nullable|in:debug,info,warn,error','search'=>'sometimes|nullable|string|max:100','from'=>'sometimes|nullable|integer|min:0','to'=>'sometimes|nullable|integer|min:0','before_id'=>'sometimes|nullable|integer|min:1','page'=>'sometimes|integer|min:1|max:10000','limit'=>'sometimes|integer|min:1|max:100']);
  return $this->json($service->query($machine,$filters,(int)Auth::guard('sanctum')->id()));
 }
}
