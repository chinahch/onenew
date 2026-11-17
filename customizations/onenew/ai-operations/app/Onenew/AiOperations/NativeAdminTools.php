<?php
namespace App\Onenew\AiOperations;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Installed native routes are capabilities; callers never supply a URL or controller. */
final class NativeAdminTools
{
    private const TABLE = 'onenew_ai_native_actions';
    private const READS = ['fetch','list','get','getNodes','nodes','history','detail','getUserInfoById','getOverride','getStats','getServerLastRank','getServerYesterdayRank','getOrder','getStatUser','getRanking','getStatRecord','getTrafficRank','templates','codes','usages','statistics','types','getCategory','getPaymentMethods','getSystemStatus','getQueueStats','getQueueWorkload','getHorizonFailedJobs','getAuditLog','getThemes','getThemeConfig','logs','stats','userHistory','getEmailTemplate','getThemeTemplate'];
    private const SECRET = '/password|passwd|(^|_)pass($|_)|secret|token|uuid|private.?key|api.?key|credential|authorization|cookie|certificate|cert$|salt|encrypted|subscribe.?url|install.?command|(^|_)key$|\.env/i';

    public function __construct(private Router $router) {}

    private function admin(int $id): User
    {
        $user = User::find($id);
        abort_unless($user && $user->is_admin && !$user->banned, 403, 'Active administrator required.');
        return $user;
    }

    private function registry(): array
    {
        $out = [];
        foreach ($this->router->getRoutes() as $route) {
            $action = $route->getActionName();
            if (!is_string($action) || !str_contains($action, '@')) continue;
            [$class, $method] = explode('@', ltrim($action, '\\'), 2);
            if (!(str_starts_with($class, 'App\\Http\\Controllers\\V2\\Admin\\') || str_starts_with($class, 'App\\Onenew\\')) || str_contains($class, '\\AiOperations\\')) continue;
            if (!in_array('admin', $route->gatherMiddleware(), true) && !in_array(\App\Http\Middleware\Admin::class, $route->gatherMiddleware(), true)) continue;
            foreach (array_intersect(['GET','POST','PUT','PATCH','DELETE'], $route->methods()) as $verb) {
                $id = hash('sha256', $verb.'|'.$route->uri().'|'.$class.'@'.$method);
                $parts = explode('/', $route->uri());
                $out[$id] = ['capability'=>$id, 'method'=>$verb, 'action'=>$method, 'path'=>$route->uri(), 'category'=>preg_replace('/Controller$/', '', class_basename($class)), 'mode'=>in_array($method, self::READS, true) ? 'read' : 'confirm', 'schema'=>['params'=>['type'=>'object','description'=>'Native endpoint parameters; native FormRequest validation is authoritative. Credentials and role changes require the native UI.','validation_hints'=>$this->ruleHints($class,$method)],'path_parameters'=>$route->parameterNames()], '_route'=>$route];
            }
        }
        ksort($out);
        return $out;
    }

    /** Read literal rules only: catalog must never execute controller/request/plugin code. */
    private function ruleHints(string $class, string $method): array
    {
        $hints=[];
        try {
            foreach((new \ReflectionMethod($class,$method))->getParameters() as $parameter){
                $type=$parameter->getType();
                if(!$type instanceof \ReflectionNamedType || $type->isBuiltin() || !is_subclass_of($type->getName(),\Illuminate\Foundation\Http\FormRequest::class))continue;
                $rules=new \ReflectionMethod($type->getName(),'rules'); $file=$rules->getFileName();
                if(!$file || !str_starts_with(str_replace('\\','/',realpath($file)),str_replace('\\','/',realpath(app_path())).'/'))continue;
                $lines=file($file); $source=implode('',array_slice($lines,$rules->getStartLine()-1,$rules->getEndLine()-$rules->getStartLine()+1));
                preg_match_all('/[\x27\x22]([a-zA-Z0-9_.*-]{1,80})[\x27\x22]\s*=>\s*[\x27\x22]([a-zA-Z0-9_|:.,* -]{1,200})[\x27\x22]/',$source,$matches,PREG_SET_ORDER);
                foreach($matches as $match)if(!preg_match(self::SECRET,$match[1])&&!in_array($match[1],['is_admin','is_staff','role','permissions'],true))$hints[$match[1]]=$match[2];
            }
        }catch(\ReflectionException $e){}
        return array_slice($hints,0,50,true);
    }

    public function catalog(array $args=[]): array
    {
        $all = array_values($this->registry());
        $categories = array_values(array_unique(array_column($all, 'category'))); sort($categories);
        $keyword = mb_substr((string)($args['keyword'] ?? ''), 0, 100);
        $all = array_values(array_filter($all, fn($r)=> (!isset($args['category']) || $args['category']===$r['category']) && ($keyword==='' || stripos($r['category'].' '.$r['action'].' '.$r['path'], $keyword)!==false)));
        $offset = max(0, (int)($args['offset'] ?? 0)); $limit = max(1, min(30, (int)($args['limit'] ?? 20)));
        $items = array_slice($all, $offset, $limit);
        foreach ($items as &$item) unset($item['_route']);
        return ['items'=>$items,'categories'=>$categories,'total'=>count($all),'next_offset'=>$offset+$limit<count($all)?$offset+$limit:null];
    }

    private function resolve(array $args): array
    {
        abort_unless(count(array_diff(array_keys($args), ['capability','params']))===0 && is_string($args['capability']??null) && is_array($args['params']??[]), 422, 'Expected capability and params.');
        $entry = $this->registry()[$args['capability']] ?? null;
        abort_unless($entry, 422, 'Capability unavailable.');
        $params = $args['params'] ?? [];
        abort_if(strlen(json_encode($params, JSON_THROW_ON_ERROR))>16000, 422, 'Parameters too large.');
        $this->checkInput($params);
        return [$entry, $params];
    }

    private function checkInput(array $params, int $depth=0): void
    {
        abort_if($depth>8 || count($params)>100, 422, 'Parameters too complex.');
        foreach ($params as $key=>$value) {
            abort_if(preg_match(self::SECRET, (string)$key) || preg_match('/^(is_admin|role|roles|permissions|is_staff|_method)$/i', (string)$key), 422, 'Credentials and privilege changes require the native panel UI.');
            if (is_array($value)) $this->checkInput($value, $depth+1);
            else {
                abort_unless(is_null($value)||is_scalar($value), 422, 'Scalar parameters required.');
                if(is_string($value))abort_if(preg_match('~BEGIN .*PRIVATE KEY|https?://[^/\s]+:[^/\s]+@|[\x22\x27]?(?:password|private_key|api_key|is_admin|is_staff)[\x22\x27]?\s*[:=]|\b(?:sk-|ghp_|github_pat_)[A-Za-z0-9_-]{12,}~i',$value),422,'Credentials and privilege changes require the native panel UI.');
            }
        }
    }

    public function read(int $admin, array $args): array
    {
        $user = $this->admin($admin); [$entry,$params] = $this->resolve($args);
        abort_unless($entry['mode']==='read', 422, 'This endpoint requires explicit confirmation.');
        return $this->dispatch($user,$entry,$params);
    }

    public function prepare(int $admin, array $args): array
    {
        $this->admin($admin); [$entry,$params] = $this->resolve($args);
        $id = (string) Str::uuid();
        $card = ['id'=>$id,'kind'=>'native.admin','title'=>$entry['category'].'.'.$entry['action'],'summary'=>'Confirm this native administrator operation. Native validation runs on execution. Current values are not snapshotted; review concurrent changes in the native panel.','before'=>['capability'=>$entry['capability'],'method'=>$entry['method'],'path'=>$entry['path']], 'after'=>$this->sanitize($params),'expires_at'=>time()+600];
        DB::table(self::TABLE)->insert(['id'=>$id,'admin_id'=>$admin,'capability'=>$entry['capability'],'status'=>'prepared','encrypted_payload'=>Crypt::encryptString(json_encode(['args'=>['capability'=>$entry['capability'],'params'=>$params],'card'=>$card], JSON_THROW_ON_ERROR)),'expires_at'=>$card['expires_at'],'created_at'=>time()]);
        return $card;
    }

    public function execute(int $admin, string $id, string $requestId): array
    {
        $user=$this->admin($admin);
        abort_unless(Str::isUuid($id)&&Str::isUuid($requestId),422,'Invalid confirmation identity.');
        // Reservation is committed before dispatch. Never hold a transaction around native code.
        $reserved=DB::transaction(function()use($admin,$id,$requestId){
            $row=DB::table(self::TABLE)->where('id',$id)->lockForUpdate()->first();
            abort_unless($row&&(int)$row->admin_id===$admin,404,'Action not found.');
            abort_if($row->request_id!==null&&$row->request_id!==$requestId,409,'Use the original confirmation request.');
            if($row->status!=='prepared')return ['row'=>$row,'dispatch'=>false];
            abort_if((int)$row->expires_at<time(),409,'Action expired.');
            abort_if(DB::table(self::TABLE)->where('request_id',$requestId)->where('id','!=',$id)->exists(),409,'Request identity already used.');
            DB::table(self::TABLE)->where('id',$id)->update(['request_id'=>$requestId,'status'=>'dispatching']);
            return ['row'=>$row,'dispatch'=>true];
        });
        $row=$reserved['row'];
        if(!$reserved['dispatch'])return $row->encrypted_result ? json_decode(Crypt::decryptString($row->encrypted_result),true,512,JSON_THROW_ON_ERROR) : ['id'=>$id,'kind'=>'native.admin','status'=>'uncertain','code'=>'native_dispatch_already_reserved','summary'=>'Execution may have occurred. Inspect the native panel; this action will not be retried.'];
        $result=['id'=>$id,'kind'=>'native.admin'];
        try {
            $payload=json_decode(Crypt::decryptString($row->encrypted_payload),true,512,JSON_THROW_ON_ERROR);
            [$entry,$params]=$this->resolve($payload['args']);
            $response=$this->dispatch($user,$entry,$params);
            $result+=['status'=>$response['ok']?'succeeded':'failed','code'=>$response['ok']?'native_completed':'native_rejected','http_status'=>$response['http_status'],'summary'=>$response['ok']?'Native administrator operation completed.':'Native endpoint rejected the operation; inspect parameters in the native panel.'];
        } catch (\Illuminate\Validation\ValidationException | \Illuminate\Http\Exceptions\HttpResponseException $e) {
            $result+=['status'=>'failed','code'=>'native_validation_rejected','summary'=>'Native validation rejected the parameters. Review required fields in the native panel.'];
        } catch (\Throwable $e) {
            // Exceptions can follow observers/external effects. Deliberately no automatic retry.
            $result+=['status'=>'uncertain','code'=>'native_execution_uncertain','summary'=>'Execution outcome is uncertain. Inspect the native panel before preparing another action.'];
        }
        DB::table(self::TABLE)->where('id',$id)->update(['status'=>$result['status'],'encrypted_result'=>Crypt::encryptString(json_encode($result,JSON_THROW_ON_ERROR))]);
        return $result;
    }

    private function dispatch(User $user, array $entry, array $params): array
    {
        $path=$entry['path'];
        foreach($entry['_route']->parameterNames() as $name){
            abort_unless(isset($params[$name])&&is_scalar($params[$name])&&preg_match('/^[A-Za-z0-9_-]{1,100}$/',(string)$params[$name]),422,'Concrete path parameter required.');
            $path=preg_replace('/\{'.preg_quote($name,'/').'\??\}/',(string)$params[$name],$path); unset($params[$name]);
        }
        // Bound pagination independently of model-provided limit.
        foreach(['limit','page_size','pageSize','per_page','size'] as $key)if(isset($params[$key]))$params[$key]=max(1,min(50,(int)$params[$key]));
        $params['pageSize']=min(50,(int)($params['pageSize']??50));
        $request=Request::create('/'.ltrim($path,'/'),$entry['method'],$params,[],[],['HTTP_ACCEPT'=>'application/json']);
        $matched=$this->router->getRoutes()->match($request);
        abort_unless($request->getMethod()===$entry['method']&&$matched->uri()===$entry['path']&&$matched->getActionName()===$entry['_route']->getActionName(),422,'Native route changed; prepare a new action.');
        $request->setUserResolver(fn()=>$user);
        $app=app(); $previous=$app['request']; $auth=$app['auth']; $driver=$auth->getDefaultDriver(); $resolver=$auth->userResolver(); $guard=$auth->guard('sanctum'); $previousUser=$guard->user();
        $state=[];foreach(['current','currentRequest'] as $property){$reflection=new \ReflectionProperty(Router::class,$property);$reflection->setAccessible(true);$state[$property]=[$reflection,$reflection->getValue($this->router)];}
        try {
            $guard->setUser($user); $auth->shouldUse('sanctum'); $app->instance('request',$request);
            $response=$this->router->dispatch($request);
            $status=$response->getStatusCode();
            if(!$response instanceof \Illuminate\Http\JsonResponse || strlen($response->getContent())>2000000)return ['ok'=>$status<400,'http_status'=>$status,'data'=>['omitted'=>'Non-JSON or oversized native response.']];
            if($entry['category']==='Config')return ['ok'=>$status<400,'http_status'=>$status,'data'=>['omitted'=>'Configuration values are available only in the native panel.']];
            $data=$this->sanitize($response->getData(true));
            if(strlen(json_encode($data,JSON_THROW_ON_ERROR))>19000)$data=['omitted'=>'Native response exceeded 20 KB after redaction.'];
            return ['ok'=>$status<400,'http_status'=>$status,'data'=>$status<400?$data:['code'=>'native_rejected']];
        } finally {
            $previousUser ? $guard->setUser($previousUser) : $guard->forgetUser();
            $auth->shouldUse($driver); $auth->resolveUsersUsing($resolver); $app->instance('request',$previous);
            foreach($state as [$reflection,$value])$reflection->setValue($this->router,$value);
        }
    }

    private function sanitize(mixed $value, int $depth=0): mixed
    {
        if($depth>8)return '[bounded]';
        if(is_array($value)){
            // Settings are often represented as {key/name, value}; value must not bypass key filtering.
            if(isset($value['value'])&&(isset($value['key'])||isset($value['name'])))$value['value']='[redacted setting]';
            $out=[];foreach(array_slice($value,0,50,true) as $key=>$item){
                if(preg_match(self::SECRET,(string)$key)||preg_match('/config|settings|headers|environment|^(message|error|errors|email)$/i',(string)$key)){$out[$key]='[redacted]';continue;}
                $out[$key]=$this->sanitize($item,$depth+1);
            }return $out;
        }
        if(!is_string($value))return is_scalar($value)||$value===null?$value:'[omitted]';
        $value=preg_replace('/https?:\/\/[^\s"<>]+|[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}|\b[0-9a-f]{8}-[0-9a-f-]{27,}\b|-----BEGIN[\s\S]*?-----END[^\n]*|\b(?:sk-|ghp_|github_pat_)[A-Za-z0-9_-]+|\b[A-Za-z0-9_\-\/+]{32,}={0,2}\b/i','[redacted]',$value);
        return mb_strcut($value,0,500,'UTF-8');
    }
}
