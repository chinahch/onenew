package main

import (
	"context"
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"time"
)

func (u *Updater) rollback(j *Journal) bool {
	u.manager("stop")
	ok := true
	for _, name := range []string{"caddy", "caddyctl"} {
		if e := copyFile(filepath.Join(u.stateDir(), name+".previous"), filepath.Join(u.Bin, name), 0755); e != nil {
			ok = false
		}
	}
	if u.manager("start") != nil {
		ok = false
	}
	if ok {
		ok = u.waitHealthy(u.Now().Unix() + 95)
	}
	j.Result = "rolled_back"
	j.Error = "health_failed"
	if !ok {
		j.Result = "failed"
		j.Error = "rollback_failed"
	}
	j.Phase = "result"
	u.save(*j)
	return ok
}
func (u *Updater) cleanup() {
	for _, n := range []string{"caddy.previous", "caddyctl.previous", "caddy.candidate", "caddyctl.candidate", "package.tar.gz", "checksums", "journal.json"} {
		os.Remove(filepath.Join(u.stateDir(), n))
	}
	syncDir(u.stateDir())
}
func (u *Updater) recover() error {
	b, e := regular(filepath.Join(u.stateDir(), "journal.json"), true, 1048576)
	if os.IsNotExist(e) {
		return nil
	}
	if e != nil {
		return e
	}
	var j Journal
	if json.Unmarshal(b, &j) != nil || !jobRE.MatchString(j.Job.ID) || len(j.Job.Lease) < 16 {
		return errors.New("invalid recovery journal")
	}
	if j.Phase == "mutating" {
		u.rollback(&j)
		if j.Error != "rollback_failed" {
			j.Error = "interrupted"
			if e = u.save(j); e != nil {
				return e
			}
		}
	}
	if j.Phase != "result" {
		j.Phase = "result"
		j.Result = "failed"
		j.Error = "interrupted"
		if e = u.save(j); e != nil {
			return e
		}
	}
	if e = u.report(j.Job, j.Result, j.Error); e != nil {
		if !(errors.Is(e, ErrExpired) && u.Now().Unix() > j.Job.Expires && j.Error != "rollback_failed") {
			return e
		}
	}
	if j.Error == "rollback_failed" {
		return errors.New("manual recovery required")
	}
	u.cleanup()
	return nil
}
func (u *Updater) execute(j Job) error {
	if e := u.validate(j); e != nil {
		u.report(j, "failed", "invalid_task")
		return e
	}
	journal := Journal{Job: j, Phase: "preparing", OldVersion: u.version()}
	if e := u.save(journal); e != nil {
		return e
	}
	finish := func(state, code string) error {
		journal.Phase = "result"
		journal.Result = state
		journal.Error = code
		if e := u.save(journal); e != nil {
			return e
		}
		if e := u.report(j, state, code); e != nil {
			return e
		}
		u.cleanup()
		return nil
	}
	if e := u.report(j, "downloading", ""); e != nil {
		return finish("failed", "download_failed")
	}
	if e := u.download(j); e != nil {
		return finish("failed", "verification_failed")
	}
	if e := u.report(j, "verifying", ""); e != nil {
		return finish("failed", "internal_error")
	}
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	out, e := u.Command(ctx, filepath.Join(u.stateDir(), "caddy.candidate"), "-v")
	cancel()
	if e != nil || normalizeVersion(string(out)) != j.Release.Version {
		return finish("failed", "verification_failed")
	}
	if u.Now().Unix() > j.Expires-360 {
		return finish("failed", "expired")
	}
	for _, name := range []string{"caddy", "caddyctl"} {
		if e := copyFile(filepath.Join(u.Bin, name), filepath.Join(u.stateDir(), name+".previous"), 0755); e != nil {
			return finish("failed", "install_failed")
		}
	}
	if e := u.report(j, "installing", ""); e != nil {
		return finish("failed", "internal_error")
	}
	journal.Phase = "mutating"
	if e := u.save(journal); e != nil {
		return e
	}
	bad := u.manager("stop") != nil
	for _, name := range []string{"caddy", "caddyctl"} {
		if !bad {
			bad = copyFile(filepath.Join(u.stateDir(), name+".candidate"), filepath.Join(u.Bin, name), 0755) != nil
		}
	}
	if !bad {
		bad = u.manager("start") != nil
	}
	if !bad {
		u.report(j, "checking", "")
		bad = !u.waitHealthy(j.Expires-180) || u.version() != j.Release.Version
	}
	if bad {
		u.rollback(&journal)
		if e := u.report(j, journal.Result, journal.Error); e != nil {
			return e
		}
		if journal.Error == "rollback_failed" {
			return errors.New("manual recovery required")
		}
		u.cleanup()
		return nil
	}
	return finish("succeeded", "")
}
