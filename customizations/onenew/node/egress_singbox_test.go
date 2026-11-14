package singbox

import (
	"context"
	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/kernel"
	"github.com/cedar2025/xboard-node/internal/model"
	singBufio "github.com/sagernet/sing/common/bufio"
	"net"
	"testing"
)

func TestPanelEgressSingboxPreservesFallback(t *testing.T) {
	local := config.KernelConfig{Type: "singbox", IPFamily: map[string]string{"singbox": "ipv4_only"}}
	for _, mode := range []string{"prefer_ipv4", "prefer_ipv6", "default"} {
		n := &model.NodeSpec{Protocol: "socks", IPFamily: mode}
		cfg := buildConfig(local, n, nil, kernel.TLSCert{})
		rules, _ := cfg["route"].(M)["rules"].([]M)
		found := false
		for _, r := range rules {
			if r["action"] == "resolve" {
				found = true
				if r["strategy"] != mode {
					t.Fatalf("unexpected strategy: %v", r)
				}
			}
			if r["ip_version"] != nil {
				t.Fatal("panel preference blocked a family")
			}
		}
		if found != (mode != "default") {
			t.Fatalf("unexpected resolve rule for %s: %v", mode, rules)
		}
	}
	if local.IPFamily["singbox"] != "ipv4_only" {
		t.Fatal("shared machine config mutated")
	}
}

func TestPanelEgressClosesTrackedTCPAndUDP(t *testing.T) {
	for _, network := range []string{"tcp", "udp"} {
		t.Run(network, func(t *testing.T) {
			tracker := NewConnTracker(0)
			tracker.SetUserMap(map[string]int{"uuid-1": 1})
			if network == "tcp" {
				a, b := net.Pipe()
				defer a.Close()
				defer b.Close()
				tracker.RoutedConnection(context.Background(), a, testInboundContext("uuid-1", "127.0.0.1"), nil, nil)
			} else {
				packet, err := net.ListenPacket("udp", "127.0.0.1:0")
				if err != nil {
					t.Fatal(err)
				}
				defer packet.Close()
				tracker.RoutedPacketConnection(context.Background(), singBufio.NewPacketConn(packet), testInboundContext("uuid-1", "127.0.0.1"), nil, nil)
			}
			old := &model.NodeSpec{Protocol: "socks", IPFamily: "prefer_ipv4"}
			next := *old
			next.IPFamily = "prefer_ipv6"
			s := New(config.KernelConfig{Type: "singbox"})
			s.nodeConfig = old
			s.connTracker = tracker
			s.hotApplyCloseOldExit(&next, nil, kernel.TLSCert{})
			if tracker.ActiveCount() != 0 {
				t.Fatal("old egress sessions survived preference change")
			}
		})
	}
}
