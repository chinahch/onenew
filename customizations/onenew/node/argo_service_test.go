package service

import (
	"context"
	"github.com/cedar2025/xboard-node/internal/cert"
	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/model"
	"testing"
)

func TestOnenewArgoPlaintextCertificateGate(t *testing.T) {
	for _, mode := range []string{"none", ""} {
		k := &fakeKernel{}
		s := newTestService(k)
		s.cfg = &config.Config{Cert: config.CertConfig{CertMode: mode}}
		if err := s.cert.Start(context.Background()); err != nil {
			t.Fatal(err)
		}
		n := &model.NodeSpec{Protocol: "vless", Network: "ws", TLS: 1, ServerPort: 25002}
		if !s.certificateReady(n) {
			t.Fatal("Argo WS listener rejected with no certificate policy")
		}
		if !s.startKernel(n, []model.UserSpec{{ID: 1, UUID: "fixture"}}) || !k.running {
			t.Fatal("Argo kernel did not start")
		}
	}
}

func TestOnenewArgoRejectsConfiguredCertificateAndRequiredTLS(t *testing.T) {
	for _, c := range []config.CertConfig{{CertMode: "content"}, {CertMode: "file"}, {CertMode: "dns"}, {CertMode: "http"}, {CertMode: "self"}, {AutoTLS: true}, {CertFile: "missing"}, {CertContent: "invalid"}} {
		s := newTestService(&fakeKernel{})
		s.cfg = &config.Config{Cert: c}
		if s.certificateReady(&model.NodeSpec{Protocol: "vless", TLS: 1, ServerPort: 25002}) {
			t.Fatalf("configured certificate became plaintext: %s", c.CertMode)
		}
	}
	for _, protocol := range []string{"trojan", "hysteria", "tuic", "anytls"} {
		s := newTestService(&fakeKernel{})
		s.cfg = &config.Config{}
		if s.certificateReady(&model.NodeSpec{Protocol: protocol, TLS: 1}) {
			t.Fatal("required TLS bypassed", protocol)
		}
	}
}
func TestOnenewArgoCertificateTransitions(t *testing.T) {
	s := newTestService(&fakeKernel{})
	s.cfg = &config.Config{}
	n := &model.NodeSpec{Protocol: "vless", Network: "ws", TLS: 1, ServerPort: 25002}
	if !s.certificateReady(n) || s.ProbePlaintextPort() != 25002 {
		t.Fatal("missing plaintext probe snapshot")
	}
	n.CertConfig = &config.CertConfig{CertMode: "content", CertContent: "invalid", KeyContent: "invalid"}
	s.applyRemoteOverrides(context.Background(), n)
	if s.certificateReady(n) || s.ProbePlaintextPort() != 0 {
		t.Fatal("failed certificate downgraded")
	}
	n.CertConfig = nil
	s.applyRemoteOverrides(context.Background(), n)
	if !s.certificateReady(n) || s.ProbePlaintextPort() != 25002 {
		t.Fatal("return to none did not recover")
	}
	s.cert = cert.NewManager(config.CertConfig{CertMode: "self", Domain: "edge.example.test"})
	if err := s.cert.Start(context.Background()); err != nil {
		t.Fatal(err)
	}
	n.ServerName = "edge.example.test"
	n.CertConfig = &config.CertConfig{CertMode: "self"}
	if !s.certificateReady(n) || s.ProbePlaintextPort() != 0 {
		t.Fatal("valid certificate did not restore TLS")
	}
	n.TLS = 2
	if !s.certificateReady(n) || s.ProbePlaintextPort() != 0 {
		t.Fatal("REALITY changed")
	}
}
