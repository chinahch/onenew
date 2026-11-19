package probe

import (
	"context"
	"fmt"
	"github.com/cedar2025/xboard-node/internal/panel"
	"time"
)

func stagedProtocolAllowed(protocol string) bool {
	switch protocol {
	case "socks", "shadowsocks", "vless", "vmess", "trojan", "hysteria2", "tuic", "anytls", "http":
		return true
	}
	return false
}
func fallbackEndpointValid(out panel.OutboundConfig) bool {
	if out.ProxyTag != "" || !stagedProtocolAllowed(out.Protocol) {
		return false
	}
	host, _ := out.Settings["server"].(string)
	port := out.Settings["server_port"]
	if host == "" {
		key := "vnext"
		if out.Protocol == "socks" || out.Protocol == "trojan" || out.Protocol == "shadowsocks" {
			key = "servers"
		}
		if entries, ok := out.Settings[key].([]any); ok && len(entries) == 1 {
			if s, ok := entries[0].(map[string]any); ok {
				host, _ = s["address"].(string)
				port = s["port"]
			}
		}
	}
	return host == "ip.sb" && fmt.Sprint(port) == "443"
}
func runStagedV2(ctx context.Context, t Task, request func(context.Context, Payload) (string, int64)) Result {
	h := t.Payload.HealthStages
	invalid := Result{Lease: t.Lease, State: "failed", Error: "configuration_unavailable"}
	if h == nil || h.Version != 2 || len(h.Landings) > 8 || t.Payload.Target != Target || (h.EntrySource != "cdn_roundtrip" && h.EntrySource != "local_listener_roundtrip") {
		return invalid
	}
	if h.FallbackOutbound != nil && (h.EntrySource != "cdn_roundtrip" || h.FallbackOutbound.Protocol != t.Payload.Outbound.Protocol || !fallbackEndpointValid(*h.FallbackOutbound)) {
		return invalid
	}
	seen := map[string]bool{}
	for _, l := range h.Landings {
		if l.ID == "" || len(l.ID) > 100 || len(l.Name) > 800 || seen[l.ID] || l.Outbound.ProxyTag != "" || (l.Kernel != "" && l.Kernel != "xray" && l.Kernel != "singbox") || (l.Error != "" && l.Error != "configuration_unavailable") {
			return invalid
		}
		seen[l.ID] = true
		if l.Error == "" && !stagedProtocolAllowed(l.Outbound.Protocol) {
			return invalid
		}
	}
	deadline := time.Now().Add(120 * time.Second)
	if t.Expires.Before(deadline) {
		deadline = t.Expires
	}
	job, cancel := context.WithDeadline(ctx, deadline)
	defer cancel()
	// A stage that never starts is expired, not a failed website request.
	attempt := func(p Payload) Result {
		if job.Err() != nil || !time.Now().Before(t.Expires) {
			return Result{Lease: t.Lease, State: "expired", Error: "expired"}
		}
		p.PingHost = ""
		p.HealthStages = nil
		stage := t
		stage.Payload = p
		stage.Timeout = 10000
		return runTask(job, stage, request, nil)
	}
	r := attempt(t.Payload)
	primary := &StageAttempt{State: r.State, Error: r.Error}
	stages := &Stages{Version: 2, Entry: Stage{State: r.State, Error: r.Error, Source: h.EntrySource, Primary: primary}, Landings: make([]LandingResult, 0, len(h.Landings))}
	if r.State == "failed" && r.Error != "configuration_unavailable" && r.Error != "unsupported" && h.FallbackOutbound != nil && job.Err() == nil && time.Now().Before(t.Expires) {
		p := t.Payload
		p.Outbound = *h.FallbackOutbound
		r = attempt(p)
		stages.Entry.Fallback = &StageAttempt{State: r.State, Error: r.Error}
		stages.Entry.State = r.State
		stages.Entry.Error = r.Error
	}
	// If an eligible backup could not start, do not present the original failure
	// as evidence that both CDN paths failed.
	if primary.State == "failed" && primary.Error != "configuration_unavailable" && primary.Error != "unsupported" && h.FallbackOutbound != nil && stages.Entry.Fallback == nil {
		r = Result{Lease: t.Lease, State: "expired", Error: "expired"}
		stages.Entry.State = r.State
		stages.Entry.Error = r.Error
	}
	for _, l := range h.Landings {
		lr := Result{Lease: t.Lease, State: "expired", Error: "expired"}
		if l.Error != "" {
			lr.State = "failed"
			lr.Error = l.Error
		} else {
			p := t.Payload
			p.Outbound = l.Outbound
			if l.Kernel != "" {
				p.Kernel = l.Kernel
			}
			lr = attempt(p)
		}
		stages.Landings = append(stages.Landings, LandingResult{ID: l.ID, State: lr.State, Error: lr.Error})
	}
	r.Stages = stages
	return r
}
