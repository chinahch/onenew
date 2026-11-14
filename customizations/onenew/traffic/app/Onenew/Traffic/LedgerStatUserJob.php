<?php

namespace App\Onenew\Traffic;

use App\Jobs\StatUserJob;
use Illuminate\Support\Str;

/** Native daily archive job with an atomic per-node projection and retry receipt. */
class LedgerStatUserJob extends StatUserJob
{
    protected string $onenewBatchId;

    public function __construct(array $server, array $data, string $protocol, string $recordType = 'd')
    {
        parent::__construct($server, $data, $protocol, $recordType);
        $this->onenewBatchId = (string) Str::uuid();
    }

    public function handle(): void
    {
        if ($this->recordType !== 'd') {
            parent::handle();
            return;
        }
        // Match the native archive's worker-side panel-calendar day, including
        // delayed jobs. A committed receipt prevents retrying into a second day.
        $recordAt = strtotime(date('Y-m-d'));
        app(NodeTrafficLedger::class)->applyBatch($this->onenewBatchId, $this->server, $this->data,
            $this->protocol, $recordAt, function () use ($recordAt): void {
                foreach ($this->data as $uid => $traffic) {
                    $this->processUserStat((int) $uid, $traffic, $recordAt);
                }
            });
    }
}
