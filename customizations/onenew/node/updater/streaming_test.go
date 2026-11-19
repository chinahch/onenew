package main

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"crypto/sha256"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"testing"
)

type repeatedByte byte

func (b repeatedByte) Read(p []byte) (int, error) {
	for i := range p {
		p[i] = byte(b)
	}
	return len(p), nil
}

func TestLargeArchiveAndCopiesBoundedAllocation(t *testing.T) {
	if os.Getenv("ONENEW_STREAM_CHILD") != "1" {
		cmd := exec.Command(os.Args[0], "-test.run=^TestLargeArchiveAndCopiesBoundedAllocation$", "-test.v")
		cmd.Env = append(os.Environ(), "ONENEW_STREAM_CHILD=1")
		out, e := cmd.CombinedOutput()
		if e != nil {
			t.Fatalf("fresh child: %v\n%s", e, out)
		}
		t.Log(string(out))
		return
	}
	dir := t.TempDir()
	pkg := filepath.Join(dir, "package.tar.gz")
	f, e := os.Create(pkg)
	if e != nil {
		t.Fatal(e)
	}
	gz := gzip.NewWriter(f)
	tw := tar.NewWriter(gz)
	for _, name := range []string{"caddy", "caddyctl"} {
		size := int64(64 << 20)
		if name == "caddyctl" {
			size = 1 << 20
		}
		if e = tw.WriteHeader(&tar.Header{Name: name, Mode: 0755, Size: size, Typeflag: tar.TypeReg}); e != nil {
			t.Fatal(e)
		}
		if _, e = io.CopyN(tw, repeatedByte(0x5a), size); e != nil {
			t.Fatal(e)
		}
	}
	if e = tw.Close(); e != nil {
		t.Fatal(e)
	}
	if e = gz.Close(); e != nil {
		t.Fatal(e)
	}
	f.Close()
	runtime.GC()
	var before, after runtime.MemStats
	runtime.ReadMemStats(&before)
	if e = extract(pkg, dir); e != nil {
		t.Fatal(e)
	}
	for _, name := range []string{"caddy", "caddyctl"} {
		if e = copyFile(filepath.Join(dir, name+".candidate"), filepath.Join(dir, name), 0755); e != nil {
			t.Fatal(e)
		}
	}
	runtime.ReadMemStats(&after)
	t.Logf("extraction and copies allocated %d bytes", after.TotalAlloc-before.TotalAlloc)
	if n := after.TotalAlloc - before.TotalAlloc; n > 8<<20 {
		t.Fatalf("archive extraction and copies allocated %d bytes; want <8MiB", n)
	}
	for _, name := range []string{"caddy", "caddyctl"} {
		size := int64(64 << 20)
		if name == "caddyctl" {
			size = 1 << 20
		}
		f, e := os.Open(filepath.Join(dir, name))
		if e != nil {
			t.Fatal(e)
		}
		h := sha256.New()
		n, e := io.Copy(h, f)
		st, _ := f.Stat()
		f.Close()
		want := sha256.New()
		io.CopyN(want, repeatedByte(0x5a), size)
		if e != nil || n != size || !bytes.Equal(h.Sum(nil), want.Sum(nil)) {
			t.Fatal("copy contents differ")
		}
		if runtime.GOOS != "windows" && st.Mode().Perm() != 0755 {
			t.Fatal(st.Mode())
		}
	}
}

type failingBinaryReader struct{ sent bool }

func (r *failingBinaryReader) Read(p []byte) (int, error) {
	if r.sent {
		return 0, io.ErrUnexpectedEOF
	}
	r.sent = true
	return copy(p, "partial"), nil
}

func TestStreamAtomicFailurePreservesDestinationAndCleansTemp(t *testing.T) {
	for _, tc := range []struct {
		name string
		r    io.Reader
		size int64
	}{
		{"short", bytes.NewBufferString("short"), 12},
		{"long", bytes.NewBufferString("too long"), 2},
		{"read error", &failingBinaryReader{}, 12},
		{"over limit", bytes.NewReader(nil), 256<<20 + 1},
	} {
		t.Run(tc.name, func(t *testing.T) {
			dir := t.TempDir()
			dst := filepath.Join(dir, "caddy")
			if e := os.WriteFile(dst, []byte("original"), 0755); e != nil {
				t.Fatal(e)
			}
			if e := streamAtomic(dst, tc.r, tc.size, 0755); e == nil {
				t.Fatal("accepted incomplete or oversized binary")
			}
			b, e := os.ReadFile(dst)
			if e != nil || string(b) != "original" {
				t.Fatal("changed destination", e)
			}
			entries, e := os.ReadDir(dir)
			if e != nil || len(entries) != 1 {
				t.Fatal("left temporary files", entries, e)
			}
		})
	}
}

func TestCopyFileRejectsSymlinksAndOversize(t *testing.T) {
	dir := t.TempDir()
	src := filepath.Join(dir, "source")
	dst := filepath.Join(dir, "destination")
	if e := os.WriteFile(src, []byte("source"), 0600); e != nil {
		t.Fatal(e)
	}
	if e := os.Symlink(src, filepath.Join(dir, "link")); e != nil {
		t.Log("symlink unavailable:", e)
	} else {
		if e = copyFile(filepath.Join(dir, "link"), dst, 0755); e == nil {
			t.Fatal("followed source symlink")
		}
		if e = copyFile(src, filepath.Join(dir, "link"), 0755); e == nil {
			t.Fatal("accepted destination symlink")
		}
		b, _ := os.ReadFile(src)
		if string(b) != "source" {
			t.Fatal("modified symlink target")
		}
	}
	f, e := os.OpenFile(src, os.O_WRONLY, 0600)
	if e != nil {
		t.Fatal(e)
	}
	e = f.Truncate(256<<20 + 1)
	f.Close()
	if e != nil {
		t.Fatal(e)
	}
	if e = copyFile(src, dst, 0755); e == nil {
		t.Fatal("accepted oversized source")
	}
	if _, e = os.Stat(dst); !os.IsNotExist(e) {
		t.Fatal("published invalid copy")
	}
}

type observingBinaryReader struct {
	t        *testing.T
	dir, dst string
	done     bool
}

func (r *observingBinaryReader) Read(p []byte) (int, error) {
	if r.done {
		return 0, io.EOF
	}
	r.done = true
	b, e := os.ReadFile(r.dst)
	if e != nil || string(b) != "original" {
		r.t.Fatal("destination changed before complete write")
	}
	entries, e := os.ReadDir(r.dir)
	if e != nil {
		r.t.Fatal(e)
	}
	for _, entry := range entries {
		if entry.Name() == "caddy" {
			continue
		}
		st, e := entry.Info()
		if e != nil {
			r.t.Fatal(e)
		}
		if runtime.GOOS != "windows" && st.Mode().Perm() != 0600 {
			r.t.Fatal("temporary file not private", st.Mode())
		}
	}
	return copy(p, "replacement"), nil
}
func TestStreamAtomicPublishesOnlyCompleteContents(t *testing.T) {
	dir := t.TempDir()
	dst := filepath.Join(dir, "caddy")
	if e := os.WriteFile(dst, []byte("original"), 0755); e != nil {
		t.Fatal(e)
	}
	if e := streamAtomic(dst, &observingBinaryReader{t: t, dir: dir, dst: dst}, 11, 0755); e != nil {
		t.Fatal(e)
	}
	b, e := os.ReadFile(dst)
	if e != nil || string(b) != "replacement" {
		t.Fatal("incorrect replacement", e)
	}
	entries, e := os.ReadDir(dir)
	if e != nil || len(entries) != 1 {
		t.Fatal("temporary files remain")
	}
}

func TestArchiveMemberValidation(t *testing.T) {
	for _, tc := range []struct {
		name    string
		headers []*tar.Header
	}{
		{"missing", []*tar.Header{{Name: "caddy", Typeflag: tar.TypeReg, Size: 1}}},
		{"duplicate", []*tar.Header{{Name: "caddy", Typeflag: tar.TypeReg, Size: 1}, {Name: "caddy", Typeflag: tar.TypeReg, Size: 1}}},
		{"symlink", []*tar.Header{{Name: "caddy", Typeflag: tar.TypeSymlink, Linkname: "elsewhere"}}},
		{"empty", []*tar.Header{{Name: "caddy", Typeflag: tar.TypeReg, Size: 0}}},
		{"oversize", []*tar.Header{{Name: "caddy", Typeflag: tar.TypeReg, Size: 256<<20 + 1}}},
		{"truncated", []*tar.Header{{Name: "caddy", Typeflag: tar.TypeReg, Size: 1000}}},
	} {
		t.Run(tc.name, func(t *testing.T) {
			dir := t.TempDir()
			pkg := filepath.Join(dir, "pkg")
			f, e := os.Create(pkg)
			if e != nil {
				t.Fatal(e)
			}
			gz := gzip.NewWriter(f)
			tw := tar.NewWriter(gz)
			for _, h := range tc.headers {
				if e = tw.WriteHeader(h); e != nil {
					t.Fatal(e)
				}
				if h.Size == 1 {
					tw.Write([]byte("x"))
				}
			}
			tw.Close()
			gz.Close()
			f.Close()
			if e = extract(pkg, dir); e == nil {
				t.Fatal("accepted invalid members")
			}
			entries, _ := filepath.Glob(filepath.Join(dir, ".caddy.candidate-*"))
			if len(entries) != 0 {
				t.Fatal("incomplete extraction left temporary files")
			}
		})
	}
}
