package nlog

import (
	"bytes"
	"context"
	"encoding/json"
	"log/slog"
	"strings"
	"testing"
)

func TestRuntimePrivacyBeforeLocalAndUpload(t *testing.T) {
	var out bytes.Buffer
	Init(&out, slog.LevelDebug, false)
	ResetRuntime()
	ForNode("vless", 34876).Info("config updated, 5 users")
	Core().Info("xray: users added via UserManager", "added", 4)
	Core().Info("started 2 nodes: vless:34876")
	Core().Warn("listener failed", "node_id", 982341, "error", "listen tcp 192.0.2.44:34876: bind: address already in use", "token", "secret-forbidden")
	if strings.Contains(out.String(), "config updated") || strings.Contains(out.String(), "UserManager") || strings.Contains(out.String(), "started 2") {
		t.Fatal("configuration success reached local sink")
	}
	b := PendingRuntime()
	raw, _ := json.Marshal(b)
	for _, forbidden := range []string{"34876", "982341", "192.0.2.44", "secret-forbidden"} {
		if strings.Contains(out.String()+string(raw), forbidden) {
			t.Fatalf("private value reached sink: %s", forbidden)
		}
	}
	if !strings.Contains(out.String(), "address already in use") || !strings.Contains(string(raw), "address already in use") {
		t.Fatal("useful error reason lost")
	}
	if len(b.Entries) != 1 {
		t.Fatalf("entries=%d", len(b.Entries))
	}
}
func TestRuntimeBoundedRetryAndDrop(t *testing.T) {
	Init(&bytes.Buffer{}, slog.LevelDebug, false)
	ResetRuntime()
	for i := 0; i < 3000; i++ {
		Core().Warn("certificate renewal failed: connection refused")
	}
	a := PendingRuntime()
	b := PendingRuntime()
	if len(a.Entries) > 1000 || len(a.Entries) == 0 || a.BatchID != b.BatchID || a.Dropped == 0 {
		t.Fatalf("invalid bounded retry: entries=%d dropped=%d", len(a.Entries), a.Dropped)
	}
	raw, _ := json.Marshal(a)
	if len(raw) > 256<<10 {
		t.Fatal("batch exceeds cap")
	}
	AcknowledgeRuntime(a.BatchID)
	c := PendingRuntime()
	if len(c.Entries) > 0 {
		t.Fatal("acknowledged entries retained")
	}
}
func TestRuntimeWriterSanitizesKernelAndMultiline(t *testing.T) {
	var out bytes.Buffer
	Init(&out, slog.LevelDebug, false)
	ResetRuntime()
	w := RuntimeWriter{}
	w.Write([]byte("ERROR dial tcp 198.51.100.2:55123: connection refused password=secret-value\nINFO config pushed users=22\n"))
	if strings.Contains(out.String(), "198.51") || strings.Contains(out.String(), "secret-value") || strings.Contains(out.String(), "config pushed") {
		t.Fatal(out.String())
	}
	if !strings.Contains(out.String(), "connection refused") {
		t.Fatal(out.String())
	}
	_ = context.Background()
}

func TestRuntimePreservesGenericStartupAndBraceError(t *testing.T) {
	var out bytes.Buffer
	Init(&out, slog.LevelDebug, false)
	ResetRuntime()
	Core().Info("runtime starting")
	Core().Warn("users updated successfully")
	Core().Error(`certificate failed {"token":"private-sentinel"}: connection refused`)
	if !strings.Contains(out.String(), "runtime starting") || !strings.Contains(out.String(), "connection refused") || strings.Contains(out.String(), "private-sentinel") || strings.Contains(out.String(), "users updated") {
		t.Fatal(out.String())
	}
}

func TestRuntimeStructuredHandlerKeepsReasons(t *testing.T) {
	var out bytes.Buffer
	Init(&out, slog.LevelDebug, false)
	ResetRuntime()
	logger := slog.New(RuntimeHandler{})
	logger.Error("certificate renewal failed", "error", "connection refused", "token", "private-sentinel")
	if !strings.Contains(out.String(), "connection refused") || strings.Contains(out.String(), "private-sentinel") {
		t.Fatal(out.String())
	}
}

func TestRuntimeSuccessSubjectsInFieldsNeverReachLocalOrUpload(t *testing.T) {
	var out bytes.Buffer
	Init(&out, slog.LevelDebug, false)
	ResetRuntime()
	Core().Info("initial snapshot ready", "users", 8, "local_nodes", 3)
	Core().Info("xray started", "ports", []int{443, 25051}, "users", 8)
	Core().Warn("snapshot ready", "users", 8)
	if out.Len() != 0 || len(PendingRuntime().Entries) != 0 {
		t.Fatal("structured configuration success leaked", out.String())
	}
	Core().Error("runtime check", "users", 8, "error", "connection refused")
	if !strings.Contains(out.String(), "connection refused") {
		t.Fatal("diagnostic reason lost")
	}
}

func TestRuntimeLibrarySuccessSeverityDoesNotBypassSuppression(t *testing.T) {
	var out bytes.Buffer
	Init(&out, slog.LevelDebug, false)
	ResetRuntime()
	w := RuntimeWriter{}
	for _, line := range []string{"WARN config updated users=5", "[Error] users added successfully", "ERROR listener started port=45234"} {
		w.Write([]byte(line + "\n"))
	}
	if out.Len() != 0 {
		t.Fatal("library severity bypassed success suppression: " + out.String())
	}
}
