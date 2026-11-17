package main

// Test the UUID/string shape emitted by UpgradeService::lease, rather than only
// constructing jobs through Go values.
import (
	"encoding/json"
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestPHPWireUUID(t *testing.T) {
	h := newHarness(t)
	raw := `{"job":{"id":"74b860e8-212f-4d7b-a651-96b916831c8c","lease":"lease-1234567890123456","expires_at":1700000900,"release":{"version":"v0.1.12","arch":"amd64","sha256":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","url":"https://github.com/chinahch/onenew-releases/releases/download/node-v0.1.12/onenew-node-linux-amd64.tar.gz"}}}`
	var result struct {
		Job *Job `json:"job"`
	}
	if e := json.Unmarshal([]byte(raw), &result); e != nil || result.Job == nil || result.Job.ID != "74b860e8-212f-4d7b-a651-96b916831c8c" {
		t.Fatal("PHP UUID incompatible", e)
	}
	if e := h.u.report(*result.Job, "failed", "invalid_task"); e != nil {
		t.Fatal(e)
	}
}
func TestNativeInstanceBindingAndAmbiguity(t *testing.T) {
	root := t.TempDir()
	cfg := "instances:\n  - id: native\n    panel:\n      url: https://panel.example\n    machine:\n      machine_id: 9\n      token_env: NATIVE_TOKEN\n"
	writeTest(t, filepath.Join(root, "config.yml"), cfg)
	writeTest(t, filepath.Join(root, "credentials.env"), "NATIVE_TOKEN=abcdefghijklmnop1234\n")
	b, e := readBinding(root)
	if e != nil || b.Machine != 9 {
		t.Fatal("real native config rejected", e)
	}
	writeTest(t, filepath.Join(root, "config.yml"), cfg+"  - panel:\n      url: https://other.example\n    machine:\n      machine_id: 10\n      token: abcdefghijklmnop1234\n")
	if _, e = readBinding(root); e == nil {
		t.Fatal("ambiguous instances accepted")
	}
}
func TestExpiredTerminalReportReleasesJournal(t *testing.T) {
	h := newHarness(t)
	j := h.job("v0.1.12")
	j.Expires = h.u.Now().Add(-time.Minute).Unix()
	h.u.save(Journal{Job: j, Phase: "result", OldVersion: "v0.1.11", Result: "rolled_back", Error: "interrupted"})
	h.server.Config.Handler = httpHandler409{}
	if e := h.u.recover(); e != nil {
		t.Fatal(e)
	}
	if _, e := os.Stat(filepath.Join(h.u.stateDir(), "journal.json")); !os.IsNotExist(e) {
		t.Fatal("expired terminal journal permanently blocked")
	}
}
