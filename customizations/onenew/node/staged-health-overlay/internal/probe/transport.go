package probe

import (
	"context"
	"crypto/tls"
	"crypto/x509"
	"errors"
	"fmt"
	"net"
	"runtime"
	"syscall"
	"time"
)

// A SOCKS CONNECT error can originate at the target. Never blame a saved proxy
// from that ambiguous error alone. Only a refused/unreachable configured TCP
// endpoint on a fresh bounded connection is independent fault evidence.
func requestError(ctx context.Context, p Payload, err error) string {
	if ctx.Err() != nil {
		return "timeout"
	}
	var cert *tls.CertificateVerificationError
	var unknown x509.UnknownAuthorityError
	var host x509.HostnameError
	var invalid x509.CertificateInvalidError
	var record tls.RecordHeaderError
	if errors.As(err, &cert) || errors.As(err, &unknown) || errors.As(err, &host) || errors.As(err, &invalid) || errors.As(err, &record) {
		return "target_failed"
	}
	if p.Outbound.Protocol == "hysteria2" || p.Outbound.Protocol == "tuic" {
		return "transport_failed"
	}
	settings := p.Outbound.Settings
	network, _ := p.Outbound.StreamSettings["network"].(string)
	if transport, ok := settings["transport"].(map[string]any); ok {
		network, _ = transport["type"].(string)
	}
	if transport, ok := settings["network"].(string); ok && transport == "udp" {
		network = "udp"
	}
	switch network {
	case "", "tcp", "raw", "ws", "grpc", "http", "httpupgrade", "xhttp", "splithttp":
	default:
		return "transport_failed"
	}

	hostName, _ := settings["server"].(string)
	port := settings["server_port"]
	if hostName == "" {
		key := "vnext"
		if p.Outbound.Protocol == "socks" || p.Outbound.Protocol == "trojan" || p.Outbound.Protocol == "shadowsocks" {
			key = "servers"
		}
		if entries, ok := settings[key].([]any); ok && len(entries) == 1 {
			if server, ok := entries[0].(map[string]any); ok {
				hostName, _ = server["address"].(string)
				port = server["port"]
			}
		}
	}
	if hostName == "" || port == nil {
		return "transport_failed"
	}
	job, cancel := context.WithTimeout(ctx, time.Second)
	defer cancel()
	conn, e := (&net.Dialer{}).DialContext(job, "tcp", net.JoinHostPort(hostName, fmt.Sprint(port)))
	if e == nil {
		conn.Close()
		return "transport_failed"
	}
	var errno syscall.Errno
	windowsRefused := runtime.GOOS == "windows" && errors.As(e, &errno) && (errno == 10061 || errno == 10051 || errno == 10065)
	if windowsRefused || errors.Is(e, syscall.ECONNREFUSED) || errors.Is(e, syscall.ENETUNREACH) || errors.Is(e, syscall.EHOSTUNREACH) {
		return "proxy_failed"
	}
	return "transport_failed"
}
