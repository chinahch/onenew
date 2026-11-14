<?php

function applyOnenewResourceIds(string $root): void
{
    $helper = '\\App\\Onenew\\ResourceIds';
    foreach (['Server', 'ServerMachine'] as $model) {
        replaceOnce($root.'/app/Models/'.$model.'.php', "class $model extends Model\n{",
            "class $model extends Model\n{\n    use \\App\\Onenew\\ReusesResourceIds;");
    }
    replaceOnce($root.'/app/Http/Controllers/V2/Admin/Server/ManageController.php',
        "\$deleted = Server::whereIn('id', \$ids)->delete();",
        "\$deleted = DB::transaction(function () use (\$ids) {\n                foreach (Server::whereIn('id', \$ids)->orderBy('id')->get() as \$node) \$node->delete();\n                return true;\n            });");
    replaceOnce($root.'/app/Jobs/StatServerJob.php',
        "    public function handle(): void\n    {",
        "    public function handle(): void\n    {\n        DB::transaction(function () {\n            \\App\\Models\\Server::whereKey(\$this->server['id'])->lockForUpdate()->first();\n            if ($helper::matches(\$this->server)) \$this->handleCurrentIncarnation();\n        }, 3);\n    }\n\n    private function handleCurrentIncarnation(): void\n    {");
    $worker = $root.'/app/WebSocket/NodeWorker.php';
    replaceOnce($worker, '$conn->nodeId = $nodeId;',
        '$conn->onenewNodeIdentity = (string) $node->onenew_identity;' . "\n        " . '$conn->nodeId = $nodeId;');
    replaceOnce($worker, '$conn->machineId = $machineId;',
        '$conn->onenewCredentialHash = hash(\'sha256\', (string) $machine->token);' . "\n        " . '$conn->machineId = $machineId;');
    replaceOnce($worker, "    public function onMessage(TcpConnection \$conn, \$data): void\n    {",
        "    public function onMessage(TcpConnection \$conn, \$data): void\n    {\n        if ((!empty(\$conn->machineId) || !empty(\$conn->nodeId)) && !\\App\\Onenew\\ResourceConnections::valid(\$conn)) return;");
    replaceOnce($worker, 'foreach ($conn->machineNodeIds as $nodeId) {',
        'foreach ($conn->machineNodeIds as $nodeId) {' . "\n                if (NodeRegistry::get(\$nodeId) !== \$conn) continue;");
    replaceOnce($worker, "\$nodeId = \$conn->nodeId;\n            NodeRegistry::remove",
        "\$nodeId = \$conn->nodeId;\n            if (NodeRegistry::get(\$nodeId) !== \$conn) return;\n            NodeRegistry::remove");
    $registry = $root.'/app/Services/NodeRegistry.php';
    // Sending a push must revalidate before any newly reused ID receives data.
    replaceOnce($registry, "\$conn = self::get(\$nodeId);\n        if (!\$conn)",
        "\$conn = self::get(\$nodeId);\n        if (!\$conn || !\\App\\Onenew\\ResourceConnections::valid(\$conn))");
    replaceOnce($registry, "\$conn = self::getMachine(\$machineId);\n        if (!\$conn) {\n            return false;",
        "\$conn = self::getMachine(\$machineId);\n        if (!\$conn || !\\App\\Onenew\\ResourceConnections::valid(\$conn)) {\n            return false;");
    replaceOnce($registry, "\$conn = self::getMachine(\$machineId);\n        if (!\$conn) {\n            return;",
        "\$conn = self::getMachine(\$machineId);\n        if (!\$conn || !\\App\\Onenew\\ResourceConnections::valid(\$conn)) {\n            return;");
}
