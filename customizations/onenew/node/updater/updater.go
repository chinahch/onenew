package main

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"os/exec"
	"path/filepath"
	"regexp"
	"runtime"
	"strconv"
	"strings"
	"time"
)

const assistantVersion = "v0.1.18"
const officialBase = "https://github.com/chinahch/onenew-releases/releases/download/"

var versionRE = regexp.MustCompile(`^v(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)$`)
var jobRE = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`)
var ErrUnauthorized = errors.New("machine authorization rejected")
var ErrExpired = errors.New("lease rejected")
var hashRE = regexp.MustCompile(`^[a-f0-9]{64}$`)

type Release struct {
	Version string `json:"version"`
	Arch    string `json:"arch"`
	SHA     string `json:"sha256"`
	URL     string `json:"url"`
}
type Job struct {
	ID      string  `json:"id"`
	Lease   string  `json:"lease"`
	Expires int64   `json:"expires_at"`
	Release Release `json:"release"`
}
type Journal struct {
	Job        Job    `json:"job"`
	Phase      string `json:"phase"`
	OldVersion string `json:"old_version"`
	Result     string `json:"result,omitempty"`
	Error      string `json:"error,omitempty"`
}
type Updater struct {
	recoveryReason     string
	Root, Bin, Manager string
	Binding            Binding
	Client, Download   *http.Client
	Command            func(context.Context, string, ...string) ([]byte, error)
	Sleep              func(time.Duration)
	Now                func() time.Time
	Base               string
	CloudflaredBase    string
}

func normalizeVersion(s string) string {
	r := regexp.MustCompile(`(?:onenew-)?(v[0-9]+\.[0-9]+\.[0-9]+)`)
	m := r.FindStringSubmatch(s)
	if len(m) == 2 {
		return m[1]
	}
	return ""
}
func newer(a, b string) bool {
	if !versionRE.MatchString(a) || !versionRE.MatchString(b) {
		return false
	}
	x := strings.Split(a[1:], ".")
	y := strings.Split(b[1:], ".")
	for i := range x {
		n, e := strconv.ParseUint(x[i], 10, 32)
		if e != nil {
			return false
		}
		m, e := strconv.ParseUint(y[i], 10, 32)
		if e != nil {
			return false
		}
		if n != m {
			return n > m
		}
	}
	return false
}
func (u *Updater) version() string {
	ctx, c := context.WithTimeout(context.Background(), 10*time.Second)
	defer c()
	out, e := u.Command(ctx, filepath.Join(u.Bin, "caddy"), "-v")
	if e != nil {
		return ""
	}
	return normalizeVersion(string(out))
}
func (u *Updater) api(method, path string, payload, out any) error {
	var body io.Reader
	if payload != nil {
		b, e := json.Marshal(payload)
		if e != nil {
			return e
		}
		body = strings.NewReader(string(b))
	}
	req, e := http.NewRequest(method, u.Binding.Panel+path, body)
	if e != nil {
		return e
	}
	req.Header.Set("Authorization", "Bearer "+u.Binding.Token)
	req.Header.Set("Content-Type", "application/json")
	res, e := u.Client.Do(req)
	if e != nil {
		return errors.New("panel request failed")
	}
	defer res.Body.Close()
	if res.StatusCode == 401 || res.StatusCode == 403 {
		return ErrUnauthorized
	}
	if res.StatusCode == 409 {
		return ErrExpired
	}
	if res.StatusCode != 200 {
		return errors.New("panel request rejected")
	}
	if out != nil {
		return json.NewDecoder(io.LimitReader(res.Body, 1048576)).Decode(out)
	}
	return nil
}
func (u *Updater) report(j Job, state, code string) error {
	return u.api("POST", fmt.Sprintf("/api/v3/server/upgrade/%s/status", j.ID), map[string]any{"machine_id": u.Binding.Machine, "lease": j.Lease, "state": state, "version": u.version(), "error_code": code}, nil)
}
func (u *Updater) poll() (*Job, error) {
	var out struct {
		Job *Job `json:"job"`
	}
	q := url.Values{"machine_id": {strconv.Itoa(u.Binding.Machine)}, "arch": {runtime.GOARCH}, "version": {u.version()}, "assistant_version": {assistantVersion}}
	e := u.api("GET", "/api/v3/server/upgrade/poll?"+q.Encode(), nil, &out)
	return out.Job, e
}
func (u *Updater) stateDir() string { return filepath.Join(u.Root, "updater") }
func (u *Updater) save(j Journal) error {
	b, e := json.Marshal(j)
	if e != nil {
		return e
	}
	if e = atomicWrite(filepath.Join(u.stateDir(), "journal.json"), b, 0600); e != nil {
		return journalWriteError{e}
	}
	return nil
}
func atomicWrite(path string, b []byte, mode os.FileMode) error {
	f, e := os.OpenFile(path+".new", os.O_WRONLY|os.O_CREATE|os.O_TRUNC, mode)
	if e != nil {
		return e
	}
	defer os.Remove(path + ".new")
	if e = f.Chmod(mode); e == nil {
		_, e = f.Write(b)
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
	if e = os.Rename(path+".new", path); e != nil {
		return e
	}
	return syncDir(filepath.Dir(path))
}
func syncDir(path string) error {
	if runtime.GOOS == "windows" {
		return nil
	}
	d, e := os.Open(path)
	if e == nil {
		e = d.Sync()
		d.Close()
	}
	return e
}
func (u *Updater) manager(action string) error {
	ctx, c := context.WithTimeout(context.Background(), 30*time.Second)
	defer c()
	var e error
	if u.Manager == "systemd" {
		_, e = u.Command(ctx, "systemctl", action, "caddy.service")
	} else {
		_, e = u.Command(ctx, "rc-service", "caddy", action)
	}
	return e
}
func (u *Updater) healthy() bool {
	ctx, c := context.WithTimeout(context.Background(), 10*time.Second)
	defer c()
	var e error
	if u.Manager == "systemd" {
		_, e = u.Command(ctx, "systemctl", "is-active", "--quiet", "caddy.service")
	} else {
		_, e = u.Command(ctx, "rc-service", "caddy", "status")
	}
	if e != nil {
		return false
	}
	out, e := u.Command(ctx, filepath.Join(u.Bin, "caddyctl"), "instance", "list", "--output", "text")
	if e != nil {
		return false
	}
	for _, line := range strings.Split(string(out), "\n") {
		f := strings.Fields(line)
		if len(f) >= 6 && f[1] == "machine" && strings.TrimRight(f[2], "/") == u.Binding.Panel && f[3] == fmt.Sprintf("machine_id=%d", u.Binding.Machine) && f[5] == "ok" {
			return true
		}
	}
	return false
}
func (u *Updater) waitHealthy(deadline int64) bool {
	if end := u.Now().Unix() + 90; deadline > end {
		deadline = end
	}
	consecutive := 0
	for i := 0; i < 45; i++ {
		if u.Now().Unix() >= deadline {
			return false
		}
		if u.healthy() {
			consecutive++
			if consecutive >= 2 {
				return true
			}
		} else {
			consecutive = 0
		}
		u.Sleep(2 * time.Second)
	}
	return false
}
func (u *Updater) validate(j Job) error {
	r := j.Release
	if !jobRE.MatchString(j.ID) || len(j.Lease) < 16 || len(j.Lease) > 256 || j.Expires < u.Now().Unix()+180 || j.Expires > u.Now().Unix()+1200 || !newer(r.Version, u.version()) || r.Arch != runtime.GOARCH || !hashRE.MatchString(r.SHA) {
		return errors.New("invalid task")
	}
	if r.URL != officialBase+"node-"+r.Version+"/onenew-node-linux-"+r.Arch+".tar.gz" {
		return errors.New("invalid official URL")
	}
	return nil
}
func newUpdater(root, manager string) (*Updater, error) {
	if manager != "systemd" && manager != "openrc" {
		return nil, errors.New("unsupported manager")
	}
	b, e := readBinding(root)
	if e != nil {
		return nil, e
	}
	u := &Updater{Root: root, Bin: "/usr/local/bin", Manager: manager, Binding: b, Client: &http.Client{Timeout: 20 * time.Second, CheckRedirect: func(req *http.Request, via []*http.Request) error { return errors.New("redirect refused") }}, Download: &http.Client{Timeout: 120 * time.Second, CheckRedirect: func(req *http.Request, via []*http.Request) error {
		if len(via) > 5 || req.URL.Scheme != "https" || req.URL.User != nil {
			return errors.New("unsafe redirect")
		}
		h := req.URL.Hostname()
		if h != "github.com" && h != "release-assets.githubusercontent.com" && h != "objects.githubusercontent.com" {
			return errors.New("untrusted redirect")
		}
		return nil
	}}, Now: time.Now, Sleep: time.Sleep, Command: func(ctx context.Context, n string, a ...string) ([]byte, error) {
		return exec.CommandContext(ctx, n, a...).Output()
	}}
	if e = os.MkdirAll(u.stateDir(), 0700); e != nil {
		return nil, e
	}
	st, e := os.Lstat(u.stateDir())
	if e != nil || !st.IsDir() || st.Mode().Perm()&0077 != 0 || st.Mode()&os.ModeSymlink != 0 {
		return nil, errors.New("invalid state directory")
	}
	return u, nil
}
