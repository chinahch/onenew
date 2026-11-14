<?php
namespace Plugin\SubscriptionGuard;

/** Hardened adaptation of SubscriptionGuard by XBoard Plugin. */
final class GuardPolicy
{
    public readonly int $maxIps;
    public readonly int $windowHours;
    public readonly int $rateSeconds;
    public readonly int $retentionDays;
    public readonly string $action;
    public readonly bool $notify;
    public readonly bool $excludeAdmin;
    private readonly bool $groupSubnets;
    private array $whitelist=[];

    public function __construct(array $config)
    {
        $this->maxIps=self::number($config,'max_unique_ips',5,1,1000);
        $this->windowHours=self::number($config,'time_window_hours',24,1,168);
        $this->rateSeconds=self::number($config,'rate_limit_seconds',60,0,3600);
        $this->retentionDays=self::number($config,'log_retention_days',30,1,365);
        $this->action=(string)($config['action_mode']??'alert_only');
        if(!in_array($this->action,['alert_only','auto_ban','reset_only'],true))throw new \InvalidArgumentException('Invalid guard action');
        $this->notify=self::flag($config['enable_telegram_notify']??false);
        $this->excludeAdmin=self::flag($config['exclude_admin']??true);
        $this->groupSubnets=self::flag($config['enable_subnet_grouping']??false);
        foreach(preg_split('/[\r\n]+/',(string)($config['ip_whitelist']??'')) as $line){
            $line=trim($line);if($line==='')continue;
            $parts=explode('/',$line);$ip=self::canonical($parts[0]);
            if($ip===null||count($parts)>2)throw new \InvalidArgumentException('Invalid whitelist address');
            $bytes=inet_pton($ip);$bits=strlen($bytes)*8;
            if(isset($parts[1])&&(!ctype_digit($parts[1])||(int)$parts[1]>$bits))throw new \InvalidArgumentException('Invalid whitelist CIDR');
            $this->whitelist[]=[$bytes,isset($parts[1])?(int)$parts[1]:$bits];
        }
    }
    private static function number(array $config,string $key,int $default,int $min,int $max):int
    {
        $value=$config[$key]??$default;
        if(filter_var($value,FILTER_VALIDATE_INT)===false||(int)$value<$min||(int)$value>$max)throw new \InvalidArgumentException('Invalid guard numeric setting');
        return (int)$value;
    }
    private static function flag(mixed $value):bool
    {
        $flag=filter_var($value,FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
        if($flag===null)throw new \InvalidArgumentException('Invalid guard boolean setting');return $flag;
    }
    public static function canonical(string $ip):?string
    {
        $bytes=@inet_pton($ip);if($bytes===false)return null;
        if(strlen($bytes)===16&&substr($bytes,0,12)===str_repeat("\0",10)."\xff\xff")$bytes=substr($bytes,12);
        return inet_ntop($bytes);
    }
    public function whitelisted(string $ip):bool
    {
        $canonical=self::canonical($ip);if($canonical===null)return false;$bytes=inet_pton($canonical);
        foreach($this->whitelist as [$network,$prefix]){
            if(strlen($network)!==strlen($bytes))continue;
            $full=intdiv($prefix,8);$remaining=$prefix%8;
            if(substr($bytes,0,$full)!==substr($network,0,$full))continue;
            if($remaining===0||((ord($bytes[$full])&(0xff<<(8-$remaining)))===(ord($network[$full])&(0xff<<(8-$remaining)))))return true;
        }
        return false;
    }
    public function distinctIps(array $ips):array
    {
        $result=[];
        foreach($ips as $ip){
            $ip=self::canonical((string)$ip);if($ip===null||$this->whitelisted($ip))continue;
            if($this->groupSubnets&&filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))$ip=substr($ip,0,strrpos($ip,'.')).'.0/24';
            $result[$ip]=true;
        }
        return array_keys($result);
    }
    public function exceeds(array $ips):bool{return count($this->distinctIps($ips))>$this->maxIps;}
    public static function safeAgent(string $agent,string $subscriptionToken):string
    {
        if($subscriptionToken!=='')$agent=str_replace($subscriptionToken,'[redacted]',$agent);
        $agent=preg_replace('/[\x00-\x1f\x7f]/','',$agent)??'';
        $agent=iconv('UTF-8','UTF-8//IGNORE',$agent)?:'';
        return function_exists('mb_strcut')?mb_strcut($agent,0,500,'UTF-8'):iconv('UTF-8','UTF-8//IGNORE',substr($agent,0,500));
    }
}
