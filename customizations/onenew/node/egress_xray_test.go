package xray

import (
	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/kernel"
	"github.com/cedar2025/xboard-node/internal/model"
	"testing"
)

func TestPanelEgressXrayConfigAndHash(t *testing.T) {
	local := config.KernelConfig{Type: "xray", LogLevel: "error", IPFamily: map[string]string{"xray": "ipv4_only"}}
	n := &model.NodeSpec{Protocol: "vless", ServerPort: 12345, IPFamily: "prefer_ipv6"}
	oldHash := kernel.ComputeHash(n, nil)
	for _, tt := range []struct{ mode, strategy string }{{"prefer_ipv6", "UseIPv6v4"}, {"prefer_ipv4", "UseIPv4v6"}, {"default", ""}} {
		n.IPFamily = tt.mode
		cfg := buildConfig(local, n, nil, kernel.TLSCert{})
		found := false
		for _, out := range cfg["outbounds"].([]M) {
			if out["protocol"] == "blackhole" && out["tag"] == "__xboard_ipfamily_deny" {
				t.Fatal("panel preference added a strict family deny")
			}
			if out["protocol"] != "freedom" {
				continue
			}
			found = true
			strategy := ""
			if stream, ok := out["streamSettings"].(M); ok {
				if sock, ok := stream["sockopt"].(M); ok {
					strategy, _ = sock["domainStrategy"].(string)
				}
			}
			if strategy != tt.strategy {
				t.Fatalf("%s: got %s", tt.mode, strategy)
			}
		}
		if !found {
			t.Fatal("direct outbound missing")
		}
	}
	if kernel.ComputeHash(n, nil) == oldHash {
		t.Fatal("preference change cannot trigger Xray reload")
	}
	if local.IPFamily["xray"] != "ipv4_only" {
		t.Fatal("shared local config changed")
	}
}
