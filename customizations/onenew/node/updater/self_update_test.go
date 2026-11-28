package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"runtime"
	"testing"
	"time"
)

func TestSelfUpdateVerifiesChecksumAndReplacesAtomically(t *testing.T) {
	bin := t.TempDir()
	root := t.TempDir()
	if err := os.MkdirAll(filepath.Join(root, "updater"), 0700); err != nil {
		t.Fatal(err)
	}
	target := filepath.Join(bin, "caddy-updater")
	if err := os.WriteFile(target, []byte("old-updater"), 0755); err != nil {
		t.Fatal(err)
	}

	candidateBytes := []byte("new-verified-updater-binary")
	sum := sha256.Sum256(candidateBytes)
	goodHex := hex.EncodeToString(sum[:])
	arch := runtime.GOARCH
	asset := "caddy-updater-linux-" + arch
	serveBadSum := false

	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/node-v9.9.9/SHA256SUMS-updater-" + arch:
			if serveBadSum {
				w.Write([]byte("0000000000000000000000000000000000000000000000000000000000000000  " + asset + "\n"))
			} else {
				w.Write([]byte(goodHex + "  " + asset + "\n"))
			}
		case "/node-v9.9.9/" + asset:
			w.Write(candidateBytes)
		default:
			http.NotFound(w, r)
		}
	}))
	defer srv.Close()

	u := &Updater{
		Root:     root,
		Bin:      bin,
		Manager:  "systemd",
		Binding:  Binding{Panel: "https://panel.example.test", Machine: 7, Token: "tok"},
		Download: srv.Client(),
		Base:     srv.URL + "/",
		Now:      time.Now,
		Sleep:    func(time.Duration) {},
		Command: func(ctx context.Context, name string, args ...string) ([]byte, error) {
			return []byte("7\n"), nil
		},
	}

	// Non-newer version is a no-op
	if err := u.selfUpdate(Release{Version: assistantVersion, Arch: arch}); err != nil {
		t.Fatalf("expected no-op for same version, got %v", err)
	}

	// Bad checksum leaves old binary intact
	serveBadSum = true
	if err := u.selfUpdate(Release{Version: "v9.9.9", Arch: arch}); err == nil {
		t.Fatalf("expected checksum mismatch error")
	}
	got, _ := os.ReadFile(target)
	if string(got) != "old-updater" {
		t.Fatalf("old binary was modified on checksum mismatch")
	}

	// Valid checksum replaces atomically and signals restart
	serveBadSum = false
	if err := u.selfUpdate(Release{Version: "v9.9.9", Arch: arch}); !errors.Is(err, ErrSelfUpdated) {
		t.Fatalf("expected ErrSelfUpdated, got %v", err)
	}
	got, _ = os.ReadFile(target)
	if string(got) != string(candidateBytes) {
		t.Fatalf("updater binary was not replaced")
	}
}
