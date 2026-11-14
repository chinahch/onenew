package singbox

import (
	"bufio"
	"context"
	"fmt"
	"github.com/cedar2025/xboard-node/internal/config"
	"github.com/cedar2025/xboard-node/internal/kernel"
	"github.com/cedar2025/xboard-node/internal/model"
	"github.com/sagernet/sing-box/adapter"
	singBufio "github.com/sagernet/sing/common/bufio"
	"github.com/sagernet/sing/service"
	"golang.org/x/net/proxy"
	"io"
	"net"
	"net/http"
	"testing"
	"time"
)

// Each real HTTP CONNECT exit marks its connection and echoes subsequent data.
func hotExit(t *testing.T, marker byte) int {
	t.Helper()
	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	t.Cleanup(func() { l.Close() })
	go func() {
		for {
			c, e := l.Accept()
			if e != nil {
				return
			}
			go func() {
				defer c.Close()
				c.SetDeadline(time.Now().Add(15 * time.Second))
				r := bufio.NewReader(c)
				if _, e := http.ReadRequest(r); e != nil {
					return
				}
				fmt.Fprint(c, "HTTP/1.1 200 OK\r\n\r\n")
				c.Write([]byte{marker})
				io.Copy(c, r)
			}()
		}
	}()
	return l.Addr().(*net.TCPAddr).Port
}

func TestOnenewHotApplyClosesOldExitOnlyOnChangedNode(t *testing.T) {
	a, b := hotExit(t, 'A'), hotExit(t, 'B')
	users := []model.UserSpec{{ID: 1, UUID: "00000000-0000-4000-8000-000000000001"}}
	makeNode := func(port int) *model.NodeSpec {
		return &model.NodeSpec{Protocol: "socks", ListenIP: "127.0.0.1", ServerPort: lumaFreePort(t), CustomOutbounds: []model.OutboundConfig{{Tag: "exit", Protocol: "http", Settings: map[string]any{"server": "127.0.0.1", "server_port": port}}}, CustomRoutes: []map[string]any{{"ip_cidr": []string{"127.0.0.0/8"}, "action": "route", "outbound": "exit"}}}
	}
	first := makeNode(a)
	s := New(config.KernelConfig{Type: "singbox", LogLevel: "error", ConfigDir: t.TempDir()})
	if err := s.Start(first, users, kernel.TLSCert{}); err != nil {
		t.Fatal(err)
	}
	defer s.Stop()
	dial := func(port int) net.Conn {
		t.Helper()
		d, e := proxy.SOCKS5("tcp", fmt.Sprintf("127.0.0.1:%d", port), &proxy.Auth{User: users[0].UUID, Password: users[0].UUID}, &net.Dialer{Timeout: 3 * time.Second})
		if e != nil {
			t.Fatal(e)
		}
		c, e := d.Dial("tcp", "127.0.0.1:9999")
		if e != nil {
			t.Fatal(e)
		}
		c.SetDeadline(time.Now().Add(5 * time.Second))
		t.Cleanup(func() { c.Close() })
		return c
	}
	read := func(c net.Conn, want byte) {
		t.Helper()
		raw := make([]byte, 1)
		if _, e := io.ReadFull(c, raw); e != nil {
			t.Fatal(e)
		}
		if raw[0] != want {
			t.Fatalf("new traffic used stale outbound: got %q want %q", raw[0], want)
		}
	}
	other := makeNode(a)
	sibling := New(config.KernelConfig{Type: "singbox", LogLevel: "error", ConfigDir: t.TempDir()})
	if err := sibling.Start(other, users, kernel.TLSCert{}); err != nil {
		t.Fatal(err)
	}
	defer sibling.Stop()
	untouched := dial(other.ServerPort)
	read(untouched, 'A')
	old := dial(first.ServerPort)
	read(old, 'A')
	oldBox, oldTracker := s.box, s.connTracker
	inboundManager := service.FromContext[adapter.InboundManager](s.ctx)
	oldInbound, _ := inboundManager.Get("socks-in")
	second := makeNode(b)
	second.ServerPort = first.ServerPort
	if err := s.Reload(second, users, kernel.TLSCert{}); err != nil {
		t.Fatal(err)
	}
	current := dial(first.ServerPort)
	read(current, 'B')
	// An identical update keeps established traffic alive.
	if err := s.Reload(second, users, kernel.TLSCert{}); err != nil {
		t.Fatal(err)
	}
	if _, e := current.Write([]byte{'X'}); e != nil {
		t.Fatal(e)
	}
	read(current, 'X')
	if s.box != oldBox || s.connTracker != oldTracker {
		t.Fatal("outbound edit restarted box/tracker")
	}
	if inbound, _ := inboundManager.Get("socks-in"); inbound != oldInbound {
		t.Fatal("outbound edit replaced unchanged inbound")
	}
	raw := make([]byte, 1)
	if _, e := old.Read(raw); e == nil {
		t.Fatal("old exit connection survived outbound change")
	} else if ne, ok := e.(net.Error); ok && ne.Timeout() {
		t.Fatal("old exit connection was not closed")
	}
	if _, e := untouched.Write([]byte{'Z'}); e != nil {
		t.Fatal(e)
	}
	read(untouched, 'Z')
	// A route-only edit must also take effect without restarting the box.
	third := *second
	third.CustomRoutes = []map[string]any{{"ip_cidr": []string{"127.0.0.0/8"}, "action": "reject"}}
	if err := s.Reload(&third, users, kernel.TLSCert{}); err != nil {
		t.Fatal(err)
	}
	if _, e := current.Read(raw); e == nil {
		t.Fatal("old exit connection survived route change")
	} else if ne, ok := e.(net.Error); ok && ne.Timeout() {
		t.Fatal("route change did not close active connection")
	}
}

func TestOnenewExitChangeClosesTrackedUDP(t *testing.T) {
	conn, err := net.ListenPacket("udp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	defer conn.Close()
	tracker := NewConnTracker(0)
	tracker.SetUserMap(map[string]int{"uuid-1": 1})
	wrapped := tracker.RoutedPacketConnection(context.Background(), singBufio.NewPacketConn(conn), testInboundContext("uuid-1", "127.0.0.1"), nil, nil)
	defer wrapped.Close()
	old := &model.NodeSpec{Protocol: "socks", ServerPort: 12345}
	next := *old
	next.CustomRoutes = []map[string]any{{"domain_suffix": []string{"example.com"}, "action": "reject"}}
	s := New(config.KernelConfig{Type: "singbox"})
	s.nodeConfig = old
	s.connTracker = tracker
	s.hotApplyCloseOldExit(&next, nil, kernel.TLSCert{})
	if tracker.ActiveCount() != 0 {
		t.Fatal("UDP session counters remained active")
	}
	if _, err := conn.WriteTo([]byte("stale exit"), conn.LocalAddr()); err == nil {
		t.Fatal("UDP socket survived exit policy change")
	}
}
