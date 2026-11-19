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

func v2Task() Task {
	fallback := panel.OutboundConfig{Tag: "backup", Protocol: "vless", Settings: map[string]any{"server": "ip.sb", "server_port": 443}}
	return Task{ID: "fixture", Lease: "lease", Expires: time.Now().Add(2 * time.Minute), Timeout: 90000, Payload: Payload{Kernel: "singbox", Target: Target, Outbound: panel.OutboundConfig{Tag: "original", Protocol: "vless"}, HealthStages: &HealthStages{Version: 2, EntrySource: "cdn_roundtrip", FallbackOutbound: &fallback, Landings: []Landing{{ID: "landing", Outbound: panel.OutboundConfig{Tag: "landing", Protocol: "socks"}}}}}}
}
func TestV2OriginalFirstFallbackOnlyWhenNeeded(t *testing.T) {
	for _, tc := range []struct {
		name, original, backup string
		wantCalls              int
		wantState              string
	}{
		{"original healthy", "", "timeout", 2, "succeeded"},
		{"backup healthy", "timeout", "", 3, "succeeded"},
		{"both failed", "transport_failed", "timeout", 3, "failed"},
	} {
		t.Run(tc.name, func(t *testing.T) {
			task := v2Task()
			calls := []string{}
			r := runStaged(context.Background(), task, func(ctx context.Context, p Payload) (string, int64) {
				calls = append(calls, p.Outbound.Tag)
				if _, ok := ctx.Deadline(); !ok {
					t.Fatal("request unbounded")
				}
				switch p.Outbound.Tag {
				case "original":
					return tc.original, 0
				case "backup":
					return tc.backup, 0
				}
				return "", 0
			}, func(context.Context, string) (*float64, string) { t.Fatal("v2 does not ping"); return nil, "" })
			if r.State != tc.wantState || len(calls) != tc.wantCalls || calls[0] != "original" || calls[len(calls)-1] != "landing" || r.Stages.Version != 2 || r.Stages.Entry.Primary.Error != tc.original {
				t.Fatalf("result %+v calls %v", r, calls)
			}
			if tc.original == "" {
				if r.Stages.Entry.Fallback != nil {
					t.Fatal("healthy original tried fallback")
				}
			} else if r.Stages.Entry.Fallback == nil || r.Stages.Entry.Fallback.Error != tc.backup {
				t.Fatal("fallback evidence missing")
			}
			if r.Stages.Landings[0].State != "succeeded" {
				t.Fatal("entry failure skipped landing")
			}
		})
	}
}
func TestV2LocalHasNoPublicOrBackupAttempt(t *testing.T) {
	task := v2Task()
	task.Payload.HealthStages.EntrySource = "local_listener_roundtrip"
	task.Payload.HealthStages.FallbackOutbound = nil
	calls := 0
	r := runStaged(context.Background(), task, func(context.Context, Payload) (string, int64) { calls++; return "timeout", 0 }, nil)
	if calls != 2 || r.State != "failed" || r.Stages.Entry.Primary.Error != "timeout" || r.Stages.Entry.Fallback != nil {
		t.Fatalf("%+v calls%d", r, calls)
	}
}
func TestV2InvalidAndExpiredDoNotRequest(t *testing.T) {
	for _, mutate := range []func(*Task){func(t *Task) { t.Payload.HealthStages.EntrySource = "public_roundtrip" }, func(t *Task) { t.Payload.HealthStages.EntrySource = "local_listener_roundtrip" }, func(t *Task) { t.Payload.HealthStages.FallbackOutbound.Settings["server"] = "arbitrary.example" }, func(t *Task) { t.Payload.HealthStages.Landings[0].ID = "" }} {
		task := v2Task()
		mutate(&task)
		r := runStaged(context.Background(), task, func(context.Context, Payload) (string, int64) { t.Fatal("invalid executed"); return "", 0 }, nil)
		if r.Error != "configuration_unavailable" {
			t.Fatal(r)
		}
	}
	task := v2Task()
	task.Expires = time.Now().Add(-time.Second)
	r := runStaged(context.Background(), task, func(context.Context, Payload) (string, int64) { t.Fatal("expired executed"); return "", 0 }, nil)
	if r.State != "expired" || r.Stages.Entry.Primary.State != "expired" || r.Stages.Entry.Fallback != nil || r.Stages.Landings[0].State != "expired" {
		t.Fatalf("%+v", r)
	}
}
func TestV2CancellationDoesNotInventLandingFault(t *testing.T) {
	task := v2Task()
	ctx, cancel := context.WithCancel(context.Background())
	calls := 0
	r := runStaged(ctx, task, func(context.Context, Payload) (string, int64) { calls++; cancel(); return "timeout", 0 }, nil)
	if calls != 1 || r.State != "expired" || r.Error != "expired" || r.Stages.Entry.State != "expired" || r.Stages.Entry.Primary.State != "failed" || r.Stages.Entry.Fallback != nil || r.Stages.Landings[0].State != "expired" {
		t.Fatalf("%+v calls%d", r, calls)
	}
}

func TestV2RealProtocolFallbackBothKernels(t *testing.T) {
	target := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte("ip=203.0.113.4\ncolo=LAX\nloc=US\n")) }))
	defer target.Close()
	roots := x509.NewCertPool()
	roots.AddCert(target.Certificate())
	serverPort := testPort(t)
	stop := testInbound(t, serverPort)
	defer stop()
	closedPort := testPort(t)
	for _, kernel := range []string{"singbox", "xray"} {
		t.Run(kernel, func(t *testing.T) {
			task := v2Task()
			task.Payload.Kernel = kernel
			task.Payload.HealthStages.Landings = nil
			if kernel == "xray" {
				task.Payload.HealthStages.FallbackOutbound.Settings = map[string]any{"vnext": []any{map[string]any{"address": "ip.sb", "port": 443, "users": []any{map[string]any{"id": "00000000-0000-4000-8000-000000000001", "encryption": "none"}}}}}
			}
			calls := []string{}
			result := runStaged(context.Background(), task, func(ctx context.Context, p Payload) (string, int64) {
				calls = append(calls, p.Outbound.Tag)
				port := serverPort
				if p.Outbound.Tag == "original" {
					port = closedPort
				}
				// The network is isolated: map test endpoint attempts to real local protocol servers.
				if kernel == "xray" {
					p.Outbound.Settings = map[string]any{"vnext": []any{map[string]any{"address": "127.0.0.1", "port": port, "users": []any{map[string]any{"id": "00000000-0000-4000-8000-000000000001", "encryption": "none"}}}}}
				} else {
					p.Outbound.Settings = map[string]any{"server": "127.0.0.1", "server_port": port, "uuid": "00000000-0000-4000-8000-000000000001"}
				}
				p.Target = target.URL
				return execute(ctx, p, &tls.Config{RootCAs: roots})
			}, nil)
			if len(calls) != 2 || calls[0] != "original" || calls[1] != "backup" || result.State != "succeeded" || result.Stages.Entry.Primary.State != "failed" || result.Stages.Entry.Fallback.State != "succeeded" {
				t.Fatalf("%+v calls%v", result, calls)
			}
		})
	}
}
