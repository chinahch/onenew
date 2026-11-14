package panel

import (
	"context"
	"encoding/json"
	"errors"
	"io"
)

func (c *Client) GetExitProbes(ctx context.Context) ([]json.RawMessage, error) {
	resp, e := c.probeRequest(ctx, "GET", "/api/v3/server/exit-probes", nil)
	if e != nil {
		return nil, e
	}
	defer resp.Body.Close()
	var out struct {
		Tasks []json.RawMessage `json:"tasks"`
	}
	if json.NewDecoder(io.LimitReader(resp.Body, 65536)).Decode(&out) != nil || len(out.Tasks) > 1 {
		return nil, errors.New("invalid exit tasks")
	}
	return out.Tasks, nil
}
func (c *Client) SendExitProbeResult(ctx context.Context, id string, r any) error {
	if len(id) != 36 {
		return errors.New("invalid exit id")
	}
	raw, e := json.Marshal(r)
	if e != nil {
		return errors.New("invalid exit result")
	}
	body := map[string]any{}
	if json.Unmarshal(raw, &body) != nil {
		return errors.New("invalid exit result")
	}
	body["machine_id"] = c.machineID
	resp, e := c.probeRequest(ctx, "POST", "/api/v3/server/exit-probes/"+id+"/result", body)
	if e != nil {
		return e
	}
	resp.Body.Close()
	return nil
}
