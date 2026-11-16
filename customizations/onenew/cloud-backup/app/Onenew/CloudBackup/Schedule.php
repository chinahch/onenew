<?php
namespace App\Onenew\CloudBackup;
final class Schedule
{
    public static function next(string $time,\DateTimeImmutable $now):int
    {
        if(!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/D',$time))throw new \InvalidArgumentException('Invalid daily time');
        $local=$now->setTimezone(new \DateTimeZone('Asia/Shanghai'));
        [$h,$m]=array_map('intval',explode(':',$time));$next=$local->setTime($h,$m,0);
        if($next<=$local)$next=$next->modify('+1 day');return $next->getTimestamp();
    }
}
