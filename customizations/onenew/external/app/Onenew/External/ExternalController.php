<?php
namespace App\Onenew\External;

use App\Http\Controllers\Controller;
use App\Models\ServerGroup;
use App\Models\User;
use App\Onenew\ResourceIds;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class ExternalController extends Controller
{
    public function index(): mixed
    {
        $rows = DB::table(ExternalNodes::TABLE)->orderBy('sort')->orderBy('id')->get()->map(function ($row) {
            $node = ShareLink::parse(Crypt::decryptString($row->link_ciphertext));
            return ['id'=>(int)$row->id,'name'=>$row->name,'type'=>$node['type'],'host'=>$node['host'],'port'=>(int)$node['port'],
                'enabled'=>(bool)$row->enabled,'sort'=>(int)$row->sort,'user_ids'=>json_decode($row->user_ids,true),
                'group_ids'=>json_decode($row->group_ids,true)];
        });
        return response()->json(['data'=>$rows])->header('Cache-Control','no-store');
    }

    public function detail(int $id): mixed
    {
        $row = DB::table(ExternalNodes::TABLE)->find($id); abort_unless($row,404);
        return response()->json(['id'=>$id,'link'=>Crypt::decryptString($row->link_ciphertext)])
            ->header('Cache-Control','no-store');
    }

    public function lookups(Request $request): mixed
    {
        $p = $request->validate(['search'=>'nullable|string|max:128','user_ids'=>'nullable|array|max:500','user_ids.*'=>'integer|min:1']);
        $users = User::query()->select(['id','email'])->orderBy('id');
        if (!empty($p['user_ids'])) $users->whereIn('id', $p['user_ids']);
        else $users->where('email','like','%'.addcslashes($p['search'] ?? '', '%_\\').'%')->limit(30);
        return response()->json(['users'=>$users->get(),'groups'=>ServerGroup::orderBy('id')->get(['id','name'])])->header('Cache-Control','no-store');
    }

    private function assignments(Request $request): array
    {
        $p = $request->validate(['user_ids'=>'present|array|max:500','user_ids.*'=>'integer|min:1|distinct',
            'group_ids'=>'present|array|max:500','group_ids.*'=>'integer|min:1|distinct']);
        $users = array_map('intval',$p['user_ids']); $groups = array_map('intval',$p['group_ids']);
        abort_unless(User::whereIn('id',$users)->count()===count($users) && ServerGroup::whereIn('id',$groups)->count()===count($groups),422,'用户或权限组已不存在');
        return ['user_ids'=>json_encode($users),'group_ids'=>json_encode($groups)];
    }

    private function metadata(string $link, ?string $name = null): array
    {
        try { $node = ShareLink::parse($link); }
        catch (\InvalidArgumentException $error) { abort(422,$error->getMessage()); }
        $name = trim($name ?? '') ?: ($node['name'] ?: $node['host']);
        abort_if(mb_strlen($name)>255 || preg_match('/[\x00-\x1f\x7f]/',$name),422,'节点名称无效');
        return ['name'=>$name,'link_ciphertext'=>Crypt::encryptString($link),'updated_at'=>now()];
    }

    public function save(Request $request): mixed
    {
        $p = $request->validate(['id'=>'nullable|integer|min:1','link'=>'required|string|max:16384',
            'name'=>'nullable|string|max:255','enabled'=>'required|boolean']);
        $data = $this->metadata(trim($p['link']),$p['name'] ?? null) + $this->assignments($request) + ['enabled'=>$p['enabled']];
        $id = DB::transaction(function () use ($p,$data) {
            ResourceIds::lock(ExternalNodes::TABLE);
            if (!empty($p['id'])) {
                abort_unless(DB::table(ExternalNodes::TABLE)->where('id',$p['id'])->exists(),404);
                DB::table(ExternalNodes::TABLE)->where('id',$p['id'])->update($data); return $p['id'];
            }
            abort_if(DB::table(ExternalNodes::TABLE)->count()>=2000,422,'外部节点数量已达上限');
            return DB::table(ExternalNodes::TABLE)->insertGetId($data + ['sort'=>1+(int)DB::table(ExternalNodes::TABLE)->max('sort'),'created_at'=>now()]);
        },3);
        return response()->json(['id'=>$id])->header('Cache-Control','no-store');
    }

    public function import(Request $request): mixed
    {
        $p = $request->validate(['links'=>'required|string|max:3300000']); $assignments = $this->assignments($request);
        $links = array_values(array_filter(array_map('trim',preg_split('/\r?\n/',$p['links'])),fn ($v)=>$v!==''));
        abort_if(count($links)<1 || count($links)>200,422,'每次请导入 1–200 条链接');
        // Validate every line before any insert; a bad batch never partially imports.
        $data = array_map(fn ($link)=>$this->metadata($link)+$assignments+['enabled'=>true,'created_at'=>now()],$links);
        DB::transaction(function () use ($data) {
            ResourceIds::lock(ExternalNodes::TABLE);
            abort_if(DB::table(ExternalNodes::TABLE)->count()+count($data)>2000,422,'外部节点数量已达上限');
            $sort = (int)DB::table(ExternalNodes::TABLE)->max('sort');
            foreach ($data as $node) DB::table(ExternalNodes::TABLE)->insert($node+['sort'=>++$sort]);
        },3);
        return response()->json(['imported'=>count($data)])->header('Cache-Control','no-store');
    }

    public function order(Request $request): mixed
    {
        $p = $request->validate(['ids'=>'present|array|max:2000','ids.*'=>'integer|min:1|distinct']);
        DB::transaction(function () use ($p) {
            ResourceIds::lock(ExternalNodes::TABLE);
            $ids = array_map('intval',$p['ids']); $existing = DB::table(ExternalNodes::TABLE)->pluck('id')->map(fn ($id)=>(int)$id)->all();
            $check = $ids; sort($check); sort($existing); abort_unless($check===$existing,409,'节点列表已变动，请刷新后重排');
            foreach ($ids as $position=>$id) DB::table(ExternalNodes::TABLE)->where('id',$id)->update(['sort'=>$position+1]);
        },3);
        return response()->json(['ok'=>true]);
    }

    public function drop(Request $request): mixed
    {
        $p = $request->validate(['id'=>'required|integer|min:1']);
        DB::transaction(function () use ($p) { ResourceIds::lock(ExternalNodes::TABLE); DB::table(ExternalNodes::TABLE)->where('id',$p['id'])->delete(); },3);
        return response()->json(['ok'=>true]);
    }
}
