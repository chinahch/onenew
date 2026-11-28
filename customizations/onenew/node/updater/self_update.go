package main

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"io"
	"os"
	"path/filepath"
	"runtime"
	"strconv"
	"strings"
	"time"
)

var ErrSelfUpdated = errors.New("assistant self-updated")

// selfUpdate downloads and verifies the official caddy-updater binary for a
// newer release after a core upgrade succeeds. Any failure leaves the existing
// assistant untouched without affecting the completed core upgrade.
func (u *Updater) selfUpdate(r Release) error {
	if !newer(r.Version, assistantVersion) || r.Arch != runtime.GOARCH {
		return nil
	}
	base := u.Base
	if base == "" {
		base = officialBase
	}
	dir := base + "node-" + r.Version + "/"
	asset := "caddy-updater-linux-" + r.Arch
	sumPath := filepath.Join(u.stateDir(), "checksums-updater")
	candPath := filepath.Join(u.stateDir(), "caddy-updater.candidate")
	defer os.Remove(sumPath)
	defer os.Remove(candPath)

	if e := u.fetch(dir+"SHA256SUMS-updater-"+r.Arch, sumPath, 16384); e != nil {
		return e
	}
	s, e := os.ReadFile(sumPath)
	if e != nil {
		return e
	}
	expected := ""
	count := 0
	for _, line := range strings.Split(string(s), "\n") {
		f := strings.Fields(line)
		if len(f) == 2 && strings.TrimPrefix(f[1], "*") == asset && hashRE.MatchString(f[0]) {
			expected = f[0]
			count++
		}
	}
	if count != 1 {
		return errors.New("ambiguous updater checksum")
	}
	if e = u.fetch(dir+asset, candPath, binaryLimit); e != nil {
		return e
	}
	f, e := os.Open(candPath)
	if e != nil {
		return e
	}
	h := sha256.New()
	_, e = io.Copy(h, f)
	f.Close()
	if e != nil || hex.EncodeToString(h.Sum(nil)) != expected {
		return errors.New("updater checksum mismatch")
	}
	if e = os.Chmod(candPath, 0755); e != nil {
		return e
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	out, e := u.Command(ctx, candPath, "binding", "--root", u.Root, "--field", "machine")
	cancel()
	if e != nil || strings.TrimSpace(string(out)) != strconv.Itoa(u.Binding.Machine) {
		return errors.New("updater verification failed")
	}
	target := filepath.Join(u.Bin, "caddy-updater")
	if st, err := os.Lstat(target); err == nil && st.Mode()&os.ModeSymlink != 0 {
		return errors.New("updater target is symlink")
	}
	if e = copyFile(candPath, target, 0755); e != nil {
		return e
	}
	return ErrSelfUpdated
}
