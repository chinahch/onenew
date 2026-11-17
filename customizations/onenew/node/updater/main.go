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
		if e = u.recover(); e == nil {
			var j *Job
			j, e = u.poll()
			if e == nil && j != nil {
				e = u.execute(*j)
			}
		}
		if errors.Is(e, ErrUnauthorized) {
			fmt.Fprintln(os.Stderr, "Updater disabled: machine authorization rejected")
			os.Exit(1)
		}
		if e != nil {
			fmt.Fprintln(os.Stderr, "Updater operation deferred; state retained")
		}
		u.Sleep(15 * time.Second)
	}
}
