// Package onenewaudit reports only hostname metadata from admitted proxy
// connections. It never captures URLs, payloads, source IPs or credentials.
package onenewaudit

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"errors"
	"io"
	"net"
	"net/http"
	"net/url"
	"strings"
	"sync/atomic"
	"time"
)

type Event struct {
	NodeID     int    `json:"-"`
	UserID     int    `json:"user_id"`
	Domain     string `json:"domain"`
	ObservedAt int64  `json:"observed_at"`
}
type batch struct {
	MachineID int     `json:"machine_id"`
	NodeID    int     `json:"node_id"`
	BatchID   string  `json:"batch_id"`
	Events    []Event `json:"events"`
}
type agent struct {
	base, token string
	machine     int
	client      *http.Client
	enabled     atomic.Bool
	dropped     atomic.Uint64
	events      chan Event
	pending     []batch
}

var current atomic.Pointer[agent]

type statusError int

func (s statusError) Error() string { return "audit endpoint unavailable" }

func validBase(raw string) bool {
	u, e := url.Parse(raw)
	if e != nil || u.Host == "" || u.User != nil || u.RawQuery != "" || u.Fragment != "" {
		return false
	}
	if u.Scheme == "https" {
		return true
	}
	ip := net.ParseIP(u.Hostname())
	return u.Scheme == "http" && (u.Hostname() == "localhost" || (ip != nil && ip.IsLoopback()))
}
func newAgent(base, token string, machine int) *agent {
	return &agent{base: strings.TrimRight(base, "/"), token: token, machine: machine, events: make(chan Event, 512),
		client: &http.Client{Timeout: 8 * time.Second, CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse }}}
}

// Run is machine-scoped. Collection remains off until the authenticated panel
// explicitly enables it. Cancellation discards memory; there is no disk spool.
func Run(ctx context.Context, base, token string, machine int) {
	if !validBase(base) || token == "" || machine < 1 {
		return
	}
	a := newAgent(base, token, machine)
	current.Store(a)
	defer func() { a.enabled.Store(false); current.CompareAndSwap(a, nil) }()
	timer := time.NewTicker(10 * time.Second)
	defer timer.Stop()
	for {
		a.tick(ctx)
		select {
		case <-ctx.Done():
			return
		case <-timer.C:
		}
	}
}

// Record never blocks traffic. Queue overflow drops audit metadata only.
func Record(node, user int, domain string) {
	if a := current.Load(); a != nil {
		a.record(node, user, domain)
	}
}
func normalize(raw string) string {
	s := strings.ToLower(strings.TrimSuffix(strings.TrimSpace(raw), "."))
	if len(s) < 3 || len(s) > 253 || net.ParseIP(s) != nil || !strings.Contains(s, ".") {
		return ""
	}
	for _, part := range strings.Split(s, ".") {
		if len(part) < 1 || len(part) > 63 || part[0] == '-' || part[len(part)-1] == '-' {
			return ""
		}
		for _, c := range part {
			if !(c >= 'a' && c <= 'z' || c >= '0' && c <= '9' || c == '-') {
				return ""
			}
		}
	}
	return s
}
func (a *agent) record(node, user int, raw string) {
	if !a.enabled.Load() || node < 1 || user < 1 {
		return
	}
	domain := normalize(raw)
	if domain == "" {
		return
	}
	select {
	case a.events <- Event{NodeID: node, UserID: user, Domain: domain, ObservedAt: time.Now().Unix()}:
	default:
		a.dropped.Add(1)
	}
}
func (a *agent) request(ctx context.Context, method, path string, body any) (*http.Response, error) {
	var r io.Reader
	if body != nil {
		b, e := json.Marshal(body)
		if e != nil {
			return nil, errors.New("audit encoding failed")
		}
		r = bytes.NewReader(b)
	}
	req, e := http.NewRequestWithContext(ctx, method, a.base+path, r)
	if e != nil {
		return nil, errors.New("audit request failed")
	}
	req.Header.Set("Authorization", "Bearer "+a.token)
	req.Header.Set("Content-Type", "application/json")
	resp, e := a.client.Do(req)
	if e != nil {
		return nil, errors.New("audit transport failed")
	}
	if resp.StatusCode != 200 {
		resp.Body.Close()
		return nil, statusError(resp.StatusCode)
	}
	return resp, nil
}
func newID() string {
	var b [16]byte
	if _, e := rand.Read(b[:]); e != nil {
		return ""
	}
	b[6] = (b[6] & 15) | 64
	b[8] = (b[8] & 63) | 128
	s := hex.EncodeToString(b[:])
	return s[:8] + "-" + s[8:12] + "-" + s[12:16] + "-" + s[16:20] + "-" + s[20:]
}
func (a *agent) tick(ctx context.Context) {
	resp, e := a.request(ctx, "GET", "/api/v3/server/domain-audit/config?machine_id="+itoa(a.machine), nil)
	if e != nil {
		a.enabled.Store(false)
		return
	}
	var config struct {
		Enabled   bool `json:"enabled"`
		Retention int  `json:"retention_days"`
	}
	e = json.NewDecoder(io.LimitReader(resp.Body, 4096)).Decode(&config)
	resp.Body.Close()
	if e != nil || config.Retention < 1 || config.Retention > 365 {
		a.enabled.Store(false)
		return
	}
	a.enabled.Store(config.Enabled)
	if !config.Enabled {
		a.pending = nil
		for len(a.events) > 0 {
			<-a.events
		}
		return
	}
	if len(a.pending) == 0 {
		grouped := make(map[int][]Event)
		// Snapshot once: concurrent producers must not extend this drain indefinitely.
		available := len(a.events)
		for i := 0; i < available; i++ {
			e := <-a.events
			grouped[e.NodeID] = append(grouped[e.NodeID], e)
		}
		for node, events := range grouped {
			for len(events) > 0 {
				n := len(events)
				if n > 200 {
					n = 200
				}
				id := newID()
				if id == "" {
					return
				}
				a.pending = append(a.pending, batch{a.machine, node, id, events[:n]})
				events = events[n:]
			}
		}
	}
	for len(a.pending) > 0 {
		p := a.pending[0]
		if len(p.Events) == 0 || time.Now().Unix()-p.Events[0].ObservedAt > int64(config.Retention)*86400 {
			a.pending = a.pending[1:]
			continue
		}
		r, e := a.request(ctx, "POST", "/api/v3/server/domain-audit", p)
		if e != nil {
			var status statusError
			if errors.As(e, &status) && status >= 400 && status < 500 && status != 429 {
				a.dropped.Add(uint64(len(p.Events)))
				a.pending = a.pending[1:]
				continue
			}
			return
		}
		r.Body.Close()
		a.pending = a.pending[1:]
	}
}
func itoa(n int) string {
	if n == 0 {
		return "0"
	}
	var b [20]byte
	i := len(b)
	for n > 0 {
		i--
		b[i] = byte('0' + n%10)
		n /= 10
	}
	return string(b[i:])
}
