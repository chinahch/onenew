<?php
namespace App\Onenew\AiOperations;
/** Safe internal reason and numeric metadata only. Never chain the raw transport exception. */
final class ProviderFailure extends \RuntimeException
{
    public readonly array $timings;
    public function __construct(public readonly string $reason,array $timings=[],public readonly array $trace=[]){$this->timings=self::timings($timings);parent::__construct($reason);}
    public static function timings(array $values):array
    {
        $out=[];foreach(['connect_ms','starttransfer_ms','total_ms'] as $field){$value=$values[$field]??null;if((is_int($value)||is_float($value))&&is_finite((float)$value)&&$value>=0)$out[$field]=(int)round(min(660000,$value));}return $out;
    }
}
