// Package exitprobe executes an unsaved landing proxy on its selected machine.
package exitprobe

import (
	"context"
	"crypto/tls"
	"fmt"
	"github.com/cedar2025/xboard-node/internal/kernel/singbox"
	"github.com/cedar2025/xboard-node/internal/kernel/xray"
	"github.com/cedar2025/xboard-node/internal/model"
	"github.com/cedar2025/xboard-node/internal/panel"
	"github.com/cedar2025/xboard-node/internal/probe"
	"golang.org/x/net/proxy"
	"io"
	"net"
	"net/http"
	"strings"
	"time"
)

const Target = "https://www.cloudflare.com/cdn-cgi/trace"

type Result struct {
	Lease   string `json:"lease"`
	State   string `json:"state"`
	Error   string `json:"error_code"`
	IP      string `json:"exit_ip,omitempty"`
	Country string `json:"country,omitempty"`
}

func Run(ctx context.Context, t probe.Task) Result {
	r := Result{Lease: t.Lease, State: "failed"}
	if time.Now().After(t.Expires) {
		r.State = "expired"
		r.Error = "expired"
		return r
	}
	p := t.Payload
	if p.Action != "exit_trace" || p.Target != Target || len(t.ID) != 36 || len(t.Lease) < 1 || len(t.Lease) > 100 || (p.Kernel != "xray" && p.Kernel != "singbox") || p.Outbound.ProxyTag != "" {
		r.Error = "configuration_unavailable"
		return r
	}
	allowed := map[string]bool{"socks": true, "shadowsocks": true, "vless": true, "vmess": true, "trojan": true, "hysteria2": true, "tuic": true, "anytls": true}
	if !allowed[p.Outbound.Protocol] {
		r.Error = "unsupported"
		return r
	}
	deadline := time.Now().Add(10 * time.Second)
	if t.Expires.Before(deadline) {
		deadline = t.Expires
	}
	job, cancel := context.WithDeadline(ctx, deadline)
	defer cancel()
	r.IP, r.Country, r.Error = execute(job, p, nil, start)
	if r.Error == "" {
		r.State = "succeeded"
	}
	return r
}
func start(kernel string, port int, out model.OutboundConfig) (func(), error) {
	if kernel == "xray" {
		return xray.StartProbe(port, out)
	}
	return singbox.StartProbe(port, out)
}
func execute(ctx context.Context, p probe.Payload, tlsConfig *tls.Config, launch func(string, int, model.OutboundConfig) (func(), error)) (string, string, string) {
	l, e := net.Listen("tcp", "127.0.0.1:0")
	if e != nil {
		return "", "", "configuration_unavailable"
	}
	port := l.Addr().(*net.TCPAddr).Port
	l.Close()
	out := model.NodeSpecFromPanel(&panel.NodeConfig{CustomOutbounds: []panel.OutboundConfig{p.Outbound}}).CustomOutbounds[0]
	closeClient, e := launch(p.Kernel, port, out)
	if e != nil {
		return "", "", "configuration_unavailable"
	}
	defer closeClient()
	d, e := proxy.SOCKS5("tcp", fmt.Sprintf("127.0.0.1:%d", port), nil, &net.Dialer{})
	if e != nil {
		return "", "", "configuration_unavailable"
	}
	cd, ok := d.(proxy.ContextDialer)
	if !ok {
		return "", "", "configuration_unavailable"
	}
	transport := &http.Transport{DialContext: cd.DialContext, TLSClientConfig: tlsConfig, DisableKeepAlives: true, MaxResponseHeaderBytes: 8192}
	defer transport.CloseIdleConnections()
	client := &http.Client{Transport: transport, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}
	req, e := http.NewRequestWithContext(ctx, "GET", Target, nil)
	if e != nil {
		return "", "", "configuration_unavailable"
	}
	resp, e := client.Do(req)
	if e != nil {
		if ctx.Err() != nil {
			return "", "", "timeout"
		}
		return "", "", "proxy_failed"
	}
	defer resp.Body.Close()
	body, e := io.ReadAll(io.LimitReader(resp.Body, 8193))
	if e != nil || len(body) > 8192 || resp.StatusCode != 200 {
		return "", "", "target_failed"
	}
	ip, country, ok := parseTrace(string(body))
	if !ok {
		return "", "", "target_failed"
	}
	return ip, country, ""
}
func parseTrace(body string) (string, string, bool) {
	if len(body) > 8192 {
		return "", "", false
	}
	ip, country := "", ""
	for _, line := range strings.Split(body, "\n") {
		key, value, found := strings.Cut(line, "=")
		if !found {
			continue
		}
		if key == "ip" {
			if ip != "" {
				return "", "", false
			}
			ip = value
		}
		if key == "loc" {
			if country != "" {
				return "", "", false
			}
			country = value
		}
	}
	if net.ParseIP(ip) == nil || len(country) != 2 || country[0] < 'A' || country[0] > 'Z' || country[1] < 'A' || country[1] > 'Z' {
		return "", "", false
	}
	return ip, country, true
}
