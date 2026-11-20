package service

import (
	"context"
	"errors"
	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/controlplane"
	"github.com/cedar2025/xboard-node/internal/model"
	"github.com/cedar2025/xboard-node/internal/monitor"
	"github.com/cedar2025/xboard-node/internal/panel"
	"testing"
)

func TestOnenewMetricsReportActualKernel(t *testing.T) {
	for _, kind := range []string{"xray", "singbox"} {
		cfg := &config.Config{Kernel: config.KernelConfig{Type: kind}}
		cp := controlplane.NewMachinePanelControlPlane(panel.NewClient(config.PanelConfig{}), cfg.Kernel, nil, nil)
		s := NewWithControlPlane(cfg, cp)
		m := s.buildMetrics(monitor.Status{})
		if m["kernel_type"] != kind || m["onenew_runtime"] != true {
			t.Fatalf("actual runtime metadata missing: %#v", m)
		}
	}
}

func TestOnenewRejectedConfigDoesNotSuppressRetry(t *testing.T) {
	k := &fakeKernel{running: true, startErr: errors.New("temporary bind failure")}
	s := newTestService(k)
	s.lastConfigHash = "pending-config"
	if s.startKernel(&model.NodeSpec{Protocol: "vless", ServerPort: 12345}, []model.UserSpec{{ID: 1, UUID: "uuid"}}) {
		t.Fatal("invalid update applied")
	}
	if s.lastConfigHash != "" {
		t.Fatal("failed kernel update was cached as applied, suppressing future config retries")
	}
}

func TestOnenewReloadFailureStopsKernelBeforeRebinding(t *testing.T) {
	k := &fakeKernel{running: true, reloadErr: errors.New("address already in use")}
	s := newTestService(k)
	s.lastConfig = &model.NodeSpec{Protocol: "vless", ServerPort: 12345}
	s.lastUsers = []model.UserSpec{{ID: 1, UUID: "uuid"}}
	s.applyChanges(context.Background(), true, false)
	if k.stopCalls != 1 {
		t.Fatalf("stop calls = %d, want 1", k.stopCalls)
	}
	if k.startWhileRunning {
		t.Fatal("kernel restart attempted while the old listener was still running")
	}
	if !k.running || k.startCalls != 1 {
		t.Fatalf("kernel was not recovered after reload failure: running=%v starts=%d", k.running, k.startCalls)
	}
}

func TestOnenewFailedRestartRestoresPreviouslyAppliedConfig(t *testing.T) {
	k := &fakeKernel{running: true, reloadErr: errors.New("inbound recreation failed"), startErrors: []error{
		errors.New("temporary bind failure"),
		nil,
	}}
	s := newTestService(k)
	previous := &model.NodeSpec{Protocol: "vless", ServerPort: 12345}
	next := &model.NodeSpec{Protocol: "hysteria2", ServerPort: 12345}
	users := []model.UserSpec{{ID: 1, UUID: "uuid"}}
	s.appliedState.Config = previous
	s.appliedState.Users = users
	s.lastConfig = next
	s.lastUsers = users
	s.lastConfigHash = "new-config"

	s.applyChanges(context.Background(), true, false)
	if k.startCalls != 2 || !k.running {
		t.Fatalf("failed new config did not recover the prior service: starts=%d running=%v", k.startCalls, k.running)
	}
	if s.appliedState.Config != previous {
		t.Fatal("previously applied config was not restored")
	}
	if s.lastConfigHash != "" {
		t.Fatal("failed config hash was retained instead of allowing a retry")
	}
}

func TestOnenewProtocolChangeStopsKernelBeforeStartingReplacement(t *testing.T) {
	k := &fakeKernel{running: true}
	s := newTestService(k)
	previous := &model.NodeSpec{Protocol: "vless", Network: "xhttp", ServerPort: 12345}
	next := &model.NodeSpec{Protocol: "hysteria", Version: 2, ServerPort: 12345}
	users := []model.UserSpec{{ID: 1, UUID: "uuid"}}
	s.appliedState.Config = previous
	s.appliedState.Users = users
	s.lastConfig = next
	s.lastUsers = users

	s.applyChanges(context.Background(), true, false)
	if k.stopCalls != 1 || k.startCalls != 1 {
		t.Fatalf("protocol update did not cleanly restart the listener: stops=%d starts=%d", k.stopCalls, k.startCalls)
	}
	if k.startWhileRunning {
		t.Fatal("replacement listener started before the prior listener was stopped")
	}
	if s.appliedState.Config != next || !k.running {
		t.Fatal("updated protocol was not applied")
	}
}

func TestOnenewListenerTopologyChangesAndSafeHotUpdates(t *testing.T) {
	old := model.NodeSpec{Protocol: "vless", Network: "xhttp", ServerPort: 12345}
	for _, tc := range []struct {
		name    string
		change  func(*model.NodeSpec)
		restart bool
	}{
		{"same config", func(*model.NodeSpec) {}, false},
		{"routing", func(n *model.NodeSpec) { n.CustomRoutes = []map[string]any{{"outbound": "direct"}} }, false},
		{"port", func(n *model.NodeSpec) { n.ServerPort++ }, true},
		{"address", func(n *model.NodeSpec) { n.ListenIP = "127.0.0.1" }, true},
		{"transport", func(n *model.NodeSpec) { n.Network = "ws" }, true},
		{"protocol", func(n *model.NodeSpec) { n.Protocol = "hysteria"; n.Version = 2; n.Network = "" }, true},
	} {
		t.Run(tc.name, func(t *testing.T) {
			next := old
			tc.change(&next)
			if got := listenerTopologyChanged(&old, &next); got != tc.restart {
				t.Fatalf("restart = %v, want %v", got, tc.restart)
			}
			if got := listenerTopologyChanged(&next, &old); got != tc.restart {
				t.Fatalf("reverse restart = %v, want %v", got, tc.restart)
			}
			k := &fakeKernel{running: true}
			s := newTestService(k)
			s.appliedState.Config = &old
			s.lastConfig = &next
			s.lastUsers = []model.UserSpec{{ID: 1, UUID: "uuid"}}
			s.applyChanges(context.Background(), true, false)
			if (k.stopCalls != 0) != tc.restart || (k.startCalls != 0) != tc.restart {
				t.Fatalf("stops=%d starts=%d restart=%v", k.stopCalls, k.startCalls, tc.restart)
			}
		})
	}
}
