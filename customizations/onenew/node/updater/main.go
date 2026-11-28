package main

import (
	"errors"
	"flag"
	"fmt"
	"os"
	"path/filepath"
	"time"
)

func main() {
	if len(os.Args) < 2 {
		fmt.Fprintln(os.Stderr, "usage: caddy-updater binding|run")
		os.Exit(2)
	}
	fs := flag.NewFlagSet(os.Args[1], flag.ExitOnError)
	root := fs.String("root", "/etc/caddy", "")
	manager := fs.String("manager", "systemd", "")
	field := fs.String("field", "", "")
	fs.Parse(os.Args[2:])
	if os.Args[1] == "binding" {
		b, e := readBinding(*root)
		if e != nil {
			fmt.Fprintln(os.Stderr, "Cannot read native machine binding")
			os.Exit(1)
		}
		switch *field {
		case "panel":
			fmt.Println(b.Panel)
		case "machine":
			fmt.Println(b.Machine)
		case "token":
			fmt.Println(b.Token)
		default:
			os.Exit(2)
		}
		return
	}
	if os.Args[1] != "run" {
		os.Exit(2)
	}
	u, e := newUpdater(*root, *manager)
	if e != nil {
		fmt.Fprintln(os.Stderr, "Updater configuration rejected")
		os.Exit(1)
	}
	unlock, e := lock(filepath.Join(u.Root, "updater.lock"))
	if e != nil {
		fmt.Fprintln(os.Stderr, "Updater already running")
		os.Exit(1)
	}
	defer unlock()
	for {
		operation := "recovery"
		if e = u.recover(); e == nil {
			u.recoveryReason = ""
			operation = "poll"
			var j *Job
			j, e = u.poll()
			if e == nil && j != nil {
				operation = "execute"
				e = u.execute(*j)
				var persistence journalWriteError
				if errors.As(e, &persistence) {
					u.recoveryReason = "journal_failed"
				}
				if e == nil {
					if su := u.selfUpdate(j.Release); errors.Is(su, ErrSelfUpdated) {
						fmt.Fprintln(os.Stderr, "Updater self-updated; exiting for service manager restart")
						return
					} else if su != nil {
						fmt.Fprintf(os.Stderr, "Updater operation=self_update error=%s; retaining current assistant\n", operationErrorCode(su))
					}
				}
			}
			if e == nil {
				operation = "tunnel_poll"
				var tj *TunnelJob
				tj, e = u.pollTunnel()
				if e == nil && tj != nil {
					operation = "tunnel_execute"
					e = u.executeTunnel(*tj)
				}
			}
			if e == nil {
				operation = "self_steal_poll"
				var sj *SelfStealJob
				sj, e = u.pollSelfSteal()
				if e == nil && sj != nil {
					operation = "self_steal_execute"
					e = u.executeSelfSteal(*sj)
				}
			}
		}
		if errors.Is(e, ErrUnauthorized) {
			fmt.Fprintln(os.Stderr, "Updater disabled: machine authorization rejected")
			os.Exit(1)
		}
		if e != nil {
			fmt.Fprintf(os.Stderr, "Updater operation=%s error=%s; state retained\n", operation, operationErrorCode(e))
		}
		u.Sleep(15 * time.Second)
	}
}
