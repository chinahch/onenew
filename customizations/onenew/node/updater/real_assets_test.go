package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"testing"
)

// Exercises shipped v15 and newly built v17 ELF binaries in a disposable
// filesystem. Service-manager calls are simulated; version probes execute real
// binaries. The native kernel suite separately verifies real process health.
func TestRealV15ToV17Assets(t *testing.T) {
	oldPkg, newPkg := os.Getenv("ONENEW_TEST_V15_PACKAGE"), os.Getenv("ONENEW_TEST_V17_PACKAGE")
	if oldPkg == "" || newPkg == "" {
		t.Skip("Linux release asset integration runs in CI")
	}
	if runtime.GOOS != "linux" || runtime.GOARCH != "amd64" {
		t.Fatal("release fixture requires linux/amd64")
	}
	for _, rollback := range []bool{false, true} {
		t.Run(map[bool]string{false: "upgrade", true: "rollback"}[rollback], func(t *testing.T) {
			h := newHarness(t)
			if e := extract(oldPkg, h.u.stateDir()); e != nil {
				t.Fatal(e)
			}
			for _, n := range []string{"caddy", "caddyctl"} {
				if e := copyFile(filepath.Join(h.u.stateDir(), n+".candidate"), filepath.Join(h.u.Bin, n), 0755); e != nil {
					t.Fatal(e)
				}
			}
			originalCommand := h.u.Command
			h.u.Command = func(ctx context.Context, n string, a ...string) ([]byte, error) {
				if len(a) == 1 && a[0] == "-v" {
					return exec.CommandContext(ctx, n, a...).Output()
				}
				return originalCommand(ctx, n, a...)
			}
			if h.u.version() != "v0.1.15" {
				t.Fatal("old asset version mismatch")
			}
			h.healthyVersion = "v0.1.15"
			var e error
			h.pkg, e = os.ReadFile(newPkg)
			if e != nil {
				t.Fatal(e)
			}
			sum := sha256.Sum256(h.pkg)
			h.sum = hex.EncodeToString(sum[:])
			writeTest(t, filepath.Join(h.u.Root, "config.yml"), "preserve-config-and-certificates")
			h.unhealthy = rollback
			if e = h.u.execute(h.job("v0.1.20")); e != nil {
				t.Fatal(e)
			}
			expected := "v0.1.20"
			state := "succeeded"
			if rollback {
				expected = "v0.1.15"
				state = "rolled_back"
			}
			if h.u.version() != expected || h.states[len(h.states)-1] != state {
				t.Fatalf("version=%s states=%v", h.u.version(), h.states)
			}
			b, _ := os.ReadFile(filepath.Join(h.u.Root, "config.yml"))
			if string(b) != "preserve-config-and-certificates" {
				t.Fatal("config changed")
			}
		})
	}
}
