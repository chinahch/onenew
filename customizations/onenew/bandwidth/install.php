<?php
function applyOnenewNodeBandwidth(string $root): void
{
    replaceOnce($root.'/app/Http/Requests/Admin/ServerSave.php',"            'rate' => 'required|numeric',","            'node_speed_limit_mbps' => ['sometimes','integer','min:0','max:100000',\\App\\Onenew\\NodeBandwidth::capabilityRule(\$this)],\n            'rate' => 'required|numeric',");
    replaceOnce($root.'/app/Models/Server.php',"    protected \$casts = [","    protected \$casts = [\n        'node_speed_limit_mbps' => 'integer',");
    replaceOnce($root.'/app/Observers/ServerObserver.php',"            'protocol_settings',","            'node_speed_limit_mbps',\n            'protocol_settings',");
    replaceOnce($root.'/app/Services/ServerService.php',"        return \$response;\n    }","        \$response['node_speed_limit_mbps'] = (int) \$node->node_speed_limit_mbps;\n        return \$response;\n    }");
    replaceOnce($root.'/app/Services/ServerService.php',"            'kernel_status' => (bool) (\$metrics['kernel_status'] ?? false),","            'onenew_node_bandwidth_v1' => (\$metrics['onenew_node_bandwidth_v1'] ?? false) === true,\n            'kernel_status' => (bool) (\$metrics['kernel_status'] ?? false),");
}
