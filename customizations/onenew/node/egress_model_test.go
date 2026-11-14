package model

import (
	"encoding/json"
	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/panel"
	"testing"
)

func TestPanelEgressRoundTripAndIsolation(t *testing.T) {
	var nc panel.NodeConfig
	if err := json.Unmarshal([]byte(`{"protocol":"vless","ip_family":"prefer_ipv6"}`), &nc); err != nil {
		t.Fatal(err)
	}
	n := NodeSpecFromPanel(&nc)
	local := config.KernelConfig{IPFamily: map[string]string{"xray": "ipv4_only", "singbox": "ipv4_only"}}
	got := PanelIPFamily(local, n)
	if got.IPFamily["xray"] != "prefer_ipv6" || got.IPFamily["singbox"] != "prefer_ipv6" {
		t.Fatalf("panel preference lost: %+v", got.IPFamily)
	}
	if local.IPFamily["xray"] != "ipv4_only" {
		t.Fatal("shared machine config mutated")
	}
	if n.ToPanel().IPFamily != "prefer_ipv6" {
		t.Fatal("panel round trip lost preference")
	}
	n.IPFamily = "default"
	if PanelIPFamily(local, n).IPFamily["xray"] != "" {
		t.Fatal("explicit default must clear local preference")
	}
	n.IPFamily = ""
	if PanelIPFamily(local, n).IPFamily["xray"] != "ipv4_only" {
		t.Fatal("legacy absent setting must retain local config")
	}
	n.IPFamily = "ipv4_only"
	if ValidateNodeSpec(n, config.KernelConfig{Type: "xray"}) == nil {
		t.Fatal("strict panel mode accepted")
	}
}
