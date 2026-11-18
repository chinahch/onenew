package singbox

import N "github.com/sagernet/sing/common/network"

// Keep packet writes inside trackedPacketConn for limiting and accounting.
// Expose allocation requirements without exposing an upstream that sing's
// copy helpers could unwrap past the tracker.
type bandwidthPacketWriter struct {
	N.PacketWriter
	frontHeadroom, rearHeadroom, mtu int
}

func newBandwidthPacketWriter(c *trackedPacketConn) N.PacketWriter {
	return &bandwidthPacketWriter{
		PacketWriter:  c,
		frontHeadroom: N.CalculateFrontHeadroom(c.PacketConn),
		rearHeadroom:  N.CalculateRearHeadroom(c.PacketConn),
		mtu:           N.CalculateMTU(nil, c.PacketConn),
	}
}

func (w *bandwidthPacketWriter) FrontHeadroom() int { return w.frontHeadroom }
func (w *bandwidthPacketWriter) RearHeadroom() int  { return w.rearHeadroom }
func (w *bandwidthPacketWriter) WriterMTU() int     { return w.mtu }
