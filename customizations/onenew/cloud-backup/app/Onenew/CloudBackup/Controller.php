<?php
namespace App\Onenew\CloudBackup;
use Illuminate\Http\Request;
use App\Onenew\Migration\MigrationService;
final class Controller
{
    public function info(Service $service,MigrationService $migration){return response()->json($service->view()+['supported'=>$migration->info()['supported']])->header('Cache-Control','no-store');}
    public function storageUsage(StorageUsage $usage,MigrationService $migration)
    {
        try{[$database]=$migration->database();}
        catch(\RuntimeException){return response()->json(['message'=>'当前部署不支持检查数据大小，需要使用官方 SQLite 持久化目录。'],422)->header('Cache-Control','no-store');}
        try{return response()->json($usage->inspect($migration->source(),$database))->header('Cache-Control','no-store');}
        catch(\Throwable){return response()->json(['message'=>'暂时无法读取当前数据大小，请稍后重试。'],503)->header('Cache-Control','no-store');}
    }
    public function save(Request $request,Config $config,Service $service)
    {
        $data=$request->validate(['enabled'=>'sometimes|boolean','daily_time'=>['sometimes','required','regex:/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D'],'client_id'=>'sometimes|nullable|string|max:512','client_secret'=>['sometimes','nullable','string','max:16384','regex:/^[^\x00-\x20\x7f]+$/D'],'refresh_token'=>['sometimes','nullable','string','max:16384','regex:/^[^\x00-\x20\x7f]+$/D'],'folder_id'=>['sometimes','nullable','string','max:200','regex:/^[A-Za-z0-9_-]+$/D'],'password'=>'sometimes|nullable|string|confirmed','password_confirmation'=>'sometimes|nullable|string']);
        if(!empty($data['password']))abort_unless(strlen($data['password'])>=12&&strlen($data['password'])<=128&&!preg_match('/[\x00-\x1f\x7f]/',$data['password']),422,'备份密码需12–128字节且不含控制字符。');
        $config->save($data);return response()->json($service->view())->header('Cache-Control','no-store');
    }
    public function connection(Config $config)
    {
        try{$row=$config->row();$secret=$config->secrets($row);if(!$config->ready($secret))throw new BackupFailure('not_configured');$folder=(new DriveClient($secret))->ensureFolder();$config->rememberFolder($folder,(int)$row['version']);return response()->json(['ok'=>true,'folder_id'=>$folder])->header('Cache-Control','no-store');}
        catch(BackupFailure $e){return response()->json(['message'=>BackupFailure::message($e->reason)],422);}
    }
    public function run(Service $service,MigrationService $migration)
    {
        abort_unless($migration->info()['supported'],422,'当前部署不支持生成迁移包。');
        try{$id=$service->enqueue('manual');return response()->json(['id'=>$id])->header('Cache-Control','no-store');}
        catch(BackupFailure $e){return response()->json(['message'=>BackupFailure::message($e->reason)],422);}
    }
}
