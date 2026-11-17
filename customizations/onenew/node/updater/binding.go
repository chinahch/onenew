package main

import (
	"errors"
	"gopkg.in/yaml.v3"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"runtime"
	"strings"
)

type Binding struct {
	Panel   string
	Machine int
	Token   string
}

var tokenRE = regexp.MustCompile(`^[A-Za-z0-9_+./=~\-]{16,256}$`)
var envRE = regexp.MustCompile(`^[A-Z_][A-Z0-9_]*$`)

func regular(path string, private bool, limit int64) ([]byte, error) {
	st, e := os.Lstat(path)
	if e != nil {
		return nil, e
	}
	if !st.Mode().IsRegular() || st.Size() > limit || (private && runtime.GOOS != "windows" && st.Mode().Perm()&0077 != 0) {
		return nil, errors.New("invalid file")
	}
	return os.ReadFile(path)
}
func readBinding(root string) (Binding, error) {
	var b Binding
	st, e := os.Lstat(root)
	if e != nil || !st.IsDir() || st.Mode()&os.ModeSymlink != 0 {
		return b, errors.New("invalid root")
	}
	data, e := regular(filepath.Join(root, "config.yml"), true, 1048576)
	if e != nil {
		return b, e
	}
	type machine struct {
		ID    int    `yaml:"machine_id"`
		Token string `yaml:"token"`
		Env   string `yaml:"token_env"`
	}
	type instance struct {
		Panel struct {
			URL    string `yaml:"url"`
			NodeID int    `yaml:"node_id"`
		} `yaml:"panel"`
		Machine    *machine `yaml:"machine"`
		Nodes      []any    `yaml:"nodes"`
		Standalone any      `yaml:"standalone"`
	}
	var document struct {
		instance  `yaml:",inline"`
		Instances []instance `yaml:"instances"`
	}
	if yaml.Unmarshal(data, &document) != nil {
		return b, errors.New("invalid configuration")
	}
	cfg := document.instance
	if len(document.Instances) > 0 {
		if len(document.Instances) != 1 || cfg.Machine != nil || cfg.Panel.URL != "" {
			return b, errors.New("ambiguous configuration")
		}
		cfg = document.Instances[0]
	}
	if cfg.Machine == nil || cfg.Machine.ID < 1 || len(cfg.Nodes) > 0 || cfg.Standalone != nil || cfg.Panel.NodeID != 0 {
		return b, errors.New("invalid binding")
	}
	p, e := url.Parse(cfg.Panel.URL)
	if e != nil || p.Scheme != "https" || p.Host == "" || p.User != nil || p.RawQuery != "" || p.Fragment != "" || strings.ContainsAny(cfg.Panel.URL, "\r\n\t ") {
		return b, errors.New("invalid panel")
	}
	b = Binding{strings.TrimRight(cfg.Panel.URL, "/"), cfg.Machine.ID, cfg.Machine.Token}
	if cfg.Machine.Env != "" {
		if !envRE.MatchString(cfg.Machine.Env) {
			return b, errors.New("invalid credential name")
		}
		data, e = regular(filepath.Join(root, "credentials.env"), true, 1048576)
		if e != nil {
			return b, e
		}
		count := 0
		for _, line := range strings.Split(string(data), "\n") {
			line = strings.TrimSuffix(line, "\r")
			if strings.HasPrefix(line, cfg.Machine.Env+"=") {
				b.Token = strings.TrimPrefix(line, cfg.Machine.Env+"=")
				count++
			}
		}
		if count != 1 {
			return b, errors.New("ambiguous credential")
		}
	}
	if !tokenRE.MatchString(b.Token) {
		return b, errors.New("invalid credential")
	}
	return b, nil
}
