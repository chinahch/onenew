<?php
namespace App\Onenew\AiOperations;
require __DIR__.'/../app/Onenew/AiOperations/Config.php';
require __DIR__.'/../app/Onenew/AiOperations/OperatorTasks.php';
function check(bool $ok,string $why):void {if(!$ok)throw new \RuntimeException($why);}
$config=new Config;$row=['enabled'=>true,'endpoint'=>'','model'=>'','token_parameter'=>'max_tokens','encrypted_key'=>null,'backend'=>'opencode','opencode_endpoint'=>'https://code.example.com','opencode_directory'=>'/srv/project','opencode_username'=>'opencode','encrypted_opencode_password'=>'encrypted-fixture','opencode_agent'=>'build','opencode_model'=>'fake/model'];
$view=$config->view($row);
check(($view['backend']??null)==='opencode','Config must expose selected backend');
check(($view['opencode_protocol']??null)==='v2','New OpenCode config must default to V2');
check(($view['opencode_password_configured']??null)===true&&!str_contains(json_encode($view),'encrypted-fixture'),'Config must expose only the secret presence');
check($config->ready($row),'OpenCode readiness must not require the chat API key');
$tasks=new OperatorTasks($config);$fingerprint=$tasks->fingerprint($row);
foreach(['backend','opencode_protocol','opencode_endpoint','opencode_directory','opencode_username','encrypted_opencode_password','opencode_agent','opencode_model'] as $field){$changed=$row;$changed[$field]='changed';check($tasks->fingerprint($changed)!==$fingerprint,'Task fingerprint omitted '.$field);}
$row['backend']='chat_completions';check(!$config->ready($row),'Chat backend must still require its independent key/model');
echo "OpenCode config view, backend readiness and task fingerprint tests passed\n";
