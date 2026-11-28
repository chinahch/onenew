package main

import (
	"archive/tar"
	"compress/gzip"
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strconv"
	"strings"
	"time"
)

const disguiseCaddyVersion = "2.9.1"
const disguiseCaddyBase = "https://github.com/caddyserver/caddy/releases/download/v" + disguiseCaddyVersion + "/"

var disguiseDomainRE = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$`)
var disguiseVersionRE = regexp.MustCompile(`v([0-9]+\.[0-9]+\.[0-9]+)`)

type SelfStealJob struct {
	ID      string `json:"id"`
	Lease   string `json:"lease"`
	Expires int64  `json:"expires_at"`
	Action  string `json:"action"`
	Domain  string `json:"domain,omitempty"`
	Port    int    `json:"port,omitempty"`
}

type SelfStealStatus struct {
	Installed bool
	Running   bool
	Domain    string
	Port      int
	Version   string
}

type selfStealState struct {
	Mode   string `json:"mode,omitempty"` // "existing" or "managed"
	Domain string `json:"domain"`
	Port   int    `json:"port"`
}

type tlsProbeResult struct {
	Reachable bool
	CertValid bool
	TLS13     bool
	HTTP2     bool
}

func probeLocalTLS(domain string, port int) tlsProbeResult {
	addr := fmt.Sprintf("127.0.0.1:%d", port)
	dialer := &net.Dialer{Timeout: 5 * time.Second}
	conn, err := tls.DialWithDialer(dialer, "tcp", addr, &tls.Config{
		ServerName: domain,
		NextProtos: []string{"h2", "http/1.1"},
		MinVersion: tls.VersionTLS12,
	})
	if err == nil {
		cs := conn.ConnectionState()
		_ = conn.Close()
		return tlsProbeResult{
			Reachable: true,
			CertValid: true,
			TLS13:     cs.Version == tls.VersionTLS13,
			HTTP2:     cs.NegotiatedProtocol == "h2",
		}
	}
	// Distinguish TCP connection refused/timeout from TLS certificate mismatch/expiry
	insecConn, err2 := tls.DialWithDialer(dialer, "tcp", addr, &tls.Config{
		ServerName:         domain,
		NextProtos:         []string{"h2", "http/1.1"},
		MinVersion:         tls.VersionTLS12,
		InsecureSkipVerify: true,
	})
	if err2 == nil {
		cs := insecConn.ConnectionState()
		_ = insecConn.Close()
		return tlsProbeResult{
			Reachable: true,
			CertValid: false,
			TLS13:     cs.Version == tls.VersionTLS13,
			HTTP2:     cs.NegotiatedProtocol == "h2",
		}
	}
	return tlsProbeResult{}
}

func localPortInUse(port int) bool {
	ln, err := net.Listen("tcp", fmt.Sprintf(":%d", port))
	if err != nil {
		return true
	}
	_ = ln.Close()
	return false
}

func validDisguiseDomain(raw string) (string, bool) {
	d := strings.ToLower(strings.TrimSpace(raw))
	if len(d) < 3 || len(d) > 253 {
		return "", false
	}
	return d, disguiseDomainRE.MatchString(d)
}

func renderDisguiseCaddyfile(domain string, port int, siteDir string) (string, error) {
	d, ok := validDisguiseDomain(domain)
	if !ok {
		return "", errors.New("invalid_domain")
	}
	if port < 1 || port > 65535 || port == 80 {
		return "", errors.New("invalid_port")
	}
	if port == 443 {
		return fmt.Sprintf("{\n\tadmin off\n}\n\n%s {\n\troot * %s\n\tfile_server\n}\n", d, siteDir), nil
	}
	return fmt.Sprintf("{\n\tadmin off\n}\n\nhttp://%s:80 {\n\trespond \"OK\" 200\n}\n\nhttps://%s:%d {\n\tbind 127.0.0.1\n\troot * %s\n\tfile_server\n}\n", d, d, port, siteDir), nil
}

func (u *Updater) disguiseBin() string {
	return filepath.Join(u.Bin, "onenew-disguise")
}

func (u *Updater) disguiseDir() string {
	if u.DisguiseDir != "" {
		return u.DisguiseDir
	}
	return "/etc/onenew-disguise"
}

func (u *Updater) disguiseUnitPath() string {
	if u.DisguiseUnit != "" {
		return u.DisguiseUnit
	}
	return "/etc/systemd/system/onenew-disguise.service"
}

func (u *Updater) probeTLS(domain string, port int) tlsProbeResult {
	if u.ProbeTLS != nil {
		return u.ProbeTLS(domain, port)
	}
	return probeLocalTLS(domain, port)
}

func (u *Updater) portInUse(port int) bool {
	if u.PortInUse != nil {
		return u.PortInUse(port)
	}
	return localPortInUse(port)
}

func (u *Updater) isManagedDisguiseRunning() bool {
	ctx, c := context.WithTimeout(context.Background(), 5*time.Second)
	defer c()
	if u.Manager == "systemd" {
		_, err := u.Command(ctx, "systemctl", "is-active", "--quiet", "onenew-disguise.service")
		return err == nil
	}
	_, err := u.Command(ctx, "rc-service", "onenew-disguise", "status")
	return err == nil
}

func (u *Updater) selfStealStatus() SelfStealStatus {
	ctx, c := context.WithTimeout(context.Background(), 10*time.Second)
	defer c()
	var st SelfStealStatus
	var saved selfStealState
	if raw, err := os.ReadFile(filepath.Join(u.disguiseDir(), "state.json")); err == nil {
		_ = json.Unmarshal(raw, &saved)
		st.Domain = saved.Domain
		st.Port = saved.Port
	}
	if saved.Mode == "existing" && saved.Domain != "" && saved.Port > 0 {
		st.Installed = true
		st.Version = "existing-h2-tls13"
		res := u.probeTLS(saved.Domain, saved.Port)
		st.Running = res.Reachable && res.CertValid && res.TLS13 && res.HTTP2
		return st
	}
	if out, err := u.Command(ctx, u.disguiseBin(), "version"); err == nil {
		st.Installed = true
		if m := disguiseVersionRE.FindStringSubmatch(string(out)); len(m) == 2 {
			st.Version = m[1]
		}
	}
	if st.Installed {
		st.Running = u.isManagedDisguiseRunning()
	}
	return st
}

func (u *Updater) pollSelfSteal() (*SelfStealJob, error) {
	st := u.selfStealStatus()
	installed, running := "0", "0"
	if st.Installed {
		installed = "1"
	}
	if st.Running {
		running = "1"
	}
	var out struct {
		Job *SelfStealJob `json:"job"`
	}
	q := url.Values{
		"machine_id":        {strconv.Itoa(u.Binding.Machine)},
		"arch":              {runtime.GOARCH},
		"installed":         {installed},
		"running":           {running},
		"domain":            {st.Domain},
		"port":              {strconv.Itoa(st.Port)},
		"version":           {st.Version},
		"assistant_version": {assistantVersion},
	}
	e := u.api("GET", "/api/v3/server/self-steal/poll?"+q.Encode(), nil, &out)
	return out.Job, e
}

func (u *Updater) reportSelfSteal(j SelfStealJob, state, code string) error {
	st := u.selfStealStatus()
	return u.api("POST", fmt.Sprintf("/api/v3/server/self-steal/%s/status", j.ID), map[string]any{
		"machine_id": u.Binding.Machine,
		"lease":      j.Lease,
		"state":      state,
		"installed":  st.Installed,
		"running":    st.Running,
		"domain":     st.Domain,
		"port":       st.Port,
		"version":    st.Version,
		"error_code": code,
	}, nil)
}

func (u *Updater) downloadDisguiseBinary() error {
	arch := runtime.GOARCH
	if arch != "amd64" && arch != "arm64" {
		return errors.New("unsupported")
	}
	base := u.DisguiseBase
	if base == "" {
		base = disguiseCaddyBase
	}
	asset := fmt.Sprintf("caddy_%s_linux_%s.tar.gz", disguiseCaddyVersion, arch)
	req, err := http.NewRequest("GET", base+asset, nil)
	if err != nil {
		return err
	}
	res, err := u.Download.Do(req)
	if err != nil {
		return err
	}
	defer res.Body.Close()
	if res.StatusCode != 200 {
		return fmt.Errorf("download status %d", res.StatusCode)
	}
	gz, err := gzip.NewReader(io.LimitReader(res.Body, 128<<20))
	if err != nil {
		return err
	}
	defer gz.Close()
	tr := tar.NewReader(gz)
	target := u.disguiseBin()
	tmp := target + ".new"
	found := false
	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}
		if err != nil {
			return err
		}
		if hdr.Typeflag == tar.TypeReg && filepath.Base(hdr.Name) == "caddy" {
			f, err := os.OpenFile(tmp, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0755)
			if err != nil {
				return err
			}
			_, copyErr := io.Copy(f, io.LimitReader(tr, 128<<20))
			if copyErr != nil {
				f.Close()
				os.Remove(tmp)
				return copyErr
			}
			if err = f.Chmod(0755); err != nil {
				f.Close()
				os.Remove(tmp)
				return err
			}
			if err = f.Sync(); err != nil {
				f.Close()
				os.Remove(tmp)
				return err
			}
			if err = f.Close(); err != nil {
				os.Remove(tmp)
				return err
			}
			found = true
			break
		}
	}
	if !found {
		return errors.New("binary missing from archive")
	}
	if err := os.Rename(tmp, target); err != nil {
		os.Remove(tmp)
		return err
	}
	return syncDir(filepath.Dir(target))
}

func (u *Updater) executeSelfSteal(j SelfStealJob) error {
	if !jobRE.MatchString(j.ID) || len(j.Lease) != 64 {
		return errors.New("invalid self-steal task")
	}
	if err := u.reportSelfSteal(j, "running", ""); err != nil {
		return err
	}
	ctx, cancel := context.WithTimeout(context.Background(), 120*time.Second)
	defer cancel()

	fail := func(code string) error {
		_ = u.reportSelfSteal(j, "failed", code)
		return errors.New(code)
	}

	if u.Manager != "systemd" {
		return fail("unsupported")
	}

	switch j.Action {
	case "install":
		domain, ok := validDisguiseDomain(j.Domain)
		if !ok {
			return fail("invalid_domain")
		}
		port := j.Port
		if port == 0 {
			port = 8443
		}
		if port < 1 || port > 65535 || port == 80 {
			return fail("invalid_port")
		}

		// Auto-detect an existing local HTTPS server (e.g. OpenResty/Nginx on 127.0.0.1:443)
		// before attempting to spin up a new Caddy instance on port 80/8443.
		if !u.isManagedDisguiseRunning() {
			checkPort := 443
			if existing := u.probeTLS(domain, checkPort); existing.Reachable {
				if !existing.CertValid {
					return fail("cert_invalid")
				}
				if !existing.TLS13 {
					return fail("missing_tls13")
				}
				if !existing.HTTP2 {
					return fail("missing_http2")
				}
				dir := u.disguiseDir()
				if err := os.MkdirAll(dir, 0755); err != nil {
					return fail("install_failed")
				}
				stateBytes, _ := json.Marshal(selfStealState{Mode: "existing", Domain: domain, Port: checkPort})
				if err := os.WriteFile(filepath.Join(dir, "state.json"), stateBytes, 0644); err != nil {
					return fail("install_failed")
				}
				return u.reportSelfSteal(j, "succeeded", "")
			}
			if u.portInUse(80) {
				return fail("port_80_in_use")
			}
		}

		if _, err := os.Stat(u.disguiseBin()); err != nil {
			if err := u.downloadDisguiseBinary(); err != nil {
				return fail("download_failed")
			}
		}
		dir := u.disguiseDir()
		siteDir := filepath.Join(dir, "site")
		if err := os.MkdirAll(siteDir, 0755); err != nil {
			return fail("install_failed")
		}
		indexPath := filepath.Join(siteDir, "index.html")
		if _, err := os.Stat(indexPath); err != nil {
			html := fmt.Sprintf("<!DOCTYPE html><html lang=\"en\"><head><meta charset=\"utf-8\"><meta name=\"viewport\" content=\"width=device-width,initial-scale=1\"><title>%s</title><style>body{font-family:system-ui,sans-serif;max-width:680px;margin:10vh auto;padding:0 20px;color:#222;line-height:1.7}h1{font-size:1.5rem}p{color:#555}</style></head><body><h1>%s</h1><p>Welcome. This site is currently under maintenance.</p></body></html>\n", domain, domain)
			if err := os.WriteFile(indexPath, []byte(html), 0644); err != nil {
				return fail("install_failed")
			}
		}
		caddyfile, err := renderDisguiseCaddyfile(domain, port, siteDir)
		if err != nil {
			return fail("install_failed")
		}
		if err := os.WriteFile(filepath.Join(dir, "Caddyfile"), []byte(caddyfile), 0644); err != nil {
			return fail("install_failed")
		}
		stateBytes, _ := json.Marshal(selfStealState{Mode: "managed", Domain: domain, Port: port})
		if err := os.WriteFile(filepath.Join(dir, "state.json"), stateBytes, 0644); err != nil {
			return fail("install_failed")
		}
		unit := fmt.Sprintf(`[Unit]
Description=Onenew Reality Self-Steal Disguise Web Server
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
Environment=XDG_DATA_HOME=/var/lib/onenew-disguise
Environment=XDG_CONFIG_HOME=%s
ExecStart=%s run --config %s/Caddyfile --adapter caddyfile
ExecReload=%s reload --config %s/Caddyfile --adapter caddyfile
Restart=on-failure
RestartSec=3s
LimitNOFILE=65536

[Install]
WantedBy=multi-user.target
`, dir, u.disguiseBin(), dir, u.disguiseBin(), dir)
		if err := os.WriteFile(u.disguiseUnitPath(), []byte(unit), 0644); err != nil {
			return fail("install_failed")
		}
		_, _ = u.Command(ctx, "systemctl", "daemon-reload")
		if _, err := u.Command(ctx, "systemctl", "enable", "--now", "onenew-disguise.service"); err != nil {
			return fail("service_failed")
		}
		if _, err := u.Command(ctx, "systemctl", "restart", "onenew-disguise.service"); err != nil {
			return fail("service_failed")
		}
		if u.Sleep != nil {
			u.Sleep(2 * time.Second)
		}
		if _, err := u.Command(ctx, "systemctl", "is-active", "--quiet", "onenew-disguise.service"); err != nil {
			return fail("service_failed")
		}
		return u.reportSelfSteal(j, "succeeded", "")

	case "restart":
		var saved selfStealState
		if raw, err := os.ReadFile(filepath.Join(u.disguiseDir(), "state.json")); err == nil {
			_ = json.Unmarshal(raw, &saved)
		}
		if saved.Mode == "existing" {
			res := u.probeTLS(saved.Domain, saved.Port)
			if !res.Reachable {
				return fail("service_failed")
			}
			if !res.CertValid {
				return fail("cert_invalid")
			}
			if !res.TLS13 {
				return fail("missing_tls13")
			}
			if !res.HTTP2 {
				return fail("missing_http2")
			}
			return u.reportSelfSteal(j, "succeeded", "")
		}
		if _, err := u.Command(ctx, "systemctl", "restart", "onenew-disguise.service"); err != nil {
			return fail("service_failed")
		}
		if u.Sleep != nil {
			u.Sleep(2 * time.Second)
		}
		if _, err := u.Command(ctx, "systemctl", "is-active", "--quiet", "onenew-disguise.service"); err != nil {
			return fail("service_failed")
		}
		return u.reportSelfSteal(j, "succeeded", "")

	case "uninstall":
		_, _ = u.Command(ctx, "systemctl", "disable", "--now", "onenew-disguise.service")
		_ = os.Remove(u.disguiseUnitPath())
		_, _ = u.Command(ctx, "systemctl", "daemon-reload")
		_ = os.RemoveAll(u.disguiseDir())
		_ = os.Remove(u.disguiseBin())
		return u.reportSelfSteal(j, "succeeded", "")

	default:
		return fail("invalid_task")
	}
}
