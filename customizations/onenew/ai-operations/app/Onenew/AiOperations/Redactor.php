<?php
namespace App\Onenew\AiOperations;
final class Redactor {
 public static function text(string $input,array $secrets=[]):string {
  if(!preg_match('//u',$input))return '[REDACTED invalid text]';
  foreach($secrets as $secret)if(is_string($secret)&&$secret!=='')$input=str_replace($secret,'[REDACTED]',$input);
  $patterns=[
   '~-----BEGIN [A-Z0-9 ]*(?:PRIVATE KEY|CERTIFICATE)-----.*?-----END [A-Z0-9 ]*(?:PRIVATE KEY|CERTIFICATE)-----~s',
   '~\b(?:vless|vmess|ss|ssr|trojan|socks5?|hysteria2?|hy2|tuic|https?)://[^\s<>"\x27]+~i',
   '~\bBearer\s+[A-Za-z0-9_.\~+/=\-]+~i',
   '~\b(?:api[_ -]?key|access[_ -]?token|refresh[_ -]?token|password|passwd|token|secret|uuid|private[_ -]?key)["\x27]?\s*[:=]\s*["\x27]?[^\s,"\x27;<>}]+~i',
   '~\b(?:sk-[A-Za-z0-9_\-]{8,}|gh[pousr]_[A-Za-z0-9_]+|github_pat_[A-Za-z0-9_]+)~',
   '~\b[A-Fa-f0-9]{8}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{4}-[A-Fa-f0-9]{12}\b~',
   '~\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b~i',
  ];
  foreach($patterns as $pattern)$input=preg_replace($pattern,'[REDACTED]',$input)??'[REDACTED invalid text]';
  $input=preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/','',$input)??'';
  if(function_exists('mb_substr'))return mb_substr($input,0,24000,'UTF-8');
  $chars=preg_split('//u',$input,-1,PREG_SPLIT_NO_EMPTY);return implode('',array_slice($chars?:[],0,24000));
 }
}
