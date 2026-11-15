<?php
namespace App\Onenew { class ExitProbePayload { public static function build($core,$out){return ['outbound'=>$out];} } }
namespace {
function abort_unless($ok,$status,$message=''){if(!$ok)throw new \RuntimeException($message,$status);}
function abort_if($bad,$status,$message=''){abort_unless(!$bad,$status,$message);}
require dirname(__DIR__).'/app/Onenew/LandingLibrary/Compiler.php';
use App\Onenew\LandingLibrary\Compiler;
$resolve=fn($id)=>['enabled'=>true,'variants'=>['singbox'=>['tag'=>'draft','protocol'=>'socks','settings'=>['server'=>'example.test','server_port'=>1080]]]];
$bindings=[['landing_id'=>1,'global'=>false,'domains'=>['example.com','sub.example.com']],['landing_id'=>2,'global'=>true,'domains'=>[]]];
$value=Compiler::compile($bindings,'singbox',$resolve);
if($value['custom_routes']!==[['outbound'=>'landing_1','domain_suffix'=>['example.com','sub.example.com']],['outbound'=>'landing_2']])throw new \RuntimeException('ordered rule compilation failed');
$invalid=[array_reverse($bindings),[['landing_id'=>1,'global'=>false,'domains'=>['example.com']],['landing_id'=>2,'global'=>false,'domains'=>['sub.example.com']]],[$bindings[1],$bindings[1]]];
foreach($invalid as $v){try{Compiler::bindings($v);throw new \LogicException('invalid routing accepted');}catch(\RuntimeException $e){if($e instanceof \LogicException)throw $e;}}
echo "landing compiler: pass\n";
}
