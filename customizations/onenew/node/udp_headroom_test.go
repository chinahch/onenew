package singbox

import (
	"bytes"
	"context"
	"encoding/binary"
	"io"
	"net"
	"testing"
	"time"

	"github.com/cedar2025/xboard-node/internal/limiter"
	vmess "github.com/sagernet/sing-vmess"
	"github.com/sagernet/sing/common"
	"github.com/sagernet/sing/common/buf"
	"github.com/sagernet/sing/common/bufio"
	singM "github.com/sagernet/sing/common/metadata"
	N "github.com/sagernet/sing/common/network"
)

type headroomPackets struct {
	payload     []byte
	destination singM.Socksaddr
	remaining   int
}

func (r *headroomPackets) ReadPacket(b *buf.Buffer) (singM.Socksaddr, error) {
	if r.remaining == 0 {
		return singM.Socksaddr{}, io.EOF
	}
	r.remaining--
	_, err := b.Write(r.payload)
	return r.destination, err
}

type headroomPacketWriter struct {
	N.PacketConn
	front, rear int
	payload     []byte
	destination singM.Socksaddr
	writes      int
}

func (w *headroomPacketWriter) FrontHeadroom() int { return w.front }
func (w *headroomPacketWriter) RearHeadroom() int  { return w.rear }
func (w *headroomPacketWriter) WritePacket(b *buf.Buffer, d singM.Socksaddr) error {
	w.payload = append([]byte(nil), b.Bytes()...)
	w.destination = d
	w.writes++
	b.ExtendHeader(w.front)
	b.Extend(w.rear)
	b.Release()
	return nil
}

// Before the fix, the bandwidth unwrap adapter erased FrontHeadroom and this
// real pooled copy panicked in ExtendHeader with start=0, need=16.
func TestTrackedUDPPooledCopyPreservesHeadroom(t *testing.T) {
	for _, address := range []string{"192.0.2.1:53", "[2001:db8::1]:53"} {
		t.Run(address, func(t *testing.T) {
			payload := bytes.Repeat([]byte("packet-integrity"), 100)
			writer := &headroomPacketWriter{front: 16, rear: 32}
			stats := &userStats{}
			tracked := &trackedPacketConn{PacketConn: writer, tracker: &ConnTracker{nodeBandwidth: limiter.NewNodeBandwidth()}, us: stats, ctx: context.Background()}
			destination, counters := N.UnwrapCountPacketWriter(tracked, nil)
			if len(counters) != 0 {
				t.Fatal("bandwidth adapter would double-count packets")
			}
			reader := &headroomPackets{payload, singM.ParseSocksaddr(address), 1}
			n, err := bufio.CopyPacketWithPool(reader, destination, reader, nil, counters, false)
			if err != io.EOF || n != int64(len(payload)) {
				t.Fatalf("copy=%d,%v", n, err)
			}
			if !bytes.Equal(writer.payload, payload) || writer.destination != reader.destination || writer.writes != 1 {
				t.Fatal("payload or destination changed")
			}
			if stats.download.Load() != int64(len(payload)) || stats.upload.Load() != 0 {
				t.Fatal("packet counters changed")
			}
		})
	}
}

func TestTrackedUDPPooledCopyKeepsAggregateBandwidth(t *testing.T) {
	buckets := limiter.NewNodeBandwidth()
	buckets.SetRate(1)
	payload := bytes.Repeat([]byte{0x5a}, 8192)
	start := time.Now()
	for _, address := range []string{"192.0.2.1:53", "[2001:db8::1]:53"} {
		writer := &headroomPacketWriter{front: 16}
		tracked := &trackedPacketConn{PacketConn: writer, tracker: &ConnTracker{nodeBandwidth: buckets}, us: &userStats{}, ctx: context.Background()}
		reader := &headroomPackets{payload, singM.ParseSocksaddr(address), 8}
		if n, err := bufio.CopyPacket(tracked, reader); err != io.EOF || n != 65536 {
			t.Fatalf("copy=%d,%v", n, err)
		}
	}
	if elapsed := time.Since(start); elapsed < 450*time.Millisecond {
		t.Fatalf("aggregate UDP limit bypassed: %v", elapsed)
	}
}

type layeredHeadroomWriter struct {
	N.PacketConn
	upstream any
	lazy     bool
}

func (w *layeredHeadroomWriter) FrontHeadroom() int  { return 7 }
func (w *layeredHeadroomWriter) RearHeadroom() int   { return 11 }
func (w *layeredHeadroomWriter) WriterMTU() int      { return 1400 }
func (w *layeredHeadroomWriter) UpstreamWriter() any { return w.upstream }
func (w *layeredHeadroomWriter) LazyHeadroom() bool  { return w.lazy }

func TestTrackedUDPWriterKeepsMetadataWithoutUnwrapBypass(t *testing.T) {
	for _, lazy := range []bool{false, true} {
		underlying := &layeredHeadroomWriter{upstream: &headroomPacketWriter{front: 16, rear: 32}, lazy: lazy}
		tracked := &trackedPacketConn{PacketConn: underlying, tracker: &ConnTracker{nodeBandwidth: limiter.NewNodeBandwidth()}}
		writer, counters := N.UnwrapCountPacketWriter(tracked, nil)
		if len(counters) != 0 {
			t.Fatal("additional counters bypass tracker accounting")
		}
		if _, ok := writer.(common.WithUpstream); ok {
			t.Fatal("upstream exposes limiter bypass")
		}
		if _, ok := writer.(N.WithUpstreamWriter); ok {
			t.Fatal("writer upstream exposes limiter bypass")
		}
		if _, ok := writer.(N.PacketWriteCounter); ok {
			t.Fatal("copy can recursively unwrap tracker")
		}
		if got, want := N.NewReadWaitOptions(nil, writer), N.NewReadWaitOptions(nil, underlying); got != want {
			t.Fatalf("metadata: got %+v want %+v", got, want)
		}
	}
}

type muxHeadroomHandler struct{ packets chan N.PacketConn }

func (h *muxHeadroomHandler) NewConnectionEx(_ context.Context, c net.Conn, _, _ singM.Socksaddr, _ N.CloseHandlerFunc) {
	c.Close()
}
func (h *muxHeadroomHandler) NewPacketConnectionEx(_ context.Context, c N.PacketConn, _, _ singM.Socksaddr, _ N.CloseHandlerFunc) {
	h.packets <- c
}

// Obtain the private serverMuxPacketConn through the public VMess mux handler,
// then exercise its actual WritePacket/ExtendHeader and serialized wire frame.
func TestTrackedUDPRealVMessMux(t *testing.T) {
	for _, address := range []string{"192.0.2.1:53", "[2001:db8::1]:53"} {
		t.Run(address, func(t *testing.T) {
			server, client := net.Pipe()
			defer server.Close()
			defer client.Close()
			client.SetDeadline(time.Now().Add(5 * time.Second))
			handler := &muxHeadroomHandler{make(chan N.PacketConn, 1)}
			muxDone := make(chan error, 1)
			go func() { muxDone <- vmess.HandleMuxConnection(context.Background(), server, singM.Socksaddr{}, handler) }()
			dest := singM.ParseSocksaddr(address)
			frame := new(bytes.Buffer)
			binary.Write(frame, binary.BigEndian, uint16(5+vmess.AddressSerializer.AddrPortLen(dest)))
			binary.Write(frame, binary.BigEndian, uint16(1))
			frame.Write([]byte{vmess.StatusNew, 0, vmess.NetworkUDP})
			if err := vmess.AddressSerializer.WriteAddrPort(frame, dest); err != nil {
				t.Fatal(err)
			}
			if _, err := client.Write(frame.Bytes()); err != nil {
				t.Fatal(err)
			}
			var packet N.PacketConn
			select {
			case packet = <-handler.packets:
			case <-time.After(5 * time.Second):
				t.Fatal("mux did not dispatch UDP connection")
			}
			payload := []byte("real vmess mux UDP payload")
			want := new(bytes.Buffer)
			binary.Write(want, binary.BigEndian, uint16(5+vmess.AddressSerializer.AddrPortLen(dest)))
			binary.Write(want, binary.BigEndian, uint16(1))
			want.Write([]byte{vmess.StatusKeep, vmess.OptionData, vmess.NetworkUDP})
			vmess.AddressSerializer.WriteAddrPort(want, dest)
			binary.Write(want, binary.BigEndian, uint16(len(payload)))
			want.Write(payload)
			wire := make([]byte, want.Len())
			readDone := make(chan error, 1)
			go func() { _, err := io.ReadFull(client, wire); readDone <- err }()
			stats := &userStats{}
			tracked := &trackedPacketConn{PacketConn: packet, tracker: &ConnTracker{nodeBandwidth: limiter.NewNodeBandwidth()}, us: stats, ctx: context.Background()}
			writer, counters := N.UnwrapCountPacketWriter(tracked, nil)
			reader := &headroomPackets{payload, dest, 1}
			if n, err := bufio.CopyPacketWithPool(reader, writer, reader, nil, counters, false); n != int64(len(payload)) || err != io.EOF {
				t.Fatalf("copy=%d,%v", n, err)
			}
			if err := <-readDone; err != nil {
				t.Fatal(err)
			}
			if !bytes.Equal(wire, want.Bytes()) {
				t.Fatalf("mux frame changed: %x want %x", wire, want.Bytes())
			}
			if stats.download.Load() != int64(len(payload)) || stats.upload.Load() != 0 {
				t.Fatal("mux counters changed")
			}
			client.Close()
			server.Close()
			select {
			case <-muxDone:
			case <-time.After(5 * time.Second):
				t.Fatal("mux did not close")
			}
		})
	}
}
