package singbox

import (
	"bytes"
	"encoding/json"
	"fmt"
	"github.com/cedar2025/xboard-node/internal/kernel"
	"github.com/cedar2025/xboard-node/internal/model"
	"github.com/sagernet/sing-box/adapter"
	singLog "github.com/sagernet/sing-box/log"
	"github.com/sagernet/sing-box/option"
	"github.com/sagernet/sing/service"
	"io"
)

func sameHotApplyValue(a, b any) bool {
	x, ex := json.Marshal(a)
	y, ey := json.Marshal(b)
	return ex == nil && ey == nil && bytes.Equal(x, y)
}

// Reconcile outbound definitions before publishing rules that reference them.
// Unchanged outbounds are retained, avoiding unnecessary session disruption.
func (s *SingBox) hotApplyOutbounds(router adapter.Router, opts option.Options, node *model.NodeSpec, users []model.UserSpec, tls kernel.TLSCert) error {
	manager := service.FromContext[adapter.OutboundManager](s.ctx)
	if manager == nil {
		return fmt.Errorf("outbound manager not available")
	}
	old := buildConfig(s.cfg, s.nodeConfig, s.users, s.tls)
	oldByTag := map[string]any{}
	if outbounds, ok := old["outbounds"].([]M); ok {
		for _, out := range outbounds {
			tag, _ := out["tag"].(string)
			oldByTag[tag] = out
		}
	}
	current := buildConfig(s.cfg, node, users, tls)
	newByTag := map[string]any{}
	if outbounds, ok := current["outbounds"].([]M); ok {
		for _, out := range outbounds {
			tag, _ := out["tag"].(string)
			newByTag[tag] = out
		}
	}
	factory := singLog.NewNOPFactory()
	for _, out := range opts.Outbounds {
		if sameHotApplyValue(oldByTag[out.Tag], newByTag[out.Tag]) {
			continue
		}
		if err := manager.Create(s.ctx, router, factory.NewLogger("outbound/"+out.Tag), out.Tag, out.Type, out.Options); err != nil {
			return fmt.Errorf("reload outbound %s: %w", out.Tag, err)
		}
	}
	return nil
}

func (s *SingBox) hotApplyInboundChanged(node *model.NodeSpec, users []model.UserSpec, tls kernel.TLSCert) bool {
	old := buildConfig(s.cfg, s.nodeConfig, users, s.tls)
	next := buildConfig(s.cfg, node, users, tls)
	return !sameHotApplyValue(old["inbounds"], next["inbounds"])
}

// Remove listeners whose protocol-specific tags disappear during a protocol
// switch before creating the replacement listener on the same port.
func (s *SingBox) hotApplyRemoveStaleInbounds(manager adapter.InboundManager, node *model.NodeSpec, users []model.UserSpec, tls kernel.TLSCert) error {
	old := buildConfig(s.cfg, s.nodeConfig, s.users, s.tls)
	next := buildConfig(s.cfg, node, users, tls)

	nextTags := make(map[string]struct{})
	if inbounds, ok := next["inbounds"].([]M); ok {
		for _, inbound := range inbounds {
			if tag, _ := inbound["tag"].(string); tag != "" {
				nextTags[tag] = struct{}{}
			}
		}
	}

	if inbounds, ok := old["inbounds"].([]M); ok {
		for _, inbound := range inbounds {
			tag, _ := inbound["tag"].(string)
			if tag == "" {
				continue
			}
			if _, retained := nextTags[tag]; retained {
				continue
			}
			if _, exists := manager.Get(tag); !exists {
				continue
			}
			if err := manager.Remove(tag); err != nil {
				if _, stillExists := manager.Get(tag); stillExists {
					return fmt.Errorf("remove stale inbound %s: %w", tag, err)
				}
			}
		}
	}
	return nil
}

// Close only this logical node's tracked TCP and UDP sessions after an exit
// policy change, so multiplexed clients cannot continue using the old exit.
func (s *SingBox) hotApplyCloseOldExit(node *model.NodeSpec, users []model.UserSpec, tls kernel.TLSCert) {
	old := buildConfig(s.cfg, s.nodeConfig, users, s.tls)
	next := buildConfig(s.cfg, node, users, tls)
	if sameHotApplyValue(old["outbounds"], next["outbounds"]) && sameHotApplyValue(old["route"], next["route"]) {
		return
	}
	if s.connTracker == nil {
		return
	}
	s.connTracker.usersMu.RLock()
	connections := make([]io.Closer, 0, len(s.connTracker.connMap))
	for _, conn := range s.connTracker.connMap {
		connections = append(connections, conn)
	}
	s.connTracker.usersMu.RUnlock()
	for _, conn := range connections {
		_ = conn.Close()
	}
}
