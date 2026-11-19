package main

import (
	"archive/tar"
	"compress/gzip"
	"crypto/sha256"
	"encoding/hex"
	"errors"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
)

func (u *Updater) fetch(raw, path string, limit int64) error {
	req, e := http.NewRequest("GET", raw, nil)
	if e != nil {
		return e
	}
	res, e := u.Download.Do(req)
	if e != nil {
		return errors.New("download failed")
	}
	defer res.Body.Close()
	if res.StatusCode != 200 {
		return errors.New("download rejected")
	}
	f, e := os.OpenFile(path, os.O_CREATE|os.O_TRUNC|os.O_WRONLY, 0600)
	if e != nil {
		return e
	}
	n, e := io.Copy(f, io.LimitReader(res.Body, limit+1))
	ce := f.Close()
	if e == nil {
		e = ce
	}
	if n > limit {
		return errors.New("download too large")
	}
	return e
}
func (u *Updater) download(j Job) error {
	r := j.Release
	base := u.Base
	if base == "" {
		base = officialBase
	}
	dir := base + "node-" + r.Version + "/"
	sumPath := filepath.Join(u.stateDir(), "checksums")
	if e := u.fetch(dir+"SHA256SUMS-"+r.Arch, sumPath, 16384); e != nil {
		return e
	}
	s, e := os.ReadFile(sumPath)
	if e != nil {
		return e
	}
	count := 0
	for _, line := range strings.Split(string(s), "\n") {
		f := strings.Fields(line)
		if len(f) == 2 && strings.TrimPrefix(f[1], "*") == "onenew-node-linux-"+r.Arch+".tar.gz" {
			if f[0] != r.SHA {
				return errors.New("checksum mismatch")
			}
			count++
		}
	}
	if count != 1 {
		return errors.New("ambiguous checksum")
	}
	pkg := filepath.Join(u.stateDir(), "package.tar.gz")
	if e = u.fetch(dir+"onenew-node-linux-"+r.Arch+".tar.gz", pkg, 256*1024*1024); e != nil {
		return e
	}
	f, e := os.Open(pkg)
	if e != nil {
		return e
	}
	h := sha256.New()
	_, e = io.Copy(h, f)
	f.Close()
	if e != nil || hex.EncodeToString(h.Sum(nil)) != r.SHA {
		return errors.New("checksum mismatch")
	}
	return extract(pkg, u.stateDir())
}
func extract(pkg, dir string) error {
	f, e := os.Open(pkg)
	if e != nil {
		return e
	}
	defer f.Close()
	gz, e := gzip.NewReader(f)
	if e != nil {
		return e
	}
	defer gz.Close()
	tr := tar.NewReader(gz)
	seen := map[string]bool{}
	for {
		h, e := tr.Next()
		if e == io.EOF {
			break
		}
		if e != nil {
			return e
		}
		if (h.Name != "caddy" && h.Name != "caddyctl") || seen[h.Name] || h.Typeflag != tar.TypeReg || h.Size < 1 || h.Size > binaryLimit {
			return errors.New("invalid package")
		}
		seen[h.Name] = true
		if e = streamAtomic(filepath.Join(dir, h.Name+".candidate"), tr, h.Size, 0755); e != nil {
			return e
		}
	}
	if len(seen) != 2 {
		return errors.New("missing binary")
	}
	return nil
}
