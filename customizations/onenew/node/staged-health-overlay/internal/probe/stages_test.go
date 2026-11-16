package probe

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"github.com/cedar2025/xboard-node/internal/panel"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func TestStagesIndependentOrderedAndBounded(t *testing.T) {
	task := Task{ID: "fixture", Lease: "lease", Expires: time.Now().Add(time.Minute), Payload: Payload{Kernel: "xray", Target: Target, Outbound: panel.OutboundConfig{Tag: "entry"}, HealthStages: &HealthStages{Version: 1, Landings: []Landing{{ID: "a", Outbound: panel.OutboundConfig{Tag: "a", Protocol: "socks"}}, {ID: "b", Outbound: panel.OutboundConfig{Tag: "b", Protocol: "vless"}}}}}}
	calls := []string{}
	request := func(ctx context.Context, p Payload) (string, int64) {
		calls = append(calls, p.Outbound.Tag)
		if _, ok := ctx.Deadline(); !ok {
			t.Fatal("unbounded request")
		}
		if p.Outbound.Tag == "a" {
			return "proxy_failed", 0
		}
		return "", 0
	}
	ping := func(context.Context, string) (*float64, string) { return nil, "" }
	r := runStaged(context.Background(), task, request, ping)
	if len(calls) != 3 || calls[0] != "entry" || calls[1] != "a" || calls[2] != "b" || r.Stages.Entry.Source != "public_roundtrip" || r.Stages.Landings[0].Error != "proxy_failed" || r.Stages.Landings[1].State != "succeeded" {
		t.Fatalf("bad stages: %+v calls %v", r.Stages, calls)
	}
	task.Payload.HealthStages.Landings[1].ID = "a"
	calls = nil
	r = runStaged(context.Background(), task, request, ping)
	if r.Error != "configuration_unavailable" || len(calls) != 0 {
		t.Fatal("duplicate ids accepted")
	}
}

func TestStagesExpiredTaskDoesNotRequest(t *testing.T) {
	task := Task{ID: "fixture", Lease: "lease", Expires: time.Now().Add(-time.Second), Payload: Payload{Kernel: "xray", Target: Target, HealthStages: &HealthStages{Version: 1, Landings: []Landing{{ID: "a", Outbound: panel.OutboundConfig{Protocol: "socks"}}}}}}
	r := runStaged(context.Background(), task, func(context.Context, Payload) (string, int64) { t.Fatal("expired task executed"); return "", 0 }, func(context.Context, string) (*float64, string) { return nil, "" })
	if r.State != "expired" || r.Stages.Landings[0].State != "expired" {
		t.Fatalf("%+v", r)
	}
}

func TestStagedRealLandingAuthenticationBothKernels(t *testing.T) {
	target := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte("ip=203.0.113.4\ncolo=LAX\nloc=US\n")) }))
	defer target.Close()
	roots := x509.NewCertPool()
	roots.AddCert(target.Certificate())
	port := testPort(t)
	closeServer := testInbound(t, port)
	defer closeServer()
	for _, kernel := range []string{"xray", "singbox"} {
		t.Run(kernel, func(t *testing.T) {
			makeOutbound := func(id string) panel.OutboundConfig {
				o := panel.OutboundConfig{Tag: "probe", Protocol: "vless"}
				if kernel == "xray" {
					o.Settings = map[string]any{"vnext": []any{map[string]any{"address": "127.0.0.1", "port": port, "users": []any{map[string]any{"id": id, "encryption": "none"}}}}}
				} else {
					o.Settings = map[string]any{"server": "127.0.0.1", "server_port": port, "uuid": id}
				}
				return o
			}
			good := makeOutbound("00000000-0000-4000-8000-000000000001")
			bad := makeOutbound("00000000-0000-4000-8000-000000000002")
			task := Task{ID: "fixture", Lease: "lease", Expires: time.Now().Add(time.Minute), Timeout: 500, Payload: Payload{Kernel: kernel, Target: Target, Outbound: good, HealthStages: &HealthStages{Version: 1, Landings: []Landing{{ID: "good", Outbound: good}, {ID: "bad", Outbound: bad}}}}}
			r := runStaged(context.Background(), task, func(ctx context.Context, p Payload) (string, int64) {
				p.Target = target.URL
				return execute(ctx, p, &tls.Config{RootCAs: roots})
			}, func(context.Context, string) (*float64, string) { return nil, "" })
			if r.State != "succeeded" || r.Stages.Landings[0].State != "succeeded" || r.Stages.Landings[1].State == "succeeded" {
				t.Fatalf("%+v", r.Stages)
			}
		})
	}
}
