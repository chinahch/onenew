package machine

import (
	"context"
	"encoding/json"
	"github.com/cedar2025/xboard-node/internal/exitprobe"
	"github.com/cedar2025/xboard-node/internal/probe"
	"time"
)

func (o *Orchestrator) runExitProbes(ctx context.Context) {
	ticker := time.NewTicker(3 * time.Second)
	defer ticker.Stop()
	for {
		tasks, e := o.client.GetExitProbes(ctx)
		if e == nil {
			for _, raw := range tasks {
				var task probe.Task
				if json.Unmarshal(raw, &task) != nil {
					continue
				}
				if ctx.Err() != nil {
					return
				}
				result := exitprobe.Run(ctx, task)
				if ctx.Err() != nil {
					return
				}
				_ = o.client.SendExitProbeResult(ctx, task.ID, result)
			}
		}
		select {
		case <-ctx.Done():
			return
		case <-ticker.C:
		}
	}
}
