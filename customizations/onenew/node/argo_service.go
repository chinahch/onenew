package service

import (
	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/model"
	"strings"
)

// Only an intentional no-certificate policy permits the upstream TLS-offload
// behavior. A failed issuer or missing configured file is never a plaintext fallback.
func (s *Service) argoPlaintext(n *model.NodeSpec) bool {
	if n == nil || n.TLS != 1 || (n.Protocol != "vless" && n.Protocol != "vmess") || s.cert == nil || s.cert.State() != "none" || s.cert.HasCert() {
		return false
	}
	c := config.CertConfig{}
	if s.cfg != nil {
		c = s.cfg.Cert
	}
	if n.CertConfig != nil {
		c = *n.CertConfig
	}
	mode := strings.ToLower(strings.TrimSpace(c.CertMode))
	return mode == "none" || (mode == "" && !c.AutoTLS && c.CertContent == "" && c.KeyContent == "" && c.CertFile == "" && c.KeyFile == "")
}

// Probe workers read an atomic snapshot, never the mutable configuration maps.
func (s *Service) ProbePlaintextPort() int {
	if s.cert == nil || s.cert.State() != "none" || s.cert.HasCert() {
		return 0
	}
	return int(s.plainProbePort.Load())
}
