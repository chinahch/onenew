package probe

import (
	"context"
	"github.com/cedar2025/xboard-node/internal/panel"
	"time"
)

// HealthStages carries bounded, independently executed saved landing snapshots.
type HealthStages struct {
	Version     int       `json:"version"`
	EntrySource string    `json:"entry_source,omitempty"`
	Landings    []Landing `json:"landings"`
}
type Landing struct {
	Kernel   string               `json:"kernel_type,omitempty"`
	Error    string               `json:"error_code,omitempty"`
	ID       string               `json:"id"`
	Name     string               `json:"name"`
	Outbound panel.OutboundConfig `json:"outbound"`
}
type Stage struct {
	State  string `json:"state"`
	Error  string `json:"error_code"`
	Source string `json:"source,omitempty"`
}
type LandingResult struct {
	ID    string `json:"id"`
	State string `json:"state"`
	Error string `json:"error_code"`
}
type Stages struct {
	Version  int             `json:"version"`
	Entry    Stage           `json:"entry"`
	Landings []LandingResult `json:"landings"`
}

func runStaged(ctx context.Context, t Task, request func(context.Context, Payload) (string, int64), ping func(context.Context, string) (*float64, string)) Result {
	h := t.Payload.HealthStages
	if h == nil {
		return runTask(ctx, t, request, ping)
	}
	bad := Result{Lease: t.Lease, State: "failed", Error: "configuration_unavailable"}
	if h.Version != 1 || len(h.Landings) > 8 || t.Payload.Target != Target {
		return bad
	}
	source := h.EntrySource
	if source == "" {
		source = "public_roundtrip"
	}
	if source != "public_roundtrip" && source != "local_listener_roundtrip" && source != "cdn_roundtrip" {
		return bad
	}
	seen := map[string]bool{}
	for _, l := range h.Landings {
		if (l.Kernel != "" && l.Kernel != "xray" && l.Kernel != "singbox") || (l.Error != "" && l.Error != "configuration_unavailable") {
			return bad
		}
		if l.ID == "" || len(l.ID) > 100 || len(l.Name) > 800 || seen[l.ID] || l.Outbound.ProxyTag != "" {
			return bad
		}
		seen[l.ID] = true
		if l.Error != "" {
			continue
		}
		switch l.Outbound.Protocol {
		case "socks", "shadowsocks", "vless", "vmess", "trojan", "hysteria2", "tuic", "anytls":
		default:
			return bad
		}
	}
	// A whole task has a strict deadline; each landing gets at most ten seconds.
	deadline := time.Now().Add(90 * time.Second)
	if t.Expires.Before(deadline) {
		deadline = t.Expires
	}
	job, cancel := context.WithDeadline(ctx, deadline)
	defer cancel()
	r := runTask(job, t, request, ping)
	r.Stages = &Stages{Version: 1, Entry: Stage{State: r.State, Error: r.Error, Source: source}, Landings: make([]LandingResult, 0, len(h.Landings))}
	for _, l := range h.Landings {
		p := t.Payload
		p.HealthStages = nil
		p.Outbound = l.Outbound
		if l.Kernel != "" {
			p.Kernel = l.Kernel
		}
		p.Target = Target
		p.PingHost = ""
		lt := t
		lt.Payload = p
		lt.Timeout = 10000
		lr := Result{State: "failed", Error: "timeout"}
		if l.Error != "" {
			lr.Error = l.Error
		} else if job.Err() == nil {
			lr = runTask(job, lt, request, ping)
		} else if time.Now().After(t.Expires) {
			lr.State = "expired"
			lr.Error = "expired"
		}
		r.Stages.Landings = append(r.Stages.Landings, LandingResult{ID: l.ID, State: lr.State, Error: lr.Error})
	}
	return r
}
