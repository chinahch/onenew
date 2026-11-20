package xray

import (
	"net"
	"strconv"
	"testing"

	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/kernel/singbox"
	"github.com/cedar2025/xboard-node/internal/model"
)

// Exercise the actual two kernels used by machine-mode XHTTP/HY2 nodes.
// No sleeps or retries: Stop must release the socket before returning.
func TestOnenewXHTTPHysteria2SamePortSwitch(t *testing.T) {
	for _, alpn := range []string{"h2", "h3"} {
		t.Run(alpn, func(t *testing.T) {
			reservation, err := net.ListenPacket("udp", "127.0.0.1:0")
			if err != nil {
				t.Fatal(err)
			}
			port := reservation.LocalAddr().(*net.UDPAddr).Port
			reservation.Close()
			users := []model.UserSpec{{ID: 1, UUID: "00000000-0000-4000-8000-000000000001"}}
			cert := lumaTestCert(t)
			x := New(config.KernelConfig{Type: "xray", LogLevel: "error", ConfigDir: t.TempDir()})
			h := singbox.New(config.KernelConfig{Type: "singbox", LogLevel: "error", ConfigDir: t.TempDir()})
			defer x.Stop()
			defer h.Stop()
			xhttp := &model.NodeSpec{Protocol: "vless", Network: "xhttp", ListenIP: "127.0.0.1", ServerPort: port, TLS: 1,
				NetworkSettings: map[string]any{"path": "/lifecycle", "mode": "auto", "alpn": alpn}}
			hy2 := &model.NodeSpec{Protocol: "hysteria", Version: 2, ListenIP: "127.0.0.1", ServerPort: port}

			for cycle := 0; cycle < 3; cycle++ {
				if err := x.Start(xhttp, users, cert); err != nil {
					t.Fatalf("cycle %d start XHTTP: %v", cycle, err)
				}
				x.Stop()
				if err := h.Start(hy2, users, cert); err != nil {
					t.Fatalf("cycle %d XHTTP -> HY2 left old listener bound: %v", cycle, err)
				}
				h.Stop()
			}
			// Verify both transports are available after the final explicit stop.
			udp, err := net.ListenPacket("udp", "127.0.0.1:"+strconv.Itoa(port))
			if err != nil {
				t.Fatal(err)
			}
			udp.Close()
			tcp, err := net.Listen("tcp", "127.0.0.1:"+strconv.Itoa(port))
			if err != nil {
				t.Fatal(err)
			}
			tcp.Close()
		})
	}
}
