<?php

/** Called by the root overlay installer on the pinned fresh upstream source. */
function applyOnenewTrafficPatch(string $root): void
{
    replaceOnce($root.'/app/Http/Routes/V2/AdminRoute.php',
        "\$router->get('/getTrafficRank', [StatController::class, 'getTrafficRank']);",
        "\$router->get('/getTrafficRank', [\\App\\Onenew\\Traffic\\RankingController::class, 'getTrafficRank']);");
    $path = $root . '/app/Services/UserService.php';
    $source = str_replace("\r\n", "\n", file_get_contents($path));
    $old = 'use App\\Jobs\\StatUserJob;';
    $new = 'use App\\Onenew\\Traffic\\LedgerStatUserJob as StatUserJob;';
    if (substr_count($source, $old) !== 1) {
        throw new RuntimeException('Pinned native daily traffic job import changed');
    }
    if (file_put_contents($path, str_replace($old, $new, $source)) === false) {
        throw new RuntimeException('Cannot apply daily traffic ledger integration');
    }

    $quota = $root.'/app/Jobs/TrafficFetchJob.php';
    $node = $root.'/app/Jobs/StatServerJob.php';
    foreach ([$quota, $node] as $job) {
        replaceOnce($job, '    public $tries = ', "    protected ?string \$onenewBatchId = null;\n    public \$tries = ");
        replaceOnce($job, '        $this->onQueue(', "        \$this->onenewBatchId = (string) \\Illuminate\\Support\\Str::uuid();\n        \$this->onQueue(");
    }

    replaceOnce($quota, '    public $tries = 1;', '    public $tries = 3;');
    replaceOnce($quota, <<<'UPSTREAM'
        foreach ($this->data as $uid => $v) {
            User::where('id', $uid)
                ->incrementEach(
                    [
                        'u' => $v[0] * $this->server['rate'],
                        'd' => $v[1] * $this->server['rate'],
                    ],
                    ['t' => time()]
                );
        }
UPSTREAM,
        <<<'PATCHED'
        \App\Onenew\Traffic\TrafficBatch::apply($this->onenewBatchId, function (): void {
            $data = $this->data;
            ksort($data, SORT_NUMERIC);
            foreach ($data as $uid => $v) {
                User::where('id', $uid)->incrementEach([
                    'u' => $v[0] * $this->server['rate'],
                    'd' => $v[1] * $this->server['rate'],
                ], ['t' => time()]);
            }
        });

        // Retry the Redis notification even if the database batch already committed.
PATCHED);

    // Keep the existing incarnation guard and make both node counters atomic with the receipt.
    replaceOnce($node, '        DB::transaction(function () {',
        '        \App\Onenew\Traffic\TrafficBatch::apply($this->onenewBatchId, function (): void {');
    replaceOnce($node, "\n        }, 3);\n    }\n\n    private function handleCurrentIncarnation",
        "\n        });\n    }\n\n    private function handleCurrentIncarnation");
}
