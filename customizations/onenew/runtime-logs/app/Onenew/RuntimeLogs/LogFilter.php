<?php
namespace App\Onenew\RuntimeLogs;
/** Defense in depth: the node must apply the same boundary before any local sink. */
final class LogFilter
{
 public static function message(string $level,string $message,array $secrets=[]):?string{
  if(!preg_match('//u',$message))return null;
  $message=preg_replace('/\x1b\[[0-9;]*[A-Za-z]/','',$message)??'';
  if(in_array($level,['debug','info'],true)&&preg_match('/(?:user(?:s|manager)?|listener|configuration|config\b|node\b|\[(?:vless|vmess|trojan|shadowsocks|ss|hysteria2?|tuic|anytls|socks|http):\d+\]|用户|下发|配置|端口)/i',$message))return null;
  foreach($secrets as $secret)if(is_string($secret)&&$secret!=='')$message=str_replace($secret,'[redacted]',$message);
  $patterns=[
   '~-----BEGIN .*?(?:PRIVATE KEY|CERTIFICATE)-----[\s\S]*?-----END .*?-----~i',
   '~\b(?:https?|vless|vmess|ss|trojan|socks5?|hysteria2?|tuic)://[^\s<>"\x27]+~i',
   '~\bBearer\s+[^\s,;]+~i',
   '~\b(?:password|passwd|pwd|secret|token|uuid|api[_-]?key|private[_-]?key|user(?:_id)?|node(?:_id)?|email|host|address|port|server_port)["\x27]?\s*[=:]\s*["\x27]?[^\s,;"\x27}]+~i',
   '~\b[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}\b~i',
   '~\b(?:sk-[A-Za-z0-9_\-]{8,}|gh[pousr]_[A-Za-z0-9_]+|github_pat_[A-Za-z0-9_]+)~',
   '~\b[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}\b~i',
   '~(?<![A-Za-z0-9])(?:\[?[0-9a-f]*:[0-9a-f:]+\]?(?::\d+)?|(?:\d{1,3}\.){3}\d{1,3}(?::\d+)?)~i',
   '~\b(?:[a-z0-9-]+\.)+[a-z]{2,}(?::\d+)?\b~i',
   '~\b(?:user|node|port|listener|用户|节点|端口)\s*#?\s*\d+\b~i',
   '~(?<![A-Za-z0-9]):\d{1,5}\b~',
  ];
  foreach($patterns as $pattern)$message=preg_replace($pattern,'[redacted]',$message)??'';
  $message=preg_replace('/[\x00-\x1f\x7f]+/',' ',$message)??'';
  if(function_exists('mb_strcut'))return trim(mb_strcut($message,0,2048,'UTF-8'));
  $out='';foreach(preg_split('//u',$message,-1,PREG_SPLIT_NO_EMPTY)?:[] as $char){if(strlen($out)+strlen($char)>2048)break;$out.=$char;}return trim($out);
 }
}
