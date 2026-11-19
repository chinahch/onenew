package main

import (
	"encoding/json"
	"errors"
	"os"
	"path/filepath"
	"strings"
	"syscall"
	"testing"
)

func TestOperationDiagnosticsNeverLogRawPrivateErrors(t *testing.T) {
	for _, tc := range []struct {
		err  error
		code string
	}{
		{journalWriteError{syscall.ENOSPC}, "journal_disk_full"},
		{journalWriteError{os.ErrPermission}, "journal_permission_denied"},
		{errors.New("secret-token https://private.example/lease"), "internal_error"},
	} {
		if got := operationErrorCode(tc.err); got != tc.code {
			t.Fatalf("got %s want %s", got, tc.code)
		}
	}
}

func TestJournalFailureIsClassifiedWithoutLeakingPath(t *testing.T) {
	h := newHarness(t)
	if e := os.Mkdir(filepath.Join(h.u.stateDir(), "journal.json.new"), 0700); e != nil {
		t.Fatal(e)
	}
	e := h.u.save(Journal{Job: h.job("v0.1.12"), Phase: "preparing"})
	if e == nil || e.Error() != "journal_write_failed" {
		t.Fatalf("unclassified journal failure: %v", e)
	}
	if strings.Contains(e.Error(), h.u.Root) {
		t.Fatal("private path leaked")
	}
}

func TestSameProcessJournalFailureIsNotCalledInterrupted(t *testing.T) {
	h := newHarness(t)
	if e := h.u.save(Journal{Job: h.job("v0.1.12"), Phase: "preparing", OldVersion: "v0.1.11"}); e != nil {
		t.Fatal(e)
	}
	h.reject = true
	// A save failure in a live process is known; it is not evidence of a crash.
	h.u.recoveryReason = "journal_failed"
	if h.u.recover() == nil {
		t.Fatal("expected deferred report")
	}
	b, e := os.ReadFile(filepath.Join(h.u.stateDir(), "journal.json"))
	if e != nil {
		t.Fatal(e)
	}
	var j Journal
	if e = json.Unmarshal(b, &j); e != nil {
		t.Fatal(e)
	}
	if j.Error != "journal_failed" || j.Phase != "result" {
		t.Fatalf("incorrect cause: %+v", j)
	}
	h.reject = false
	if e = h.u.recover(); e != nil {
		t.Fatal(e)
	}
}
