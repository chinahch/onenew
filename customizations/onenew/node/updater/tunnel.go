package main

import (
	"context"
	"errors"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strconv"
	"time"
)

const cloudflaredBase = "https://github.com/cloudflare/cloudflared/releases/latest/download/"

var tunnelTokenRE = regexp.MustCompile(`^[A-Za-z0-9_-]{32,1024}={0,2}$`)
var cloudflaredVersionRE = regexp.MustCompile(`([0-9]{4}\.[0-9]+\.[0-9]+)`)

type TunnelJob struct {
	ID      string `json:"id"`
	Lease   string `json:"lease"`
	Expires int64  `json:"expires_at"`
	Action  string `json:"action"`
	Token   string `json:"token,omitempty"`
}

type TunnelStatus struct {
	Installed bool
	Running   bool
	Version   string
}

func (u *Updater) cloudflaredBin() string {
	return filepath.Join(u.Bin, "cloudflared")
}

func (u *Updater) tunnelStatus() TunnelStatus {
	ctx, c := context.WithTimeout(context.Background(), 10*time.Second)
	defer c()
	var st TunnelStatus
	if out, err := u.Command(ctx, u.cloudflaredBin(), "--version"); err == nil {
		st.Installed = true
		if m := cloudflaredVersionRE.FindStringSubmatch(string(out)); len(m) == 2 {
			st.Version = m[1]
		}
	}
	if st.Installed {
		if u.Manager == "systemd" {
			if _, err := u.Command(ctx, "systemctl", "is-active", "--quiet", "cloudflared.service"); err == nil {
				st.Running = true
			}
		} else {
			if _, err := u.Command(ctx, "rc-service", "cloudflared", "status"); err == nil {
				st.Running = true
			}
		}
	}
	return st
}

func (u *Updater) pollTunnel() (*TunnelJob, error) {
	st := u.tunnelStatus()
	installed := "0"
	if st.Installed {
		installed = "1"
	}
	running := "0"
	if st.Running {
		running = "1"
	}
	var out struct {
		Job *TunnelJob `json:"job"`
	}
	q := url.Values{
		"machine_id":        {strconv.Itoa(u.Binding.Machine)},
		"arch":              {runtime.GOARCH},
		"installed":         {installed},
		"running":           {running},
		"version":           {st.Version},
		"assistant_version": {assistantVersion},
	}
	e := u.api("GET", "/api/v3/server/tunnel/poll?"+q.Encode(), nil, &out)
	return out.Job, e
}

func (u *Updater) reportTunnel(j TunnelJob, state, code string) error {
	st := u.tunnelStatus()
	return u.api("POST", fmt.Sprintf("/api/v3/server/tunnel/%s/status", j.ID), map[string]any{
		"machine_id": u.Binding.Machine,
		"lease":      j.Lease,
		"state":      state,
		"installed":  st.Installed,
		"running":    st.Running,
		"version":    st.Version,
		"error_code": code,
	}, nil)
}

func (u *Updater) downloadCloudflared() error {
	arch := runtime.GOARCH
	if arch != "amd64" && arch != "arm64" {
		return errors.New("unsupported")
	}
	base := u.CloudflaredBase
	if base == "" {
		base = cloudflaredBase
	}
	req, err := http.NewRequest("GET", base+"cloudflared-linux-"+arch, nil)
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
	target := u.cloudflaredBin()
	tmp := target + ".new"
	f, err := os.OpenFile(tmp, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0755)
	if err != nil {
		return err
	}
	defer os.Remove(tmp)
	if _, err = io.Copy(f, io.LimitReader(res.Body, 128<<20)); err != nil {
		f.Close()
		return err
	}
	if err = f.Chmod(0755); err != nil {
		f.Close()
		return err
	}
	if err = f.Sync(); err != nil {
		f.Close()
		return err
	}
	if err = f.Close(); err != nil {
		return err
	}
	if err = os.Rename(tmp, target); err != nil {
		return err
	}
	return syncDir(filepath.Dir(target))
}

func (u *Updater) executeTunnel(j TunnelJob) error {
	if !jobRE.MatchString(j.ID) || len(j.Lease) != 64 {
		return errors.New("invalid tunnel task")
	}
	if err := u.reportTunnel(j, "running", ""); err != nil {
		return err
	}
	ctx, cancel := context.WithTimeout(context.Background(), 120*time.Second)
	defer cancel()

	switch j.Action {
	case "install":
		if !tunnelTokenRE.MatchString(j.Token) {
			return u.reportTunnel(j, "failed", "invalid_token")
		}
		if err := u.downloadCloudflared(); err != nil {
			return u.reportTunnel(j, "failed", "download_failed")
		}
		if u.Manager == "systemd" {
			_, _ = u.Command(ctx, u.cloudflaredBin(), "service", "uninstall")
			if _, err := u.Command(ctx, u.cloudflaredBin(), "service", "install", j.Token); err != nil {
				return u.reportTunnel(j, "failed", "install_failed")
			}
			_, _ = u.Command(ctx, "systemctl", "enable", "--now", "cloudflared.service")
			if _, err := u.Command(ctx, "systemctl", "restart", "cloudflared.service"); err != nil {
				return u.reportTunnel(j, "failed", "service_failed")
			}
		} else {
			return u.reportTunnel(j, "failed", "unsupported")
		}
		if !u.tunnelStatus().Running {
			return u.reportTunnel(j, "failed", "service_failed")
		}
		return u.reportTunnel(j, "succeeded", "")

	case "restart":
		var err error
		if u.Manager == "systemd" {
			_, err = u.Command(ctx, "systemctl", "restart", "cloudflared.service")
		} else {
			_, err = u.Command(ctx, "rc-service", "cloudflared", "restart")
		}
		if err != nil || !u.tunnelStatus().Running {
			return u.reportTunnel(j, "failed", "service_failed")
		}
		return u.reportTunnel(j, "succeeded", "")

	case "uninstall":
		if u.Manager == "systemd" {
			_, _ = u.Command(ctx, "systemctl", "disable", "--now", "cloudflared.service")
			_, _ = u.Command(ctx, u.cloudflaredBin(), "service", "uninstall")
		} else {
			_, _ = u.Command(ctx, "rc-service", "cloudflared", "stop")
		}
		if err := os.Remove(u.cloudflaredBin()); err != nil && !errors.Is(err, os.ErrNotExist) {
			return u.reportTunnel(j, "failed", "uninstall_failed")
		}
		return u.reportTunnel(j, "succeeded", "")

	default:
		return u.reportTunnel(j, "failed", "invalid_task")
	}
}
