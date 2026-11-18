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
    private array $resourceReferences=[];
    private const TABLE = 'onenew_ai_native_actions';
    private const READS = ['fetch','list','get','getNodes','nodes','history','detail','getUserInfoById','getOverride','getStats','getServerLastRank','getServerYesterdayRank','getOrder','getStatUser','getRanking','getStatRecord','getTrafficRank','templates','codes','usages','statistics','types','getCategory','getPaymentMethods','getSystemStatus','getQueueStats','getQueueWorkload','getHorizonFailedJobs','getAuditLog','getThemes','getThemeConfig','logs','stats','userHistory','getEmailTemplate','getThemeTemplate'];
    private const CLASS_READS = [
        'App\\Onenew\\RuntimeLogs\\Controller'=>['index'],
        'App\\Onenew\\CloudBackup\\Controller'=>['info'],
        'App\\Onenew\\WebRestore\\Controller'=>['info','status'],
        'App\\Onenew\\External\\ExternalController'=>['index','lookups'],
        'App\\Onenew\\LandingLibrary\\LandingController'=>['index','show'],
        'App\\Onenew\\Energy\\EnergyController'=>['adminIndex','attention'],
        'App\\Http\\Controllers\\V2\\Admin\\PluginController'=>['index'],
    ];
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
            // gatherMiddleware() instantiates controllers (some open Redis or perform setup).
            // Discovery trusts only explicitly registered route/group admin middleware.
            if (!in_array('admin', $route->middleware(), true) && !in_array(\App\Http\Middleware\Admin::class, $route->middleware(), true)) continue;
            foreach (array_intersect(['GET','POST','PUT','PATCH','DELETE'], $route->methods()) as $verb) {
                $id = hash('sha256', $verb.'|'.$route->uri().'|'.$class.'@'.$method);
                $parts = explode('/', $route->uri());
                $category=preg_replace('/Controller$/','',class_basename($class));
                if($category===''){$segments=explode('\\',$class);$category=$segments[count($segments)-2];}
                $read=in_array($method,self::READS,true)||in_array($method,self::CLASS_READS[$class]??[],true);
                $out[$id] = ['capability'=>$id, 'method'=>$verb, 'action'=>$method, 'path'=>$route->uri(), 'category'=>$category, 'mode'=>$read ? 'read' : 'confirm', 'schema'=>['params'=>['type'=>'object','description'=>'Native endpoint parameters; native FormRequest validation is authoritative. Credentials and role changes require the native UI.','validation_hints'=>$this->ruleHints($class,$method)],'path_parameters'=>$route->parameterNames()], '_route'=>$route];
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
        abort_if(count(array_diff(array_keys($args),['category','search','keyword','offset','limit']))>0,422,'Unknown catalog parameters.');
        $all = array_values($this->registry());
        $categories = array_values(array_unique(array_column($all, 'category'))); sort($categories);
        abort_unless((!isset($args['category'])||is_string($args['category']))&&(!isset($args['keyword'])||is_string($args['keyword']))&&(!isset($args['search'])||is_string($args['search'])),422,'Invalid catalog search.');
        $keyword = mb_substr((string)($args['keyword'] ?? $args['search'] ?? ''), 0, 100);
        $category=strtolower(trim($args['category']??''));
        $all = array_values(array_filter($all, fn($r)=> ($category==='' || $category===strtolower($r['category']) || in_array($category,explode('/',strtolower($r['path'])),true)) && ($keyword==='' || stripos($r['category'].' '.$r['action'].' '.$r['path'], $keyword)!==false)));
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
        if(isset($args['params'])&&is_array($args['params']))$args['params']=$this->resolveReferences($args['params'],$admin);
        $user = $this->admin($admin); [$entry,$params] = $this->resolve($args);
        abort_unless($entry['mode']==='read', 422, 'This endpoint requires explicit confirmation.');
        return $this->dispatch($user,$entry,$params);
    }

    public function prepare(int $admin, array $args): array
    {
        if(isset($args['params'])&&is_array($args['params']))$args['params']=$this->resolveReferences($args['params'],$admin);
        $this->admin($admin); [$entry,$params] = $this->resolve($args);
        $snapshots=$this->snapshots($entry,$params);
        $id = (string) Str::uuid();
        $card = ['id'=>$id,'kind'=>'native.admin','title'=>$entry['category'].'.'.$entry['action'],'summary'=>'请确认此原生管理操作及参数。已固定处理程序版本和已识别目标的身份、配置；执行时再次核对并使用原生验证。','before'=>['capability'=>$entry['capability'],'method'=>$entry['method'],'path'=>$entry['path'],'snapshot_available'=>count($snapshots)>0,'target_ids'=>array_column($snapshots,'id')], 'after'=>$this->sanitize($params,0,$admin),'expires_at'=>time()+600];
        DB::table(self::TABLE)->insert(['id'=>$id,'admin_id'=>$admin,'capability'=>$entry['capability'],'status'=>'prepared','encrypted_payload'=>Crypt::encryptString(json_encode(['args'=>['capability'=>$entry['capability'],'params'=>$params],'card'=>$card,'snapshots'=>$snapshots,'handler_hash'=>$this->handlerHash($entry)], JSON_THROW_ON_ERROR)),'expires_at'=>$card['expires_at'],'created_at'=>time()]);
        return $card;
    }

    private function resolveReferences(array $params,int $admin,bool $identity=false): array
    {
        $out=[];
        foreach($params as $key=>$value){
            $isIdentity=$identity||in_array($key,['onenew_identity','machine_identity','node_identity','node_identities','identity'],true);
            if(is_array($value))$out[$key]=$this->resolveReferences($value,$admin,$isIdentity);
            elseif(is_string($value)&&str_starts_with($value,'resource_ref_')){
                $ref=$this->resourceReferences[$value]??null;
                abort_unless($isIdentity&&$ref&&$ref['admin']===$admin,422,'Resource reference expired or belongs to another administrator; read the native resource again.');
                $out[$key]=$ref['value'];
            }else{abort_if($isIdentity&&$value!==null,422,'Resource identities must use references observed in this task.');$out[$key]=$value;}
        }
        return $out;
    }

    private function resourceReference(mixed $value,int $admin): mixed
    {
        if(is_array($value)){foreach($value as &$item)$item=$this->resourceReference($item,$admin);return $value;}
        if(!is_string($value)||!Str::isUuid($value))return '[redacted]';
        // Only native node/machine generation identities can be referenced; never account UUIDs.
        $known=\App\Models\Server::where('onenew_identity',$value)->exists()||\App\Models\ServerMachine::where('onenew_identity',$value)->exists();
        if(!$known)return '[redacted]';
        foreach($this->resourceReferences as $ref=>$record)if($record['admin']===$admin&&$record['value']===$value)return $ref;
        if(count($this->resourceReferences)>=256)return '[bounded]';
        $ref='resource_ref_'.bin2hex(random_bytes(10));$this->resourceReferences[$ref]=['admin'=>$admin,'value'=>$value];return $ref;
    }

    private function handlerHash(array $entry): string
    {
        [$class]=explode('@',$entry['_route']->getActionName(),2);
        $file=(new \ReflectionClass($class))->getFileName();
        abort_unless($file&&is_file($file),422,'Native handler unavailable.');
        return hash_file('sha256',$file);
    }

    private function fingerprint(array $row): string
    {
        foreach(['updated_at','u','d','last_seen_at','load_status','last_check_at','last_push_at','online','online_conn','metrics','last_login_at'] as $live)unset($row[$live]);
        ksort($row);return hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));
    }

    /** Unsupported target identities fail closed rather than following a recycled numeric ID. */
    private function snapshots(array $entry,array $params): array
    {
        if($entry['mode']==='read')return [];
        $models=['User'=>\App\Models\User::class,'Plan'=>\App\Models\Plan::class,'Manage'=>\App\Models\Server::class,'Machine'=>\App\Models\ServerMachine::class,'Group'=>\App\Models\ServerGroup::class,'Route'=>\App\Models\ServerRoute::class,'Order'=>\App\Models\Order::class,'Notice'=>\App\Models\Notice::class,'Ticket'=>\App\Models\Ticket::class,'Coupon'=>\App\Models\Coupon::class,'Knowledge'=>\App\Models\Knowledge::class,'Payment'=>\App\Models\Payment::class,'MailTemplate'=>\App\Models\MailTemplate::class,'Plugin'=>\App\Models\Plugin::class];
        $primary=isset($models[$entry['category']])?(new $models[$entry['category']])->getTable():null;
        if($entry['category']==='Landing')$primary=\App\Onenew\LandingLibrary\Library::TABLE;
        if($entry['category']==='External')$primary=\App\Onenew\External\ExternalNodes::TABLE;
        if($entry['category']==='Energy')$primary='onenew_energy_requests';
        if($entry['category']==='GiftCard')$primary=(new (str_contains(strtolower($entry['action']),'template')?\App\Models\GiftCardTemplate::class:\App\Models\GiftCardCode::class))->getTable();
        $foreign=['node_id'=>\App\Models\Server::class,'nodeId'=>\App\Models\Server::class,'server_id'=>\App\Models\Server::class,'machine_id'=>\App\Models\ServerMachine::class,'machineId'=>\App\Models\ServerMachine::class,'user_id'=>\App\Models\User::class,'userId'=>\App\Models\User::class,'plan_id'=>\App\Models\Plan::class,'group_id'=>\App\Models\ServerGroup::class,'template_id'=>\App\Models\GiftCardTemplate::class];
        foreach(['node_ids'=>'node_id','server_ids'=>'server_id','machine_ids'=>'machine_id','user_ids'=>'user_id','plan_ids'=>'plan_id','group_ids'=>'group_id','invite_user_id'=>'user_id'] as $plural=>$singular)$foreign[$plural]=$foreign[$singular];
        $foreign['route_id']=\App\Models\ServerRoute::class;$foreign['route_ids']=\App\Models\ServerRoute::class;
        $targets=[];
        $collect=function(array $values,int $depth=0)use(&$collect,&$targets,$primary,$foreign){
            foreach($values as $key=>$value){
                if($key==='node_identities'&&is_array($value)){
                    $table=(new \App\Models\Server)->getTable();
                    foreach(array_keys($value) as $nodeId){abort_unless(ctype_digit((string)$nodeId)&&(int)$nodeId>0,422,'Concrete node IDs required.');$targets[$table.':'.$nodeId]=['table'=>$table,'id'=>$nodeId];}
                    continue;
                }
                $table=isset($foreign[$key])?(new $foreign[$key])->getTable():null;
                if(in_array($key,['id','ids','parent_id'],true))$table=$primary;
                $isIdentity=in_array($key,['id','ids'],true)||isset($foreign[$key])||preg_match('/(_id|_ids|Id|Ids)$/',(string)$key);
                if($isIdentity&&$value!==null&&$value!==''&&$value!==0&&$value!=='0'){
                    abort_unless($table,422,'This target identity requires review in the native panel UI.');
                    foreach(is_array($value)?$value:[$value] as $id){abort_unless(is_scalar($id)&&preg_match('/^[A-Za-z0-9_-]{1,100}$/',(string)$id),422,'Concrete target IDs required.');$targets[$table.':'.$id]=['table'=>$table,'id'=>$id];}
                }elseif(is_array($value))$collect($value,$depth+1);
            }
        };$collect($params);
        abort_if(count($targets)>100,422,'Too many target identities; use the native panel.');
        if(preg_match('/delete|destroy|drop|batch|sort/i',$entry['action']))abort_unless(count($targets)>0,422,'Destructive bulk operations require explicit supported target IDs; use the native panel.');
        foreach($targets as &$target){$row=DB::table($target['table'])->where('id',$target['id'])->first();abort_unless($row,422,'Target does not exist.');$target['hash']=$this->fingerprint((array)$row);}
        return array_values($targets);
    }

    private function verifyPayload(array $payload): array
    {
        [$entry,$params]=$this->resolve($payload['args']);
        abort_unless(hash_equals($payload['handler_hash'],$this->handlerHash($entry)),409,'Native handler changed; prepare a new action.');
        foreach($payload['snapshots'] as $snapshot){$row=DB::table($snapshot['table'])->where('id',$snapshot['id'])->first();abort_unless($row&&hash_equals($snapshot['hash'],$this->fingerprint((array)$row)),409,'Target changed or was replaced; prepare a new action.');}
        return [$entry,$params];
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
            $payload=json_decode(Crypt::decryptString($row->encrypted_payload),true,512,JSON_THROW_ON_ERROR);
            $this->verifyPayload($payload);
            abort_if(DB::table(self::TABLE)->where('request_id',$requestId)->where('id','!=',$id)->exists(),409,'Request identity already used.');
            DB::table(self::TABLE)->where('id',$id)->update(['request_id'=>$requestId,'status'=>'dispatching']);
            return ['row'=>$row,'dispatch'=>true];
        });
        $row=$reserved['row'];
        if(!$reserved['dispatch'])return $row->encrypted_result ? json_decode(Crypt::decryptString($row->encrypted_result),true,512,JSON_THROW_ON_ERROR) : ['id'=>$id,'kind'=>'native.admin','status'=>'uncertain','code'=>'native_dispatch_already_reserved','summary'=>'Execution may have occurred. Inspect the native panel; this action will not be retried.'];
        $result=['id'=>$id,'kind'=>'native.admin'];
        try {
            $payload=json_decode(Crypt::decryptString($row->encrypted_payload),true,512,JSON_THROW_ON_ERROR);
            [$entry,$params]=$this->verifyPayload($payload);
            $user=$this->admin($admin);
            $response=$this->dispatch($user,$entry,$params);
            $result+=['status'=>!empty($response['ambiguous'])?'uncertain':($response['ok']?'succeeded':'failed'),'code'=>$response['ok']?'native_completed':'native_rejected','http_status'=>$response['http_status'],'summary'=>$response['ok']?'原生管理操作已完成。':'原生接口未确认成功，请先在原生面板核对，再决定是否重新创建操作。'];
            if(isset($response['target_id']))$result['target_id']=$response['target_id'];
        } catch (\Illuminate\Validation\ValidationException | \Illuminate\Http\Exceptions\HttpResponseException $e) {
            $errors=$e instanceof \Illuminate\Validation\ValidationException?$e->errors():($e->getResponse() instanceof \Illuminate\Http\JsonResponse?($e->getResponse()->getData(true)['errors']??[]):[]);
            $fields=is_array($errors)?array_values(array_filter(array_keys($errors),fn($key)=>is_string($key)&&preg_match('/^[A-Za-z0-9_.-]{1,80}$/',$key))):[];
            $result+=['status'=>'failed','code'=>'native_validation_rejected','validation_fields'=>array_slice($fields,0,30),'summary'=>'原生参数验证未通过，请在原生面板核对必填字段。'];
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
        if($entry['mode']==='read'){
            foreach(['limit','page_size','pageSize','per_page','size'] as $key)if(isset($params[$key]))$params[$key]=max(1,min(50,(int)$params[$key]));
            $params['pageSize']=min(50,(int)($params['pageSize']??50));
        }
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
            if(!$response instanceof \Illuminate\Http\JsonResponse || strlen($response->getContent())>2000000)return ['ok'=>false,'ambiguous'=>true,'http_status'=>$status,'data'=>['omitted'=>'Non-JSON or oversized native response.']];
            if($status>=300&&$status<400)return ['ok'=>false,'ambiguous'=>true,'http_status'=>$status,'data'=>['omitted'=>'Native redirect requires review in the panel.']];
            if($entry['category']==='Config')return ['ok'=>$status<400,'http_status'=>$status,'data'=>['omitted'=>'Configuration values are available only in the native panel.']];
            $data=$this->sanitize($response->getData(true),0,(int)$user->id);
            if(strlen(json_encode($data,JSON_THROW_ON_ERROR))>19000)$data=['omitted'=>'Native response exceeded 20 KB after redaction.'];
            $result=['ok'=>$status>=200&&$status<300,'http_status'=>$status,'data'=>$status<400?$data:['code'=>'native_rejected']];
            $target=$response->getData(true)['data']['id']??null;
            if((is_int($target)||is_string($target))&&ctype_digit((string)$target)&&(int)$target>0)$result['target_id']=(int)$target;
            return $result;
        } finally {
            $previousUser ? $guard->setUser($previousUser) : $guard->forgetUser();
            $auth->shouldUse($driver); $auth->resolveUsersUsing($resolver); $app->instance('request',$previous);
            foreach($state as [$reflection,$value])$reflection->setValue($this->router,$value);
        }
    }

    private function sanitize(mixed $value, int $depth=0,?int $admin=null): mixed
    {
        if($depth>8)return '[bounded]';
        if(is_array($value)){
            // Settings are often represented as {key/name, value}; value must not bypass key filtering.
            if(isset($value['value'])&&(isset($value['key'])||isset($value['name'])))$value['value']='[redacted setting]';
            $out=[];foreach(array_slice($value,0,50,true) as $key=>$item){
                if($admin!==null&&in_array($key,['onenew_identity','machine_identity','node_identity','node_identities','identity'],true)){$out[$key]=$this->resourceReference($item,$admin);continue;}
                if(preg_match(self::SECRET,(string)$key)||preg_match('/config|settings|headers|environment|(^|_)(link|url|uri|code|codes|voucher|vouchers)($|_)|codes?$|^(message|error|errors|email)$/i',(string)$key)){$out[$key]='[redacted]';continue;}
                $out[$key]=$this->sanitize($item,$depth+1,$admin);
            }return $out;
        }
        if(!is_string($value))return is_scalar($value)||$value===null?$value:'[omitted]';
        if(preg_match('~[a-z][a-z0-9+.-]*://|BEGIN .*PRIVATE KEY|(?:password|private_key|api_key|authorization|token)\s*[:=]~i',$value))return '[redacted]';
        $value=preg_replace('/https?:\/\/[^\s"<>]+|[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}|\b[0-9a-f]{8}-[0-9a-f-]{27,}\b|-----BEGIN[\s\S]*?-----END[^\n]*|\b(?:sk-|ghp_|github_pat_)[A-Za-z0-9_-]+|\b[A-Za-z0-9_\-\/+]{32,}={0,2}\b/i','[redacted]',$value);
        return mb_strcut($value,0,500,'UTF-8');
    }
}
