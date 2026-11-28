<?php
namespace App\Onenew\Migration;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class MigrationService
{
    public const TTL=3600;
    public const LOCK='onenew:migration:export';
    public const EPHEMERAL_TABLES=[
        'v2_onenew_traffic_receipts',
        'v2_onenew_domain_events',
        'v2_onenew_domain_batches',
        'onenew_runtime_log_entries',
        'onenew_runtime_log_batches',
    ];
    public function source(): string { return app()->environment('testing')?config('onenew.migration_source',base_path()):base_path(); }
    public function root(): string { return $this->source().'/.docker/.data/onenew-migrations'; }
    public function revision(): string
    {
        $revision=app()->environment('testing')?config('onenew.migration_revision',trim(file_get_contents('/opt/onenew/REVISION'))):trim(file_get_contents('/opt/onenew/REVISION'));
        if(!preg_match('/^[a-f0-9]{40}$/D',$revision))throw new \RuntimeException('Migration requires a versioned deployment');
        return $revision;
    }
    public function database(): array
    {
        $driver=config('database.connections.'.config('database.default').'.driver');
        if($driver!=='sqlite')throw new \RuntimeException('Only the official SQLite deployment is supported');
        $path=app()->environment('testing')?config('onenew.migration_database',config('database.connections.sqlite.database')):config('database.connections.sqlite.database');
        $root=realpath($this->source().'/.docker/.data');$database=realpath($path);
        if(!$root||!$database||is_link($path)||!str_starts_with($database,$root.DIRECTORY_SEPARATOR)||!is_file($database))throw new \RuntimeException('Database is outside the supported persistent directory');
        $relative=str_replace(DIRECTORY_SEPARATOR,'/',substr($database,strlen($root)+1));
        if(str_starts_with($relative,'onenew-migrations/'))throw new \RuntimeException('Invalid database path');
        return [$database,$relative];
    }
    public function info(): array
    {
        try{$revision=$this->revision();$this->database();$this->environmentFile();
            return ['supported'=>true,'revision'=>$revision,'reason'=>'','max_bytes'=>2147483648,'expires_seconds'=>self::TTL];
        }catch(\Throwable){return ['supported'=>false,'revision'=>null,'reason'=>'需要使用带版本信息的官方 SQLite Docker 部署，并确保应用密钥及持久化目录可读取。'];}
    }
    public function environmentFile(): string
    {
        $path=$this->source().'/.env';
        if(is_link($path)||!is_file($path)||filesize($path)>1048576)throw new \RuntimeException('Invalid environment file');
        $env=\Dotenv\Dotenv::parse(file_get_contents($path));
        if(empty($env['APP_KEY'])||!hash_equals((string)config('app.key'),(string)$env['APP_KEY']))throw new \RuntimeException('Environment key does not match runtime key');
        return $path;
    }
    public function directory(string $id): string
    {
        if(!Str::isUuid($id))throw new \RuntimeException('Invalid migration ID');
        return $this->root().'/'.$id;
    }
    public function status(string $id): ?array { return Cache::store('redis')->get('onenew:migration:job:'.$id); }
    public function save(array $state): void { Cache::store('redis')->put('onenew:migration:job:'.$state['id'],$state,self::TTL); }
    public function phase(string $id,string $phase): void
    {
        $state=$this->status($id);if(!$state)throw new \RuntimeException('Migration expired');
        $state['state']=$phase;$this->save($state);
    }
    public function prepare(string $id): string
    {
        $root=$this->root();if(is_link($root))throw new \RuntimeException('Invalid migration storage');
        if(!is_dir($root)&&!mkdir($root,0700,true))throw new \RuntimeException('Migration directory unavailable');
        chmod($root,0700);$dir=$this->directory($id);
        if(file_exists($dir)||!mkdir($dir,0700))throw new \RuntimeException('Migration already exists');
        return $dir;
    }
    public function snapshot(string $id): string
    {
        [$database,$relative]=$this->database();$environment=$this->environmentFile();
        $stage=$this->directory($id).'/stage';if(!mkdir($stage,0700))throw new \RuntimeException('Snapshot already exists');
        $this->copyFile($environment,$stage.'/.env');
        $snapshot=$stage.'/data/database/'.$relative;$this->parents($snapshot);
        if(disk_free_space($stage)<67108864+filesize($database)*3)throw new \RuntimeException('Insufficient snapshot space');
        $this->phase($id,'database');
        $pdo=new \PDO('sqlite:'.$database);$pdo->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA busy_timeout=30000');$pdo->exec('VACUUM INTO '.$pdo->quote($snapshot));chmod($snapshot,0600);
        $pdo=null;
        $snap=new \PDO('sqlite:'.$snapshot);$snap->setAttribute(\PDO::ATTR_ERRMODE,\PDO::ERRMODE_EXCEPTION);
        $existing=$snap->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(\PDO::FETCH_COLUMN);
        $stripped=false;
        foreach(self::EPHEMERAL_TABLES as $tbl){
            if(in_array($tbl,$existing,true)){$snap->exec('DELETE FROM "'.$tbl.'"');$stripped=true;}
        }
        if($stripped)$snap->exec('VACUUM');
        $snap=null;
        $this->phase($id,'files');$bytes=filesize($snapshot);$count=2;
        foreach(['plugins','storage/theme'] as $relativeDir){
            $source=$this->source().'/'.$relativeDir;
            if(is_link($source))throw new \RuntimeException('Linked resources cannot be migrated');
            if(!is_dir($source))continue;
            $iterator=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($source,\FilesystemIterator::SKIP_DOTS));
            foreach($iterator as $file){
                if($file->isLink()||!$file->isFile())throw new \RuntimeException('Unsupported resource type');
                $bytes+=$file->getSize();if($bytes>2147483648||++$count>50000)throw new \RuntimeException('Migration size limit exceeded');
                $destination=$stage.'/'.$relativeDir.'/'.substr($file->getPathname(),strlen($source)+1);
                $this->copyFile($file->getPathname(),$destination);
            }
        }
        return $stage;
    }
    private function parents(string $path): void
    {
        $parent=dirname($path);if(!is_dir($parent)&&!mkdir($parent,0700,true))throw new \RuntimeException('Cannot create migration directory');
    }
    private function copyFile(string $source,string $destination): void
    {
        $this->parents($destination);
        if(disk_free_space(dirname($destination))<67108864+filesize($source))throw new \RuntimeException('Insufficient migration space');
        if(!copy($source,$destination))throw new \RuntimeException('Cannot copy migration resource');chmod($destination,(fileperms($source)&0100)?0700:0600);
    }
    public function removeTree(string $path): void
    {
        $root=$this->root().'/';if(!str_starts_with($path,$root))throw new \RuntimeException('Refusing cleanup outside migration directory');
        if(is_link($path)||is_file($path)){unlink($path);return;}
        if(!is_dir($path))return;
        foreach(new \DirectoryIterator($path) as $file){if($file->isDot())continue;$this->removeTree($file->getPathname());}
        rmdir($path);
    }
}
