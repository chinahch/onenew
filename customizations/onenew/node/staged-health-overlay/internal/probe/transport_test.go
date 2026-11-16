package probe

import (
	"context"
	"errors"
	"github.com/cedar2025/xboard-node/internal/panel"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"
)

func TestBadTargetCertificateCannotBlameLanding(t *testing.T) {
	target := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { w.Write([]byte("ip=203.0.113.4\ncolo=LAX\nloc=US\n")) }))
	defer target.Close()
	port := testPort(t)
	closeServer := testInbound(t, port)
	defer closeServer()
	for _, kernel := range []string{"xray", "singbox"} {
		t.Run(kernel, func(t *testing.T) {
			o := panel.OutboundConfig{Tag: "probe", Protocol: "vless"}
			if kernel == "xray" {
				o.Settings = map[string]any{"vnext": []any{map[string]any{"address": "127.0.0.1", "port": port, "users": []any{map[string]any{"id": "00000000-0000-4000-8000-000000000001", "encryption": "none"}}}}}
			} else {
				o.Settings = map[string]any{"server": "127.0.0.1", "server_port": port, "uuid": "00000000-0000-4000-8000-000000000001"}
			}
			ctx, cancel := context.WithTimeout(context.Background(), 2*time.Second)
			defer cancel()
			code, _ := execute(ctx, Payload{Kernel: kernel, Outbound: o, Target: target.URL}, nil)
			if code != "target_failed" {
				t.Fatalf("bad target certificate classified %s", code)
			}
		})
	}
}
func TestAmbiguousErrorsNeedIndependentEndpointEvidence(t *testing.T) {
	p := Payload{Outbound: panel.OutboundConfig{Protocol: "vless"}}
	if requestError(context.Background(), p, errors.New("opaque transport failure")) != "transport_failed" {
		t.Fatal("ambiguous failure blamed proxy")
	}
	p.Outbound.Settings = map[string]any{"server": "127.0.0.1", "server_port": testPort(t)}
	if requestError(context.Background(), p, errors.New("opaque transport failure")) != "proxy_failed" {
		t.Fatal("refused endpoint not detected")
	}
	p.Outbound.StreamSettings = map[string]any{"network": "quic"}
	if requestError(context.Background(), p, errors.New("opaque")) != "transport_failed" {
		t.Fatal("QUIC probed as TCP")
	}
	p.Outbound.StreamSettings = nil
	p.Outbound.Protocol = "tuic"
	if requestError(context.Background(), p, errors.New("opaque transport failure")) != "transport_failed" {
		t.Fatal("UDP probed as TCP")
	}
	ctx, cancel := context.WithCancel(context.Background())
	cancel()
	if requestError(ctx, p, errors.New("opaque")) != "timeout" {
		t.Fatal("cancel ignored")
	}
}
