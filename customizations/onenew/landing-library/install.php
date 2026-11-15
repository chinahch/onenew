<?php
function applyOnenewLandingLibrary(string $root): void
{
    replaceOnce($root.'/app/Models/Server.php',"class Server extends Model\n{","class Server extends Model\n{\n    use \\App\\Onenew\\LandingLibrary\\HasLandingBindings;");
    replaceOnce($root.'/app/Models/Server.php',"        'custom_outbounds' => 'array',","        'onenew_landing_bindings' => 'array',\n        'custom_outbounds' => 'array',");
    replaceOnce($root.'/app/Http/Requests/Admin/ServerSave.php',"            'custom_outbounds' => 'nullable|array',",
        "            'onenew_landing_bindings' => 'nullable|array|max:50',\n            'onenew_landing_bindings.*.landing_id' => 'required|integer|min:1',\n            'onenew_landing_bindings.*.global' => 'required|boolean',\n            'onenew_landing_bindings.*.domains' => 'present|array|max:500',\n            'onenew_landing_bindings.*.domains.*' => 'required|string|max:253',\n            'custom_outbounds' => 'nullable|array',");
    $controller=$root.'/app/Http/Controllers/V2/Admin/Server/ManageController.php';
    replaceOnce($controller,'$copiedServer = $server->replicate();',
        '$copiedServer = $server->replicate();'."\n        // Copies are detached drafts; avoid duplicate listeners on the same machine/port.\n        \$copiedServer->onenew_landing_bindings = [];\n        \$copiedServer->custom_outbounds = [];\n        \$copiedServer->custom_routes = [];\n        \$copiedServer->route_ids = [];\n        \$copiedServer->parent_id = null;\n        \$copiedServer->enabled = false;");
    // Keep meaningful compile errors and prevent credential-bearing exception logging.
    foreach(['保存失败','创建失败'] as $message){
        $indent=$message==='保存失败'?'            ':'        ';
        replaceOnce($controller,$indent.'} catch (\\Exception $e) {'."\n".$indent.'    Log::error($e);'."\n".$indent.'    return $this->fail([500, '."'$message']);",
            $indent.'} catch (\\Exception $e) {'."\n".$indent.'    if ($e instanceof \\Symfony\\Component\\HttpKernel\\Exception\\HttpExceptionInterface) throw $e;'."\n".$indent.'    return $this->fail([500, '."'$message']);");
    }
}
