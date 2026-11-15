package machine

import (
	"encoding/json"
	"github.com/cedar2025/xboard-node/internal/probe"
)

// Adapt only self-probes of a confirmed local plaintext listener. Exit probes,
// public endpoints and REALITY must retain their requested security settings.
func argoProbe(t probe.Task, port int) probe.Task {
	if port <= 0 || t.Payload.Action != "" || (t.Payload.Outbound.Protocol != "vless" && t.Payload.Outbound.Protocol != "vmess") {
		return t
	}
	raw, err := json.Marshal(t)
	if err != nil {
		return t
	}
	var out probe.Task
	if json.Unmarshal(raw, &out) != nil {
		return t
	}
	settings := out.Payload.Outbound.Settings
	if out.Payload.Kernel == "singbox" {
		p, _ := settings["server_port"].(float64)
		tls, _ := settings["tls"].(map[string]any)
		if settings["server"] != "127.0.0.1" || p != float64(port) || tls["enabled"] != true {
			return t
		}
		if reality, ok := tls["reality"].(map[string]any); ok && reality["enabled"] == true {
			return t
		}
		delete(settings, "tls")
	} else if out.Payload.Kernel == "xray" {
		stream := out.Payload.Outbound.StreamSettings
		if stream["security"] != "tls" {
			return t
		}
		servers, ok := settings["vnext"].([]any)
		if !ok || len(servers) != 1 {
			return t
		}
		server, ok := servers[0].(map[string]any)
		if !ok || server["address"] != "127.0.0.1" || server["port"] != float64(port) {
			return t
		}
		stream["security"] = "none"
		delete(stream, "tlsSettings")
	} else {
		return t
	}
	return out
}

func (o *Orchestrator) localArgoProbe(t probe.Task) probe.Task {
	o.mu.Lock()
	defer o.mu.Unlock()
	for _, h := range o.nodes {
		if h.service != nil {
			t = argoProbe(t, h.service.ProbePlaintextPort())
		}
	}
	return t
}
