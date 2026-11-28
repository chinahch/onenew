package main

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestRenderDisguiseCaddyfile(t *testing.T) {
	cfg, err := renderDisguiseCaddyfile("Blog.Example.COM", 8443, "/etc/onenew-disguise/site")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if !strings.Contains(cfg, "http://blog.example.com:80") || !strings.Contains(cfg, "https://blog.example.com:8443") || !strings.Contains(cfg, "bind 127.0.0.1") {
		t.Fatalf("unexpected caddyfile:\n%s", cfg)
	}
	cfg443, err := renderDisguiseCaddyfile("blog.example.com", 443, "/etc/onenew-disguise/site")
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if strings.Contains(cfg443, "bind 127.0.0.1") || !strings.Contains(cfg443, "blog.example.com {") {
		t.Fatalf("unexpected 443 caddyfile:\n%s", cfg443)
	}
	if _, err := renderDisguiseCaddyfile("bad domain!", 8443, "/tmp"); err == nil {
		t.Fatal("expected invalid domain error")
	}
	if _, err := renderDisguiseCaddyfile("blog.example.com", 80, "/tmp"); err == nil {
		t.Fatal("expected port 80 rejection")
	}
}

func TestExecuteSelfStealInstallAndUninstall(t *testing.T) {
	// Build a minimal tar.gz containing a fake "caddy" binary
	var buf bytes.Buffer
	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)
	payload := []byte("#!/bin/sh\necho v2.9.1\n")
	if err := tw.WriteHeader(&tar.Header{Name: "caddy", Mode: 0755, Size: int64(len(payload)), Typeflag: tar.TypeReg}); err != nil {
		t.Fatal(err)
	}
	if _, err := tw.Write(payload); err != nil {
		t.Fatal(err)
	}
	tw.Close()
	gz.Close()

	dlSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(200)
		_, _ = w.Write(buf.Bytes())
	}))
	defer dlSrv.Close()

	var states []string
	apiSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)
		if s, ok := body["state"].(string); ok {
			states = append(states, s)
		}
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"ok":true}`))
	}))
	defer apiSrv.Close()

	tmp := t.TempDir()
	binDir := filepath.Join(tmp, "bin")
	_ = os.MkdirAll(binDir, 0755)
	disguiseDir := filepath.Join(tmp, "etc-disguise")
	unitPath := filepath.Join(tmp, "onenew-disguise.service")

	u := &Updater{
		Root:         tmp,
		Bin:          binDir,
		Manager:      "systemd",
		Binding:      Binding{Panel: apiSrv.URL, Machine: 22, Token: "tok"},
		Client:       apiSrv.Client(),
		Download:     dlSrv.Client(),
		DisguiseBase: dlSrv.URL + "/",
		DisguiseDir:  disguiseDir,
		DisguiseUnit: unitPath,
		ProbeTLS:     func(string, int) tlsProbeResult { return tlsProbeResult{} },
		PortInUse:    func(int) bool { return false },
		Command: func(ctx context.Context, name string, args ...string) ([]byte, error) {
			if strings.HasSuffix(name, "onenew-disguise") {
				return []byte("v2.9.1 h1:abc\n"), nil
			}
			if name == "systemctl" && len(args) > 0 && args[0] == "is-active" {
				if _, err := os.Stat(unitPath); err != nil {
					return nil, os.ErrNotExist
				}
			}
			return []byte("ok"), nil
		},
	}

	job := SelfStealJob{
		ID:     "11111111-2222-3333-4444-555555555555",
		Lease:  strings.Repeat("a", 64),
		Action: "install",
		Domain: "blog.example.com",
		Port:   8443,
	}
	if err := u.executeSelfSteal(job); err != nil {
		t.Fatalf("install failed: %v", err)
	}
	if len(states) != 2 || states[0] != "running" || states[1] != "succeeded" {
		t.Fatalf("unexpected states: %v", states)
	}
	if _, err := os.Stat(filepath.Join(binDir, "onenew-disguise")); err != nil {
		t.Fatalf("expected onenew-disguise binary: %v", err)
	}
	if _, err := os.Stat(filepath.Join(disguiseDir, "Caddyfile")); err != nil {
		t.Fatalf("expected Caddyfile: %v", err)
	}
	st := u.selfStealStatus()
	if !st.Installed || !st.Running || st.Domain != "blog.example.com" || st.Port != 8443 || st.Version != "2.9.1" {
		t.Fatalf("unexpected status: %+v", st)
	}

	job.Action = "uninstall"
	if err := u.executeSelfSteal(job); err != nil {
		t.Fatalf("uninstall failed: %v", err)
	}
	if _, err := os.Stat(filepath.Join(binDir, "onenew-disguise")); !os.IsNotExist(err) {
		t.Fatalf("expected binary removed")
	}
}

func TestExecuteSelfStealAutoDetectsExistingSiteAndChecksTLS13HTTP2(t *testing.T) {
	var lastCode, lastState string
	apiSrv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		var body map[string]any
		_ = json.NewDecoder(r.Body).Decode(&body)
		if s, ok := body["state"].(string); ok {
			lastState = s
		}
		if c, ok := body["error_code"].(string); ok {
			lastCode = c
		}
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"ok":true}`))
	}))
	defer apiSrv.Close()

	tmp := t.TempDir()
	u := &Updater{
		Root:         tmp,
		Bin:          filepath.Join(tmp, "bin"),
		Manager:      "systemd",
		Binding:      Binding{Panel: apiSrv.URL, Machine: 1, Token: "tok"},
		Client:       apiSrv.Client(),
		DisguiseDir:  filepath.Join(tmp, "etc-disguise"),
		DisguiseUnit: filepath.Join(tmp, "onenew-disguise.service"),
		Command: func(ctx context.Context, name string, args ...string) ([]byte, error) {
			return nil, os.ErrNotExist
		},
	}
	job := SelfStealJob{
		ID:     "11111111-2222-3333-4444-555555555555",
		Lease:  strings.Repeat("b", 64),
		Action: "install",
		Domain: "bitsflow.example.com",
		Port:   8443,
	}

	// Missing HTTP/2 on existing local 443 site -> explicit error
	u.ProbeTLS = func(string, int) tlsProbeResult {
		return tlsProbeResult{Reachable: true, CertValid: true, TLS13: true, HTTP2: false}
	}
	if err := u.executeSelfSteal(job); err == nil || lastCode != "missing_http2" {
		t.Fatalf("expected missing_http2, got err=%v code=%s", err, lastCode)
	}

	// Existing local 443 site with valid cert + TLS 1.3 + HTTP/2 -> auto-reused without installing Caddy
	u.ProbeTLS = func(string, int) tlsProbeResult {
		return tlsProbeResult{Reachable: true, CertValid: true, TLS13: true, HTTP2: true}
	}
	if err := u.executeSelfSteal(job); err != nil || lastState != "succeeded" {
		t.Fatalf("expected existing reuse success, got err=%v state=%s", err, lastState)
	}
	st := u.selfStealStatus()
	if !st.Installed || !st.Running || st.Port != 443 || st.Version != "existing-h2-tls13" {
		t.Fatalf("unexpected existing status: %+v", st)
	}
}
