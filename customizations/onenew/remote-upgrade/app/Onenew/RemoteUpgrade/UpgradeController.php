<?php
namespace App\Onenew\RemoteUpgrade;
use App\Http\Controllers\Controller;
use App\Models\ServerMachine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
final class UpgradeController extends Controller
{
    private function json(array $data) { return response()->json($data)->header('Cache-Control','no-store'); }
    public function index(UpgradeService $service) { return $this->json($service->overview()); }
    public function create(Request $r,UpgradeService $service) {
        abort_if(strlen($r->getContent())>16000,413);
        $data=$r->validate(['machine_ids'=>'required|array|min:1|max:100','machine_ids.*'=>'required|integer|min:1|distinct',
            'version'=>'required|string|max:64','confirmed'=>'required|accepted','url'=>'prohibited','command'=>'prohibited','sha256'=>'prohibited']);
        return $this->json($service->create($data['machine_ids'],$data['version'],(int)Auth::guard('sanctum')->id()));
    }
    public function cancel(string $id,UpgradeService $service) { $this->id($id); $service->cancel($id); return $this->json(['ok'=>true]); }
    public function poll(Request $r,UpgradeService $service) {
        $m=$this->machine($r);
        $data=$r->validate(['arch'=>['required',Rule::in(['amd64','arm64'])],'version'=>['required','regex:/^v[0-9]+\.[0-9]+\.[0-9]+$/D'],
            'assistant_version'=>['required','regex:/^v[0-9]+\.[0-9]+\.[0-9]+$/D']]);
        return $this->json(['job'=>$service->poll($m,$data)]);
    }
    public function status(Request $r,string $id,UpgradeService $service) {
        abort_if(strlen($r->getContent())>2048,413); $m=$this->machine($r); $this->id($id);
        $data=$r->validate(['lease'=>'required|string|size:64','state'=>['required',Rule::in(array_merge(UpgradeService::ACTIVE,['succeeded','failed','rolled_back']))],
            'version'=>['required','regex:/^v[0-9]+\.[0-9]+\.[0-9]+$/D'],'error_code'=>['nullable',Rule::in(array_merge([''],UpgradeService::ERRORS))],
            'logs'=>'prohibited','command'=>'prohibited','url'=>'prohibited']);
        $service->accept($m,$id,$data); return $this->json(['ok'=>true]);
    }
    private function id(string $id): void { abort_unless(Strlen($id)===36 && preg_match('/^[a-f0-9-]{36}$/D',$id),404); }
    private function machine(Request $r): ServerMachine {
        $r->validate(['machine_id'=>'required|integer|min:1']); $token=$r->bearerToken(); $m=ServerMachine::find((int)$r->input('machine_id'));
        abort_unless($token && strlen($token)<=1024 && $m && $m->is_active && hash_equals((string)$m->token,$token),403); return $m;
    }
}
