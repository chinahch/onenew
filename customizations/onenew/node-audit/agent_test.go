package onenewaudit

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestMetadataValidationAndNonblockingBound(t *testing.T) {
	a := newAgent("https://panel.example", "fixture", 1)
	a.enabled.Store(true)
	for _, target := range []string{"https://private.example/path", "192.0.2.1", "example.org/path", "bad label.test", "bad..test", "user@example.org", "abc:443"} {
		a.record(1, 2, target)
	}
	if len(a.events) != 0 {
		t.Fatal("recorded URL, IP or invalid hostname")
	}
	a.record(1, 2, "EXAMPLE.ORG.")
	e := <-a.events
	if e.Domain != "example.org" || e.UserID != 2 || e.NodeID != 1 {
		t.Fatalf("bad metadata: %+v", e)
	}
	for i := 0; i < 1000; i++ {
		a.record(1, 2, "example.org")
	}
	if len(a.events) != 512 || a.dropped.Load() != 488 {
		t.Fatal("queue not bounded")
	}
}

func TestDisabledConfigIdentityAndExactRetry(t *testing.T) {
	enabled := false
	reject := true
	var bodies []string
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer fake-private-token" {
			t.Error("missing bearer")
		}
		if strings.Contains(r.URL.String(), "fake-private") {
			t.Error("token in URL")
		}
		if r.Method == "GET" {
			if r.URL.Query().Get("machine_id") != "7" {
				t.Error("machine identity")
			}
			json.NewEncoder(w).Encode(map[string]any{"enabled": enabled, "retention_days": 7})
			return
		}
		var body json.RawMessage
		json.NewDecoder(r.Body).Decode(&body)
		bodies = append(bodies, string(body))
		if strings.Contains(string(body), "https://") || strings.Contains(string(body), "token") {
			t.Error("sensitive payload")
		}
		if reject {
			w.WriteHeader(503)
			return
		}
		w.WriteHeader(200)
	}))
	defer srv.Close()
	a := newAgent(srv.URL, "fake-private-token", 7)
	a.record(4, 2, "example.org")
	a.tick(context.Background())
	if len(bodies) != 0 {
		t.Fatal("disabled recorder emitted data")
	}
	enabled = true
	a.tick(context.Background())
	a.record(4, 2, "example.org")
	a.tick(context.Background())
	if len(bodies) != 1 {
		t.Fatal("expected one failed send")
	}
	reject = false
	a.tick(context.Background())
	if len(bodies) != 2 || bodies[0] != bodies[1] {
		t.Fatal("retry must keep identical batch UUID and events")
	}
	enabled = false
	a.tick(context.Background())
	a.record(4, 2, "example.org")
	if len(a.events) != 0 || a.enabled.Load() {
		t.Fatal("disabled config did not stop collection")
	}
}

func TestRedirectRefusedAndSecureEndpoint(t *testing.T) {
	for _, u := range []string{"http://public.example", "https://user:password@panel.example", "https://panel.example/?token=bad"} {
		if validBase(u) {
			t.Fatal("unsafe endpoint", u)
		}
	}
	hit := false
	target := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { hit = true }))
	defer target.Close()
	first := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) { http.Redirect(w, r, target.URL, 302) }))
	defer first.Close()
	a := newAgent(first.URL, "fixture", 1)
	a.tick(context.Background())
	if hit || a.enabled.Load() {
		t.Fatal("followed redirect or enabled invalid config")
	}
}

func TestRemovedNodeDoesNotBlockOtherNodes(t *testing.T) {
	sent := 0
	srv := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method == "GET" {
			json.NewEncoder(w).Encode(map[string]any{"enabled": true, "retention_days": 7})
			return
		}
		var b batch
		json.NewDecoder(r.Body).Decode(&b)
		if b.NodeID == 1 {
			w.WriteHeader(403)
			return
		}
		sent++
		w.WriteHeader(200)
	}))
	defer srv.Close()
	a := newAgent(srv.URL, "fixture", 1)
	a.record(1, 1, "example.org") // disabled, no event
	a.enabled.Store(true)
	a.record(1, 1, "example.org")
	event := <-a.events
	a.pending = []batch{{1, 1, newID(), []Event{event}}, {1, 2, newID(), []Event{event}}}
	a.tick(context.Background())
	if sent != 1 || len(a.pending) != 0 {
		t.Fatal("removed node blocked the healthy node")
	}
}
