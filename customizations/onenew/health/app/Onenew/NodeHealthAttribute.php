<?php

namespace App\Onenew;

trait NodeHealthAttribute
{
    public function initializeNodeHealthAttribute(): void
    {
        $this->append('proxy_health');
    }

    public function getProxyHealthAttribute(): array
    {
        try {
            return app(ProbeService::class)->status($this);
        } catch (\Throwable $e) {
            // Health storage is optional to native subscription delivery. A Redis
            // outage must never fabricate online state or break model serialization.
            return ['state' => 'pending', 'finished_at' => null, 'created_at' => null,
                'error_code' => 'configuration_unavailable', 'source' => 'node machine · automatic',
                'latency_ms' => null, 'ping_ms' => null];
        }
    }
}
