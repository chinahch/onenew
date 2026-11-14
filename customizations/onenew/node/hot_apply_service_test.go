package service

import (
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
