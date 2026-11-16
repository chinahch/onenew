<?php
require __DIR__.'/../app/Onenew/CloudBackup/Schedule.php';
use App\Onenew\CloudBackup\Schedule;
function verify($condition){if(!$condition)throw new RuntimeException('Schedule assertion failed');}
$before=new DateTimeImmutable('2026-11-16 02:59:00',new DateTimeZone('Asia/Shanghai'));
verify(Schedule::next('03:00',$before)==strtotime('2026-11-15T19:00:00Z'));
verify(Schedule::next('03:00',$before->modify('+1 minute'))==strtotime('2026-11-16T19:00:00Z'));
verify(Schedule::next('00:00',new DateTimeImmutable('2026-12-31T16:01:00Z'))==strtotime('2027-01-01T16:00:00Z'));
foreach(['24:00','03:60','3:00','12:01:00','oops'] as $bad){try{Schedule::next($bad,$before);throw new LogicException('Invalid time accepted');}catch(InvalidArgumentException $e){}}
echo "Daily Beijing scheduling passed\n";
