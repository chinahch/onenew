package panel

import (
	"context"
	"encoding/json"
	"errors"
	"github.com/cedar2025/xboard-node/internal/nlog"
	"io"
)

func (c *Client) SendRuntimeLogs(ctx context.Context, b nlog.RuntimeBatch) error {
	raw, e := json.Marshal(b)
	if e != nil || len(raw) > 256<<10 {
		return errors.New("runtime batch invalid")
	}
	var body map[string]any
	if json.Unmarshal(raw, &body) != nil {
		return errors.New("runtime batch invalid")
	}
	body["machine_id"] = c.machineID
	resp, e := c.probeRequest(ctx, "POST", "/api/v3/server/runtime-logs", body)
	if e != nil {
		return errors.New("runtime delivery unavailable")
	}
	defer resp.Body.Close()
	var ack struct {
		Accepted bool  `json:"accepted"`
		LastSeq  int64 `json:"last_seq"`
	}
	if json.NewDecoder(io.LimitReader(resp.Body, 4096)).Decode(&ack) != nil || !ack.Accepted {
		return errors.New("runtime acknowledgement invalid")
	}
	if len(b.Entries) > 0 && ack.LastSeq < b.Entries[len(b.Entries)-1].Seq {
		return errors.New("runtime acknowledgement incomplete")
	}
	return nil
}
