package main

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"errors"
	"fmt"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"strings"
	"testing"
	"time"
)

func writeTest(t *testing.T, p, s string) {
	t.Helper()
	if e := os.WriteFile(p, []byte(s), 0600); e != nil {
		t.Fatal(e)
	}
}
func archiveTest(t *testing.T, entries map[string]string) []byte {
	t.Helper()
	var b bytes.Buffer
	gz := gzip.NewWriter(&b)
	tw := tar.NewWriter(gz)
	for name, content := range entries {
		if e := tw.WriteHeader(&tar.Header{Name: name, Size: int64(len(content)), Mode: 0755}); e != nil {
			t.Fatal(e)
		}
		tw.Write([]byte(content))
	}
	tw.Close()
	gz.Close()
	return b.Bytes()
}

type harness struct {
	u         *Updater
	server    *httptest.Server
	pkg       []byte
	sum       string
	states    []string
	stops     int
	unhealthy bool
	reject    bool
}

func newHarness(t *testing.T) *harness {
	t.Helper()
	h := &harness{}
	root := t.TempDir()
	bin := filepath.Join(root, "bin")
	os.Mkdir(bin, 0700)
	os.Mkdir(filepath.Join(root, "updater"), 0700)
	writeTest(t, filepath.Join(bin, "caddy"), "caddy onenew-v0.1.11 (built fixture)")
	writeTest(t, filepath.Join(bin, "caddyctl"), "previous-ctl")
	h.setPackage(t, "v0.1.12")
	h.server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if strings.HasPrefix(r.URL.Path, "/api/") {
			if r.Header.Get("Authorization") != "Bearer test-token-1234567890" {
				t.Error("missing bearer")
			}
			if h.reject {
				w.WriteHeader(503)
				return
			}
			if r.Method == "POST" {
				var body struct {
					State   string `json:"state"`
					Version string `json:"version"`
					Lease   string `json:"lease"`
				}
				json.NewDecoder(r.Body).Decode(&body)
				if body.Lease != "lease-1234567890123456" {
					t.Error("lease missing")
				}
				h.states = append(h.states, body.State)
			}
			fmt.Fprint(w, `{"job":null}`)
			return
		}
		if strings.Contains(r.URL.Path, "SHA256SUMS-") {
			fmt.Fprintf(w, "%s  onenew-node-linux-%s.tar.gz\n", h.sum, runtime.GOARCH)
			return
		}
		w.Write(h.pkg)
	}))
	t.Cleanup(h.server.Close)
	now := time.Unix(1700000000, 0)
	h.u = &Updater{Root: root, Bin: bin, Manager: "systemd", Binding: Binding{h.server.URL, 7, "test-token-1234567890"}, Client: h.server.Client(), Download: h.server.Client(), Base: h.server.URL + "/", Now: func() time.Time { return now }, Sleep: func(d time.Duration) { now = now.Add(d) }}
	h.u.Command = func(ctx context.Context, n string, a ...string) ([]byte, error) {
		if filepath.Base(n) == "systemctl" || filepath.Base(n) == "rc-service" {
			action := a[0]
			if n == "rc-service" {
				action = a[1]
			}
			if action == "stop" {
				h.stops++
			}
			return nil, nil
		}
		if len(a) == 1 && a[0] == "-v" {
			return os.ReadFile(n)
		}
		if filepath.Base(n) == "caddyctl" {
			if h.unhealthy && h.u.version() == "v0.1.12" {
				return []byte("ID MODE PANEL TARGET SERVICE HEALTH\nx machine " + h.server.URL + " machine_id=7 running failed\n"), nil
			}
			return []byte("ID MODE PANEL TARGET SERVICE HEALTH\nx machine " + h.server.URL + " machine_id=7 running ok\n"), nil
		}
		return nil, errors.New("unexpected command")
	}
	return h
}
func (h *harness) setPackage(t *testing.T, v string) {
	h.pkg = archiveTest(t, map[string]string{"caddy": "caddy onenew-" + v + " (built fixture)", "caddyctl": "candidate-ctl"})
	s := sha256.Sum256(h.pkg)
	h.sum = hex.EncodeToString(s[:])
}
func (h *harness) job(v string) Job {
	return Job{ID: "74b860e8-212f-4d7b-a651-96b916831c8c", Lease: "lease-1234567890123456", Expires: h.u.Now().Unix() + 900, Release: Release{Version: v, Arch: runtime.GOARCH, SHA: h.sum, URL: officialBase + "node-" + v + "/onenew-node-linux-" + runtime.GOARCH + ".tar.gz"}}
}
func TestUpgradeAndFutureVersion(t *testing.T) {
	for _, manager := range []string{"systemd", "openrc"} {
		t.Run(manager, func(t *testing.T) {
			h := newHarness(t)
			h.u.Manager = manager
			if e := h.u.execute(h.job("v0.1.12")); e != nil {
				t.Fatal(e)
			}
			if h.u.version() != "v0.1.12" || h.states[len(h.states)-1] != "succeeded" {
				t.Fatalf("upgrade failed: %v", h.states)
			}
			h.setPackage(t, "v0.1.13")
			if e := h.u.execute(h.job("v0.1.13")); e != nil {
				t.Fatal(e)
			}
			if h.u.version() != "v0.1.13" {
				t.Fatal("future official version requires helper replacement")
			}
			if _, e := os.Stat(filepath.Join(h.u.stateDir(), "journal.json")); !os.IsNotExist(e) {
				t.Fatal("acknowledged journal retained")
			}
		})
	}
}
func TestHealthFailureRestoresBothBinaries(t *testing.T) {
	h := newHarness(t)
	h.unhealthy = true
	if e := h.u.execute(h.job("v0.1.12")); e != nil {
		t.Fatal(e)
	}
	if h.u.version() != "v0.1.11" || h.states[len(h.states)-1] != "rolled_back" {
		t.Fatal(h.states)
	}
	b, _ := os.ReadFile(filepath.Join(h.u.Bin, "caddyctl"))
	if string(b) != "previous-ctl" {
		t.Fatal("CLI not restored")
	}
}
func TestChecksumMismatchNeverStopsService(t *testing.T) {
	h := newHarness(t)
	j := h.job("v0.1.12")
	j.Release.SHA = strings.Repeat("a", 64)
	if e := h.u.execute(j); e != nil {
		t.Fatal(e)
	}
	if h.stops != 0 || h.u.version() != "v0.1.11" {
		t.Fatal("mutated before independent checksum verification")
	}
}
func TestInvalidTasksNeverDownloadOrStop(t *testing.T) {
	h := newHarness(t)
	for _, change := range []func(*Job){func(j *Job) { j.Release.URL = "https://evil.invalid/a" }, func(j *Job) { j.Release.Version = "v0.1.10" }, func(j *Job) { j.Expires = h.u.Now().Unix() - 1 }, func(j *Job) { j.Release.Arch = "other" }} {
		j := h.job("v0.1.12")
		change(&j)
		if h.u.execute(j) == nil {
			t.Fatal("accepted invalid task")
		}
	}
	if h.stops != 0 {
		t.Fatal("invalid task stopped node")
	}
}
func TestPowerLossRecoveryAndReportRetry(t *testing.T) {
	h := newHarness(t)
	j := h.job("v0.1.12")
	for _, name := range []string{"caddy", "caddyctl"} {
		if e := copyFile(filepath.Join(h.u.Bin, name), filepath.Join(h.u.stateDir(), name+".previous"), 0755); e != nil {
			t.Fatal(e)
		}
	}
	writeTest(t, filepath.Join(h.u.Bin, "caddy"), "caddy onenew-v0.1.12 (built fixture)")
	h.u.save(Journal{Job: j, Phase: "mutating", OldVersion: "v0.1.11"})
	h.reject = true
	if h.u.recover() == nil {
		t.Fatal("unacknowledged result discarded")
	}
	if h.u.version() != "v0.1.11" {
		t.Fatal("power loss did not rollback")
	}
	stops := h.stops
	h.reject = false
	if e := h.u.recover(); e != nil {
		t.Fatal(e)
	}
	if h.stops != stops {
		t.Fatal("result retry swapped binaries again")
	}
}
func TestArchiveRejectsExtraAndTraversal(t *testing.T) {
	for _, name := range []string{"../evil", "extra"} {
		dir := t.TempDir()
		p := filepath.Join(dir, "p.tar.gz")
		os.WriteFile(p, archiveTest(t, map[string]string{"caddy": "x", "caddyctl": "y", name: "z"}), 0600)
		if extract(p, dir) == nil {
			t.Fatal("accepted extra archive entry")
		}
	}
}
func TestBindingLiteralCredentialAndPermissions(t *testing.T) {
	root := t.TempDir()
	writeTest(t, filepath.Join(root, "config.yml"), "panel:\n  url: https://panel.example/base/\nmachine:\n  machine_id: 7\n  token_env: MACHINE_TOKEN\n")
	writeTest(t, filepath.Join(root, "credentials.env"), "MACHINE_TOKEN=abcdefghijklmnop1234\nIGNORED=$(touch /bad)\n")
	b, e := readBinding(root)
	if e != nil || b.Token != "abcdefghijklmnop1234" || b.Panel != "https://panel.example/base" {
		t.Fatal(b, e)
	}
	writeTest(t, filepath.Join(root, "credentials.env"), "MACHINE_TOKEN=$(touch /bad)\n")
	if _, e = readBinding(root); e == nil {
		t.Fatal("shell value accepted")
	}
	writeTest(t, filepath.Join(root, "credentials.env"), "MACHINE_TOKEN=abcdefghijklmnop1234\nMACHINE_TOKEN=abcdefghijklmnop1234\n")
	if _, e = readBinding(root); e == nil {
		t.Fatal("duplicate accepted")
	}
}
func TestVersions(t *testing.T) {
	if !newer("v0.1.13", "v0.1.12") || newer("v0.1.12", "v0.1.12") || newer("v0.1.11", "v0.1.12") || newer("v999999999999999999999.0.0", "v0.1.12") {
		t.Fatal("bad version ordering")
	}
}
