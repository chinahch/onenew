<?php
// Exercise the real service's early guard without booting Laravel/Redis.
namespace App\Models {class ServerMachine {public $id=1;public $onenew_identity='fixture';public $token='fixture';}}
namespace Illuminate\Support\Facades {
 class Schema {public static $missing;public static function hasTable($name){return $name!==self::$missing;}}
 class DB {public static function table($name){throw new \RuntimeException('Log query reached database before schema guard: '.$name);}}
}
namespace Illuminate\Http\Exceptions {class HttpResponseException extends \RuntimeException {public function __construct(public $response){parent::__construct('HTTP response');}}}
namespace {
 function response(){return new class {public function json($data,$status){return new class($data,$status){public function __construct(public $data,public $status){}public function header($key,$value){return $this;}};}};}
 function abort($response){throw new \Illuminate\Http\Exceptions\HttpResponseException($response);}
 require __DIR__.'/../app/Onenew/RuntimeLogs/Service.php';
 foreach(['entries','streams','batches','reads'] as $suffix){
  \Illuminate\Support\Facades\Schema::$missing='onenew_runtime_log_'.$suffix;
  try{(new \App\Onenew\RuntimeLogs\Service)->query(new \App\Models\ServerMachine,[],1);throw new \RuntimeException('Missing schema was accepted');}
  catch(\Illuminate\Http\Exceptions\HttpResponseException $e){if($e->response->status!==503||$e->response->data['code']!=='runtime_logs_schema_missing'||!str_contains($e->response->data['message'],'php artisan migrate --force'))throw new \RuntimeException('Missing schema response is not actionable');}
 }
 \Illuminate\Support\Facades\Schema::$missing=null;
 try{(new \App\Onenew\RuntimeLogs\Service)->query(new \App\Models\ServerMachine,[],1);throw new \RuntimeException('Query did not run');}
 catch(\RuntimeException $e){if(!str_starts_with($e->getMessage(),'Log query reached database'))throw $e;}
 echo "Runtime log schema guard covers four tables before DB reads and preserves unrelated failures\n";
}
