<?php
require __DIR__.'/../app/Onenew/Energy/Amount.php';
use App\Onenew\Energy\Amount;
foreach(['0.01'=>1,'12.34'=>1234,'1000000'=>100000000,'1'=>100] as $value=>$expected){if(Amount::parse((string)$value)!==$expected)throw new RuntimeException('Precision failure');}
foreach(['0','-1','1.001','1e3','1000000.01','nan',' 1','01.00'] as $value){try{Amount::parse($value);throw new RuntimeException('Accepted invalid amount');}catch(InvalidArgumentException $e){}}
echo "Exact energy amount validation passed\n";
