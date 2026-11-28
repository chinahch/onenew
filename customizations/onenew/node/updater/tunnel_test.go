package main

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"
)

func TestTunnelInstallAndUninstallLifecycle(t *testing.T) {
	bin := t.TempDir()
	root := t.TempDir()
	var reports []map[string]any
	var cmds [][]string
	active := false
	installed := false

	binSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Write([]byte("fake-cloudflared-elf"))
	}))
	defer binSrv.Close()

	panelSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasSuffix(r.URL.Path, "/status") {
			var body map[string]any
			_ = json.NewDecoder(r.Body).Decode(&body)
			reports = append(reports, body)
		}
		w.Write([]byte(`{"ok":true}`))
	}))
	defer panelSrv.Close()

	u := &Updater{
		Root:            root,
		Bin:             bin,
		Manager:         "systemd",
		Binding:         Binding{Panel: panelSrv.URL, Machine: 7, Token: "machine-tok"},
		Client:          panelSrv.Client(),
		Download:        binSrv.Client(),
		CloudflaredBase: binSrv.URL + "/",
		Now:             time.Now,
		Sleep:           func(time.Duration) {},
		Command: func(ctx context.Context, name string, args ...string) ([]byte, error) {
			cmds = append(cmds, append([]string{name}, args...))
			if name == filepath.Join(bin, "cloudflared") && len(args) > 0 && args[0] == "--version" {
				if !installed {
					return nil, os.ErrNotExist
				}
				return []byte("cloudflared version 2026.2.0 (built 2026-02-01)"), nil
			}
			if name == filepath.Join(bin, "cloudflared") && len(args) >= 2 && args[0] == "service" && args[1] == "install" {
				installed = true
				return []byte("ok"), nil
			}
			if name == "systemctl" && len(args) >= 2 && args[0] == "restart" {
				active = true
				return []byte("ok"), nil
			}
			if name == "systemctl" && len(args) >= 2 && args[0] == "is-active" {
				if active {
					return []byte("active"), nil
				}
				return nil, os.ErrNotExist
			}
			if name == "systemctl" && len(args) >= 2 && args[0] == "disable" {
				active = false
				return []byte("ok"), nil
			}
			return []byte("ok"), nil
		},
	}

	job := TunnelJob{
		ID:     "11111111-2222-3333-4444-555555555555",
		Lease:  strings.Repeat("a", 64),
		Action: "install",
		Token:  "eyJhIjoi" + strings.Repeat("AbCdEf0123456789", 4) + "==",
	}
	if err := u.executeTunnel(job); err != nil {
		t.Fatalf("install failed: %v", err)
	}
	if len(reports) != 2 || reports[1]["state"] != "succeeded" || reports[1]["running"] != true || reports[1]["version"] != "2026.2.0" {
		t.Fatalf("unexpected reports: %+v", reports)
	}
	if _, err := os.Stat(filepath.Join(bin, "cloudflared")); err != nil {
		t.Fatalf("cloudflared binary not installed: %v", err)
	}

	// Uninstall
	installed = false
	job.Action = "uninstall"
	job.Token = ""
	if err := u.executeTunnel(job); err != nil {
		t.Fatalf("uninstall failed: %v", err)
	}
	if _, err := os.Stat(filepath.Join(bin, "cloudflared")); !os.IsNotExist(err) {
		t.Fatalf("cloudflared binary should be removed")
	}
}
