package machine

import (
	"encoding/json"
	"github.com/cedar2025/xboard-node/internal/probe"
	"testing"
)

func TestOnenewArgoProbeMatchesLocalListener(t *testing.T) {
	for _, wire := range []string{
		`{"payload":{"kernel_type":"singbox","outbound":{"protocol":"vless","settings":{"server":"127.0.0.1","server_port":25002,"uuid":"fixture","tls":{"enabled":true,"server_name":"edge.example"},"transport":{"type":"ws","path":"/"}}}}}`,
		`{"payload":{"kernel_type":"xray","outbound":{"protocol":"vless","settings":{"vnext":[{"address":"127.0.0.1","port":25002,"users":[{"id":"fixture"}]}]},"stream_settings":{"security":"tls","tlsSettings":{"serverName":"edge.example"},"network":"ws","wsSettings":{"path":"/"}}}}}`,
	} {
		var task probe.Task
		if err := json.Unmarshal([]byte(wire), &task); err != nil {
			t.Fatal(err)
		}
		before, _ := json.Marshal(task)
		got := argoProbe(task, 25002)
		if got.Payload.Kernel == "singbox" {
			if _, ok := got.Payload.Outbound.Settings["tls"]; ok {
				t.Fatal("probe still TLS")
			}
			if got.Payload.Outbound.Settings["uuid"] != "fixture" {
				t.Fatal("lost authentication")
			}
		} else {
			if got.Payload.Outbound.StreamSettings["security"] != "none" {
				t.Fatal("xray probe still TLS")
			}
			if got.Payload.Outbound.StreamSettings["network"] != "ws" {
				t.Fatal("lost transport")
			}
		}
		after, _ := json.Marshal(task)
		if string(before) != string(after) {
			t.Fatal("mutated original task")
		}
		unchanged, _ := json.Marshal(argoProbe(task, 25003))
		if string(before) != string(unchanged) {
			t.Fatal("changed another node")
		}
		unchanged, _ = json.Marshal(argoProbe(task, 0))
		if string(before) != string(unchanged) {
			t.Fatal("changed certificate-managed node")
		}
		task.Payload.Action = "diagnostic"
		before, _ = json.Marshal(task)
		unchanged, _ = json.Marshal(argoProbe(task, 25002))
		if string(before) != string(unchanged) {
			t.Fatal("changed diagnostics")
		}
	}
}
func TestOnenewArgoPreservesRemoteAndReality(t *testing.T) {
	for _, wire := range []string{
		`{"payload":{"kernel_type":"singbox","outbound":{"protocol":"vless","settings":{"server":"remote.example","server_port":25002,"tls":{"enabled":true}}}}}`,
		`{"payload":{"kernel_type":"singbox","outbound":{"protocol":"vless","settings":{"server":"127.0.0.1","server_port":25002,"tls":{"enabled":true,"reality":{"enabled":true}}}}}}`,
		`{"payload":{"kernel_type":"xray","outbound":{"protocol":"vless","settings":{"vnext":[{"address":"127.0.0.1","port":25002}]},"stream_settings":{"security":"reality"}}}}`,
	} {
		var task probe.Task
		json.Unmarshal([]byte(wire), &task)
		a, _ := json.Marshal(task)
		b, _ := json.Marshal(argoProbe(task, 25002))
		if string(a) != string(b) {
			t.Fatal("changed remote or REALITY")
		}
	}
}
