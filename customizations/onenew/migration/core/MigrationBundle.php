<?php
namespace App\Onenew\Migration;

/** Portable, bounded-memory, authenticated migration container. No package code executes. */
final class MigrationBundle
{
    private const MAGIC = "ONENEW1\n";
    private const CHUNK = 1048576;
    private const LIMIT = 2147483648;
    private const FILES = 50000;
    private const ITERATIONS = 210000;

    public static function inspect(string $package): array
    {
        $f = self::open($package, 'rb');
        try { [$header] = self::header($f); return $header; } finally { fclose($f); }
    }

    public static function pack(string $sourceRoot, string $destination, string $password, string $revision): array
    {
        self::password($password);
        if (!preg_match('/^[0-9a-f]{40}$/D', $revision)) throw new \RuntimeException('Invalid source revision.');
        $root = realpath($sourceRoot);
        if ($root === false || !is_dir($root) || is_link($sourceRoot)) throw new \RuntimeException('Invalid source directory.');
        $files = [];
        $walk = function ($dir, $prefix = '') use (&$walk, &$files) {
            foreach (new \DirectoryIterator($dir) as $entry) {
                if ($entry->isDot()) continue;
                $path = $prefix . $entry->getFilename();
                if ($entry->isLink()) throw new \RuntimeException('Symbolic links are forbidden.');
                if ($entry->isDir()) { $walk($entry->getPathname(), $path . '/'); continue; }
                if (!$entry->isFile() || !self::allowed($path)) throw new \RuntimeException('Unexpected source file.');
                $files[] = $path;
                if (count($files) > self::FILES) throw new \RuntimeException('Too many files.');
            }
        };
        $walk($root);
        sort($files, SORT_STRING);
        if (!in_array('.env', $files, true) || !count(array_filter($files, fn($p) => str_starts_with($p, 'data/database/')))) throw new \RuntimeException('Environment or database snapshot missing.');
        $f = self::open($destination, 'x+b');
        @chmod($destination, 0600);
        try {
            $header = ['format'=>1, 'revision'=>$revision, 'salt'=>bin2hex(random_bytes(16)), 'nonce'=>bin2hex(random_bytes(8)), 'iterations'=>self::ITERATIONS];
            $raw = json_encode($header, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            self::write($f, self::MAGIC . pack('N', strlen($raw)) . $raw);
            $key = hash_pbkdf2('sha256', $password, hex2bin($header['salt']), self::ITERATIONS, 32, true);
            $index = 0; $total = 0;
            foreach ($files as $path) {
                $input = self::open($root . '/' . $path, 'rb');
                try {
                    $stat = fstat($input); $size = $stat['size'];
                    if ($size < 0 || $size > self::LIMIT - $total) throw new \RuntimeException('Migration size limit exceeded.');
                    self::record($f, 'F' . json_encode(['path'=>$path,'size'=>$size,'mode'=>($stat['mode'] & 0100)?0700:0600], JSON_THROW_ON_ERROR), $key, $header, $raw, $index);
                    $hash = hash_init('sha256'); $read = 0;
                    while (!feof($input)) {
                        $data = fread($input, self::CHUNK);
                        if ($data === false) throw new \RuntimeException('Source read failed.');
                        if ($data === '') break;
                        $read += strlen($data);
                        if ($read > $size) throw new \RuntimeException('Source changed during export.');
                        hash_update($hash, $data);
                        self::record($f, 'D'.$data, $key, $header, $raw, $index);
                    }
                    if ($read !== $size) throw new \RuntimeException('Source changed during export.');
                    self::record($f, 'H'.hash_final($hash), $key, $header, $raw, $index);
                    $total += $size;
                } finally { fclose($input); }
            }
            self::record($f, 'E'.json_encode(['files'=>count($files),'bytes'=>$total], JSON_THROW_ON_ERROR), $key, $header, $raw, $index);
            if (!fflush($f)) throw new \RuntimeException('Package flush failed.');
            return ['revision'=>$revision,'files'=>count($files),'bytes'=>$total];
        } catch (\Throwable $e) { fclose($f); $f = null; @unlink($destination); throw $e; }
        finally { if (is_resource($f)) fclose($f); }
    }

    public static function unpack(string $package, string $password, string $destination): array
    {
        self::password($password);
        $parent = realpath(dirname($destination));
        if ($parent === false || is_link($destination) || (file_exists($destination) && (!is_dir($destination) || count(scandir($destination)) !== 2))) throw new \RuntimeException('Restore requires a new empty destination.');
        $destination = $parent . '/' . basename($destination);
        if (in_array(basename($destination), ['.', '..', ''], true)) throw new \RuntimeException('Invalid restore destination.');
        $stage = $parent . '/.onenew-restore-' . bin2hex(random_bytes(16));
        if (!mkdir($stage, 0700)) throw new \RuntimeException('Cannot create private restore staging.');
        $f = null; $out = null;
        try {
            $f = self::open($package, 'rb');
            [$header, $raw] = self::header($f);
            $key = hash_pbkdf2('sha256', $password, hex2bin($header['salt']), self::ITERATIONS, 32, true);
            $index = 0; $seen = []; $total = 0; $size = 0; $read = 0; $hasEnv = false; $hasDb = false;
            while (true) {
                $length = unpack('N', self::read($f, 4))[1];
                if ($length < 17 || $length > self::CHUNK + 17) throw new \RuntimeException('Invalid encrypted record length.');
                $cipher = self::read($f, $length);
                $plain = openssl_decrypt(substr($cipher, 16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, hex2bin($header['nonce']).pack('N', $index), substr($cipher,0,16), $raw.pack('N', $index));
                $index++;
                if ($plain === false || $plain === '') throw new \RuntimeException('Wrong password or damaged migration package.');
                $type = $plain[0]; $data = substr($plain,1);
                if ($type === 'F' && $out === null) {
                    $meta = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
                    $path = $meta['path'] ?? null; $size = $meta['size'] ?? null; $mode = $meta['mode'] ?? 0600;
                    if (!in_array($mode,[0600,0700],true)) throw new \RuntimeException('Invalid file permissions.');
                    if (!is_string($path) || !self::allowed($path) || isset($seen[$path]) || count($seen) >= self::FILES || !is_int($size) || $size < 0 || $size > self::LIMIT - $total) throw new \RuntimeException('Invalid file manifest.');
                    $seen[$path] = true; $hasEnv = $hasEnv || $path === '.env'; $hasDb = $hasDb || str_starts_with($path, 'data/database/');
                    $target = $stage . '/' . $path;
                    if (!is_dir(dirname($target)) && !mkdir(dirname($target),0700,true)) throw new \RuntimeException('Cannot create restore directory.');
                    $out = self::open($target,'xb'); @chmod($target,$mode); $read=0; $hash=hash_init('sha256');
                } elseif ($type === 'D' && is_resource($out) && strlen($data) > 0) {
                    $read += strlen($data);
                    if ($read > $size) throw new \RuntimeException('File exceeds declared size.');
                    hash_update($hash,$data); self::write($out,$data);
                } elseif ($type === 'H' && is_resource($out)) {
                    if ($read !== $size || !hash_equals(hash_final($hash),$data)) throw new \RuntimeException('File integrity check failed.');
                    fclose($out); $out=null; $total += $size;
                } elseif ($type === 'E' && $out === null) {
                    $end=json_decode($data,true,8,JSON_THROW_ON_ERROR);
                    if (($end['files']??null)!==count($seen) || ($end['bytes']??null)!==$total || !$hasEnv || !$hasDb || fread($f,1)!=='') throw new \RuntimeException('Invalid final manifest.');
                    if (file_exists($destination) && (!is_dir($destination) || is_link($destination) || count(scandir($destination))!==2 || !rmdir($destination))) throw new \RuntimeException('Restore destination changed.');
                    if (!rename($stage,$destination)) throw new \RuntimeException('Cannot publish restored data.');
                    return ['revision'=>$header['revision'],'files'=>count($seen),'bytes'=>$total];
                } else throw new \RuntimeException('Invalid record sequence.');
            }
        } finally {
            if (is_resource($out)) fclose($out);
            if (is_resource($f)) fclose($f);
            if (is_dir($stage)) self::remove($stage);
        }
    }

    private static function allowed(string $p): bool
    {
        if (strlen($p)>1024 || (preg_match('/[\x00-\x1f\x7f:]/',$p) || strpos($p, chr(92)) !== false) || str_starts_with($p,'/') || preg_match('~(^|/)\.\.?(/|$)|//~',$p)) return false;
        return $p === '.env' || (bool) preg_match('~^(data/database|plugins|storage/theme)/[^/].*$~D',$p);
    }
    private static function password(string $p): void { if (strlen($p)<12 || strlen($p)>128 || preg_match('/[\x00-\x1f\x7f]/', $p)) throw new \RuntimeException('Migration password must contain 12 to 128 bytes without control characters.'); }
    private static function header($f): array
    {
        if (self::read($f,8)!==self::MAGIC) throw new \RuntimeException('Unknown migration format.');
        $n=unpack('N',self::read($f,4))[1];
        if ($n<1 || $n>1024) throw new \RuntimeException('Invalid migration header.');
        $raw=self::read($f,$n); $h=json_decode($raw,true,8,JSON_THROW_ON_ERROR);
        if (!is_array($h) || count($h)!==5 || ($h['format']??null)!==1 || ($h['iterations']??null)!==self::ITERATIONS || !is_string($h['revision']??null) || !preg_match('/^[0-9a-f]{40}$/D',$h['revision']) || !is_string($h['salt']??null) || !preg_match('/^[0-9a-f]{32}$/D',$h['salt']) || !is_string($h['nonce']??null) || !preg_match('/^[0-9a-f]{16}$/D',$h['nonce'])) throw new \RuntimeException('Invalid migration header.');
        return [$h,$raw];
    }
    private static function record($f,string $plain,string $key,array $h,string $raw,int &$i): void
    {
        $tag=''; $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,hex2bin($h['nonce']).pack('N',$i),$tag,$raw.pack('N',$i)); $i++;
        if ($cipher===false) throw new \RuntimeException('Encryption failed.');
        self::write($f,pack('N',strlen($cipher)+16).$tag.$cipher);
    }
    private static function open(string $p,string $mode) { $f=@fopen($p,$mode); if ($f===false) throw new \RuntimeException('Cannot open migration file.'); return $f; }
    private static function read($f,int $n): string { $s=''; while(strlen($s)<$n) { $v=fread($f,$n-strlen($s)); if($v===false || $v==='') throw new \RuntimeException('Truncated migration package.'); $s.=$v; } return $s; }
    private static function write($f,string $s): void { $n=strlen($s); $i=0; while($i<$n) { $v=fwrite($f,substr($s,$i)); if($v===false || $v===0) throw new \RuntimeException('Migration write failed.'); $i+=$v; } }
    private static function remove(string $dir): void { foreach(new \FilesystemIterator($dir) as $e) { if($e->isDir() && !$e->isLink()) self::remove($e->getPathname()); else @unlink($e->getPathname()); } @rmdir($dir); }
}
