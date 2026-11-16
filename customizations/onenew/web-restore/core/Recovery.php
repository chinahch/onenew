<?php
namespace App\Onenew\WebRestore;

/** Filesystem operations run as the unprivileged application user, never as root. */
final class Recovery
{
    public static function validId(string $id):bool{return (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$id);}
    public static function root(string $source):string{return $source.'/.docker/.data/onenew-web-restores';}
    public static function dir(string $source,string $id):string
    {
        if(!self::validId($id))throw new \RuntimeException('Invalid restore ID');
        foreach([$source,$source.'/.docker',$source.'/.docker/.data',self::root($source),self::root($source).'/'.$id] as $path)if(is_link($path))throw new \RuntimeException('Linked restore storage');
        return self::root($source).'/'.$id;
    }
    public static function write(string $file,string $data):void
    {
        if(is_link($file))throw new \RuntimeException('Linked output');$tmp=$file.'.'.bin2hex(random_bytes(6));
        if(file_put_contents($tmp,$data,LOCK_EX)!==strlen($data))throw new \RuntimeException('Cannot write private state');chmod($tmp,0600);
        if(!rename($tmp,$file)){@unlink($tmp);throw new \RuntimeException('Cannot publish private state');}
    }
    public static function json(string $file):array
    {
        if(is_link($file)||!is_file($file)||filesize($file)>65536)throw new \RuntimeException('Invalid state');
        $value=json_decode(file_get_contents($file),true,32,JSON_THROW_ON_ERROR);if(!is_array($value))throw new \RuntimeException('Invalid state');return $value;
    }
    public static function state(string $dir,array $changes):array
    {
        $state=self::json($dir.'/status.json');$state=array_merge($state,$changes,['updated_at'=>time()]);self::write($dir.'/status.json',json_encode($state,JSON_THROW_ON_ERROR));return $state;
    }
    public static function key(string $environment):string
    {
        // Production callers run as www and use exactly Laravel's dotenv parser.
        // The root coordinator obtains this value over a private child pipe instead.
        if(class_exists(\Dotenv\Dotenv::class)){
            $parsed=\Dotenv\Dotenv::parse($environment);$key=$parsed['APP_KEY']??null;
        }else{
            // Portable core/test fallback for literal generated keys, including CRLF/BOM.
            if(str_starts_with($environment,"\xef\xbb\xbf"))$environment=substr($environment,3);
            if(!preg_match_all('/^[ \t]*(?:export[ \t]+)?APP_KEY[ \t]*=[ \t]*([^\r\n]*)\r?$/m',$environment,$matches))throw new \RuntimeException('Missing application key');
            $value=trim(end($matches[1]));
            if(preg_match('/^([\'"])(.*?)\1[ \t]*(?:#.*)?$/D',$value,$quoted))$key=$quoted[1]==='"'?stripcslashes($quoted[2]):$quoted[2];
            else $key=rtrim(preg_split('/[ \t]+#/',$value,2)[0]);
        }
        $bytes=is_string($key)?(str_starts_with($key,'base64:')?base64_decode(substr($key,7),true):$key):false;
        if(!is_string($bytes)||strlen($bytes)!==32)throw new \RuntimeException('Invalid application key');return $key;
    }
    public static function environmentWithKey(string $environment,string $key):string
    {
        $count=0;
        $next=preg_replace_callback('/^(\xef\xbb\xbf)?[ \t]*(?:export[ \t]+)?APP_KEY[ \t]*=[^\r\n]*(\r?)$/m',function($match)use($key,&$count){return ($match[1]??'').(++$count===1?'APP_KEY='.$key:'# duplicate APP_KEY removed').($match[2]??'');},$environment);
        if($count<1||self::key($next)!==$key)throw new \RuntimeException('Cannot update application key');return $next;
    }
    public static function copyTree(string $from,string $to):void
    {
        if(is_link($from)||is_link($to))throw new \RuntimeException('Linked resource');
        if(is_dir($from)){
            if(!is_dir($to)&&!mkdir($to,0700,true))throw new \RuntimeException('Cannot create resource directory');
            foreach(new \DirectoryIterator($from) as $entry)if(!$entry->isDot())self::copyTree($entry->getPathname(),$to.'/'.$entry->getFilename());
        }elseif(is_file($from)){
            if(!is_dir(dirname($to))&&!mkdir(dirname($to),0700,true))throw new \RuntimeException('Cannot create resource parent');
            if(!copy($from,$to))throw new \RuntimeException('Cannot copy resource');chmod($to,(fileperms($from)&0100)?0700:0600);
        }else throw new \RuntimeException('Unsupported resource');
    }
    public static function clear(string $path):void
    {
        if(is_link($path))throw new \RuntimeException('Linked resource');if(!file_exists($path))return;
        if(is_dir($path)){foreach(new \DirectoryIterator($path) as $e)if(!$e->isDot())self::clear($e->getPathname());if(!rmdir($path))throw new \RuntimeException('Cannot remove directory');}
        elseif(!is_file($path)||!unlink($path))throw new \RuntimeException('Cannot remove file');
    }
    public static function validateStage(string $stage,array $knownMigrations):array
    {
        $env=$stage.'/.env';if(is_link($env)||!is_file($env)||filesize($env)>1048576)throw new \RuntimeException('Invalid environment');self::key(file_get_contents($env));
        $folder=$stage.'/data/database';$files=[];
        if(!is_dir($folder)||is_link($folder))throw new \RuntimeException('Missing database');
        foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder,\FilesystemIterator::SKIP_DOTS)) as $f){if($f->isLink()||!$f->isFile())throw new \RuntimeException('Invalid database resource');$files[]=$f->getPathname();}
        if(count($files)!==1)throw new \RuntimeException('Expected one SQLite database');$db=$files[0];
        $pdo=new \PDO('sqlite:'.$db);$pdo->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
        if($pdo->query('PRAGMA quick_check')->fetchColumn()!=='ok')throw new \RuntimeException('Database check failed');
        $tables=$pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach(['v2_user','v2_server','v2_settings','migrations'] as $table)if(!in_array($table,$tables,true))throw new \RuntimeException('Unsupported database structure');
        $recorded=$pdo->query('SELECT migration FROM migrations')->fetchAll(\PDO::FETCH_COLUMN);
        if(array_diff($recorded,$knownMigrations))throw new \RuntimeException('Backup requires newer or unavailable migrations');
        $counts=[];foreach(['users'=>'v2_user','nodes'=>'v2_server','machines'=>'v2_server_machine','landings'=>'onenew_landings'] as $label=>$table)$counts[$label]=in_array($table,$tables,true)?(int)$pdo->query('SELECT COUNT(*) FROM "'.$table.'"')->fetchColumn():0;
        $pdo=null;return ['database'=>$db,'counts'=>$counts];
    }
    public static function databasePath(string $source,string $relative):string
    {
        if(!preg_match('~^[A-Za-z0-9_.-]+(?:/[A-Za-z0-9_.-]+)*$~D',$relative)||preg_match('~(^|/)\.\.?(/|$)~',$relative)||str_starts_with($relative,'onenew-'))throw new \RuntimeException('Invalid target database path');
        $path=$source.'/.docker/.data/'.$relative;$parent=dirname($path);
        if(is_link($path)||!realpath($parent)||!str_starts_with(realpath($parent).DIRECTORY_SEPARATOR,realpath($source.'/.docker/.data').DIRECTORY_SEPARATOR))throw new \RuntimeException('Unsafe database path');return $path;
    }
    public static function snapshot(string $source,string $relative,string $dir,string $password,string $revision):void
    {
        $required=filesize($source.'/.env')+filesize(self::databasePath($source,$relative));
        foreach(['plugins','storage/theme'] as $resource)if(is_dir($source.'/'.$resource))foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source.'/'.$resource,\FilesystemIterator::SKIP_DOTS)) as $file){if($file->isLink()||!$file->isFile())throw new \RuntimeException('Unsupported rollback resource');$required+=$file->getSize();}
        if($required>2147483648||disk_free_space($dir)<$required*2+67108864)throw new \RuntimeException('Insufficient rollback space');
        $stage=$dir.'/rollback';if(file_exists($stage))throw new \RuntimeException('Rollback already exists');mkdir($stage,0700);
        self::copyTree($source.'/.env',$stage.'/.env');$database=self::databasePath($source,$relative);mkdir(dirname($stage.'/data/database/'.$relative),0700,true);
        $pdo=new \PDO('sqlite:'.$database);$pdo->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);$pdo->exec('PRAGMA busy_timeout=30000');$pdo->exec('VACUUM INTO '.$pdo->quote($stage.'/data/database/'.$relative));$pdo=null;
        foreach(['plugins','storage/theme'] as $resource)if(is_dir($source.'/'.$resource))self::copyTree($source.'/'.$resource,$stage.'/'.$resource);
        \App\Onenew\Migration\MigrationBundle::pack($stage,$dir.'/rollback.onm',$password,$revision);
        self::write($dir.'/rollback.json',json_encode(['database_relative'=>$relative],JSON_THROW_ON_ERROR));
    }
    private static function replaceDatabase(string $from,string $destination):void
    {
        foreach([$destination,$destination.'-wal',$destination.'-shm'] as $path)if(is_link($path))throw new \RuntimeException('Linked database');
        $tmp=$destination.'.restore-'.bin2hex(random_bytes(6));self::copyTree($from,$tmp);
        foreach(['-wal','-shm'] as $suffix)if(is_file($destination.$suffix)&&!unlink($destination.$suffix))throw new \RuntimeException('Cannot remove SQLite journal');
        if(!rename($tmp,$destination)){@unlink($tmp);throw new \RuntimeException('Cannot switch database');}
    }
    private static function replaceResources(string $source,string $stage):void
    {
        foreach(['plugins','storage/theme'] as $resource){$target=$source.'/'.$resource;
            if(is_link($target)||!is_dir($target))throw new \RuntimeException('Unsupported resource mount');
            foreach(new \DirectoryIterator($target) as $entry)if(!$entry->isDot())self::clear($entry->getPathname());
            if(is_dir($stage.'/'.$resource))self::copyTree($stage.'/'.$resource,$target);
        }
    }
    private static function environment(string $path,string $data):void
    {
        if(is_link($path)||!is_file($path)||!is_writable($path))throw new \RuntimeException('Environment is not writable');
        $f=fopen($path,'c+b');try{if(!flock($f,LOCK_EX)||!ftruncate($f,0)||fwrite($f,$data)!==strlen($data)||!fflush($f))throw new \RuntimeException('Cannot switch key');}finally{fclose($f);}
    }
    public static function apply(string $source,string $relative,string $dir,string $stage,string $database):void
    {
        self::write($dir.'/switching','1');$key=self::key(file_get_contents($stage.'/.env'));$env=file_get_contents($source.'/.env');
        $env=self::environmentWithKey($env,$key);
        self::replaceDatabase($database,self::databasePath($source,$relative));self::replaceResources($source,$stage);self::environment($source.'/.env',$env);
        $pdo=new \PDO('sqlite:'.self::databasePath($source,$relative));$pdo->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
        $tables=$pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
        foreach(['personal_access_tokens','sessions','jobs','failed_jobs'] as $table)if(in_array($table,$tables,true))$pdo->exec('DELETE FROM "'.$table.'"');
        if(in_array('onenew_cloud_backup_config',$tables,true))$pdo->exec('UPDATE onenew_cloud_backup_config SET enabled=0,next_run_at=NULL');
        if(in_array('onenew_cloud_backup_runs',$tables,true))$pdo->exec("UPDATE onenew_cloud_backup_runs SET state='paused',encrypted_config=NULL,encrypted_session=NULL,lease_token=NULL,lease_until=NULL WHERE state IN ('queued','generating','uploading','verifying')");
        $pdo=null;
    }
    public static function rollback(string $source,string $dir):void
    {
        $meta=self::json($dir.'/rollback.json');$stage=$dir.'/rollback';
        self::replaceDatabase($stage.'/data/database/'.$meta['database_relative'],self::databasePath($source,$meta['database_relative']));
        self::replaceResources($source,$stage);self::environment($source.'/.env',file_get_contents($stage.'/.env'));if(is_file($dir.'/switching')&&!unlink($dir.'/switching'))throw new \RuntimeException('Cannot finish rollback');
    }
}
