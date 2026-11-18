<?php
namespace App\Onenew\AiOperations;

use App\Models\{Server,User};
use App\Protocols\General;
use App\Services\{ServerService,UserService};
use App\Services\Plugin\HookManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/** Administrator-only local credential delivery. Never return this result to a model. */
final class NodeLinks
{
    public function generate(int $admin,array $input):array
    {
        abort_unless($admin>0&&(bool)User::find($admin)?->is_admin,403);
        $input=Validator::make($input,[
            'node_id'=>'required|integer|min:1',
            'user_id'=>'required_without:email|prohibits:email|integer|min:1',
            'email'=>'required_without:user_id|prohibits:user_id|email|max:254',
        ])->validate();
        $user=isset($input['user_id'])?User::find($input['user_id']):User::where('email',User::normalizeEmail($input['email']))->first();
        abort_unless($user,422,'未找到指定用户，请明确用户编号或邮箱。');
        abort_unless((new UserService)->isAvailable($user)&&((float)$user->u+(float)$user->d<(float)$user->transfer_enable),422,'该用户当前无法使用订阅，请检查到期、封禁和剩余流量。');
        $node=Server::find($input['node_id']);
        abort_unless($node&&$node->enabled!==false,422,'节点不存在或已停用。');
        // Reuse the exact native subscription access selection and password construction.
        $request=Request::create('/api/onenew/admin/ai/node-links','POST');
        $request->setUserResolver(fn()=>$user);
        $servers=HookManager::filter('client.subscribe.servers',ServerService::getAvailableServers($user),$user,$request);
        $selected=[];
        foreach($servers as $server)if((int)($server['id']??0)===(int)$node->id)$selected[]=$server;
        abort_unless(count($selected)===1,422,'该用户无权使用此节点，或节点已隐藏、流量耗尽。');
        // General builds the real subscription transport, UUID/password and SS2022 keys.
        $response=(new General($user,[$selected[0]],'v2rayn','99.0.0','general'))->handle();
        $decoded=base64_decode($response->getContent(),true);
        abort_unless(is_string($decoded),422,'原生订阅生成器未返回有效链接。');
        $links=preg_split('/\r?\n/',trim($decoded),-1,PREG_SPLIT_NO_EMPTY);
        abort_unless(count($links)===1&&preg_match('~^(?:vless|vmess|ss|trojan|hysteria2?|hy2|tuic|anytls|socks5?|https?)://[^\s]+$~D',$links[0]),422,'该节点暂不支持生成唯一的单节点链接。');
        return ['node_link'=>[
            'node_id'=>(int)$node->id,'node_name'=>(string)$node->name,'user_id'=>(int)$user->id,
            'generated_at'=>gmdate('c'),'source'=>'native_subscription','uri'=>$links[0],
            'evidence'=>['generator'=>General::class,'node_updated_at'=>$node->getRawOriginal('updated_at'),'protocol'=>$node->type,'account_eligible'=>true,'group_access'=>true,'connectivity_verified'=>false],
        ]];
    }
}
