<?php
namespace App\Onenew\Energy;
final class Amount
{
    public static function parse(string $value): int
    {
        if(!preg_match('/^(0|[1-9][0-9]{0,6})(?:\.([0-9]{1,2}))?$/D',$value,$m))throw new \InvalidArgumentException('请输入正数能量，最多两位小数');
        $amount=(int)$m[1]*100+(int)str_pad($m[2]??'',2,'0');
        if($amount<1||$amount>100000000)throw new \InvalidArgumentException('单次补充范围为 0.01–1000000 能量');
        return $amount;
    }
}
