package machine

import (
	"context"
	"fmt"
	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/controlplane"
	"github.com/cedar2025/xboard-node/internal/panel"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func TestOnenewDiscoveryResolvesChangedTransportBeforeNoop(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Path == "/api/v2/server/config" {
			fmt.Fprint(w, `{"protocol":"vless","network":"xhttp","server_port":12345}`)
			return
		}
		http.Error(w, "test stops after kernel selection", 503)
	}))
	defer server.Close()
	cfg := &config.Config{Panel: config.PanelConfig{URL: server.URL}, Machine: &config.MachineConfig{MachineID: 1, Token: "test"}, Kernel: config.KernelConfig{Type: "singbox", ConfigDir: t.TempDir()}}
	o := New(cfg)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()
	done := make(chan struct{})
	stopped := false
	old := &nodeHandle{discoveredType: "vless", kernel: cfg.Kernel, done: done, cancel: func() { stopped = true; close(done) }, mailbox: controlplane.NewNodeMailbox()}
	o.nodes[2] = old
	o.startNode(ctx, panel.MachineNode{ID: 2, Type: "vless"})
	if !stopped {
		t.Fatal("transport changed to xhttp but running singbox node was treated as unchanged")
	}
	o.mu.Lock()
	h := o.nodes[2]
	o.mu.Unlock()
	if h.kernel.Type != "xray" {
		t.Fatalf("resolved kernel = %s want xray", h.kernel.Type)
	}
	cancel()
	select {
	case <-h.done:
	case <-time.After(3 * time.Second):
		t.Fatal("replacement node did not stop")
	}
}
