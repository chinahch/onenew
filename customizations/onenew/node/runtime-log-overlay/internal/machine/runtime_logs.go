package machine

import (
	"context"
	"github.com/cedar2025/xboard-node/internal/nlog"
	"time"
)

func (o *Orchestrator) runRuntimeLogs(ctx context.Context) {
	ticker := time.NewTicker(10 * time.Second)
	defer ticker.Stop()
	// Heartbeats make collection capability explicit even while logs are quiet.
	for {
		b := nlog.PendingRuntime()
		if o.client.SendRuntimeLogs(ctx, b) == nil {
			nlog.AcknowledgeRuntime(b.BatchID)
		}
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
	}
}
