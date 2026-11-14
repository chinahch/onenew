package exitprobe

import (
	"context"
	"crypto/tls"
	"encoding/binary"
	"github.com/cedar2025/xboard-node/internal/model"
	"github.com/cedar2025/xboard-node/internal/probe"
	"io"
	"net"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func TestTraceValidation(t *testing.T) {
	for _, body := range []string{"ip=bad\nloc=US\n", "ip=1.2.3.4\nloc=USA\n", "ip=1.2.3.4\nloc=us\n", "ip=1.2.3.4\nloc=US\nip=8.8.8.8\n", strings.Repeat("x", 8193)} {
		if _, _, ok := parseTrace(body); ok {
			t.Fatal("accepted invalid trace")
		}
	}
	for _, ip := range []string{"203.0.113.7", "2001:db8::7"} {
		got, country, ok := parseTrace("fl=ignored\nip=" + ip + "\nloc=JP\n")
		if !ok || got != ip || country != "JP" {
			t.Fatal(got, country, ok)
		}
	}
}

// The trace HTTP request must traverse the SOCKS proxy even when env proxies exist.
func TestTraceRoundtripUsesSOCKSAndBoundsBody(t *testing.T) {
	for _, body := range []string{"ip=203.0.113.9\nloc=JP\n", strings.Repeat("x", 8193)} {
		target := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			if r.URL.Path != "/cdn-cgi/trace" {
				t.Error(r.URL.Path)
			}
			io.WriteString(w, body)
		}))
		var connections atomic.Int32
		launch := func(_ string, port int, _ model.OutboundConfig) (func(), error) {
			listener, e := net.Listen("tcp", net.JoinHostPort("127.0.0.1", fmtPort(port)))
			if e != nil {
				return nil, e
			}
			go func() {
				conn, e := listener.Accept()
				if e != nil {
					return
				}
				defer conn.Close()
				connections.Add(1)
				conn.SetDeadline(time.Now().Add(3 * time.Second))
				greeting := make([]byte, 3)
				if _, e = io.ReadFull(conn, greeting); e != nil {
					return
				}
				conn.Write([]byte{5, 0})
				header := make([]byte, 5)
				if _, e = io.ReadFull(conn, header); e != nil {
					return
				}
				name := make([]byte, int(header[4])+2)
				if _, e = io.ReadFull(conn, name); e != nil {
					return
				}
				if string(name[:len(name)-2]) != "www.cloudflare.com" || binary.BigEndian.Uint16(name[len(name)-2:]) != 443 {
					return
				}
				upstream, e := net.Dial("tcp", strings.TrimPrefix(target.URL, "https://"))
				if e != nil {
					return
				}
				defer upstream.Close()
				conn.Write([]byte{5, 0, 0, 1, 127, 0, 0, 1, 0, 0})
				go io.Copy(upstream, conn)
				io.Copy(conn, upstream)
			}()
			return func() { listener.Close() }, nil
		}
		ctx, cancel := context.WithTimeout(context.Background(), 3*time.Second)
		ip, country, code := execute(ctx, probe.Payload{}, &tls.Config{InsecureSkipVerify: true}, launch)
		cancel()
		target.Close()
		if connections.Load() != 1 {
			t.Fatal("request did not use SOCKS")
		}
		if len(body) > 8192 {
			if code != "target_failed" {
				t.Fatal(code)
			}
		} else if code != "" || ip != "203.0.113.9" || country != "JP" {
			t.Fatal(ip, country, code)
		}
	}
}
func fmtPort(port int) string {
	var b [5]byte
	i := len(b)
	for {
		i--
		b[i] = byte('0' + port%10)
		port /= 10
		if port == 0 {
			return string(b[i:])
		}
	}
}

func TestArbitraryTargetAndDirectRefused(t *testing.T) {
	task := probe.Task{ID: "12345678-1234-4234-8234-123456789abc", Lease: "lease", Expires: time.Now().Add(time.Minute), Payload: probe.Payload{Action: "exit_trace", Kernel: "singbox", Target: "http://127.0.0.1"}}
	if Run(context.Background(), task).Error != "configuration_unavailable" {
		t.Fatal("arbitrary target accepted")
	}
	task.Payload.Target = Target
	task.Payload.Outbound.Protocol = "direct"
	if Run(context.Background(), task).Error != "unsupported" {
		t.Fatal("direct accepted")
	}
}
