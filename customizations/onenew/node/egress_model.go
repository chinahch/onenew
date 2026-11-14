package model

import "github.com/cedar2025/xboard-node/internal/config"

// PanelIPFamily returns a private config copy; each logical node shares the
// machine's local config, but must never share a mutable preference map.
// Empty is the backwards-compatible local YAML setting; default clears it.
func PanelIPFamily(local config.KernelConfig, node *NodeSpec) config.KernelConfig {
	if node == nil || node.IPFamily == "" {
		return local
	}
	mode := node.IPFamily
	if mode == "default" {
		mode = ""
	}
	local.IPFamily = map[string]string{"xray": mode, "singbox": mode}
	return local
}
