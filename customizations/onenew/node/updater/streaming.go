package main

import (
	"errors"
	"io"
	"os"
	"path/filepath"
)

const binaryLimit int64 = 256 * 1024 * 1024

// streamAtomic keeps incomplete data private and never exposes a partial binary.
func streamAtomic(path string, r io.Reader, size int64, mode os.FileMode) error {
	if size < 0 || size > binaryLimit {
		return errors.New("invalid binary size")
	}
	if st, e := os.Lstat(path); e == nil {
		if !st.Mode().IsRegular() {
			return errors.New("invalid destination")
		}
	} else if !os.IsNotExist(e) {
		return e
	}
	f, e := os.CreateTemp(filepath.Dir(path), "."+filepath.Base(path)+"-*")
	if e != nil {
		return e
	}
	defer os.Remove(f.Name())
	n, e := io.Copy(f, io.LimitReader(r, size+1))
	if e == nil && n != size {
		e = errors.New("invalid binary size")
	}
	if e == nil {
		e = f.Chmod(mode)
	}
	if e == nil {
		e = f.Sync()
	}
	ce := f.Close()
	if e == nil {
		e = ce
	}
	if e != nil {
		return e
	}
	if e = os.Rename(f.Name(), path); e != nil {
		return e
	}
	return syncDir(filepath.Dir(path))
}

func copyFile(src, dst string, mode os.FileMode) error {
	st, e := os.Lstat(src)
	if e != nil {
		return e
	}
	if !st.Mode().IsRegular() || st.Size() > binaryLimit {
		return errors.New("invalid file")
	}
	f, e := os.Open(src)
	if e != nil {
		return e
	}
	defer f.Close()
	opened, e := f.Stat()
	if e != nil {
		return e
	}
	if !opened.Mode().IsRegular() || !os.SameFile(st, opened) {
		return errors.New("invalid file")
	}
	return streamAtomic(dst, f, opened.Size(), mode)
}
