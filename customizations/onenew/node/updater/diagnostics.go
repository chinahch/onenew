package main

import (
	"errors"
	"os"
	"syscall"
)

// Never send operating system paths, API URLs, leases or raw errors to logs.
type journalWriteError struct{ cause error }

func (e journalWriteError) Error() string { return "journal_write_failed" }
func (e journalWriteError) Unwrap() error { return e.cause }

func operationErrorCode(e error) string {
	var persistence journalWriteError
	if errors.As(e, &persistence) {
		if errors.Is(e, syscall.ENOSPC) {
			return "journal_disk_full"
		}
		if errors.Is(e, os.ErrPermission) {
			return "journal_permission_denied"
		}
		return "journal_write_failed"
	}
	if errors.Is(e, ErrUnauthorized) {
		return "authorization_rejected"
	}
	if errors.Is(e, ErrExpired) {
		return "lease_rejected"
	}
	switch e.Error() {
	case "panel request failed":
		return "panel_request_failed"
	case "panel request rejected":
		return "panel_request_rejected"
	case "invalid recovery journal":
		return "invalid_recovery_journal"
	case "manual recovery required":
		return "rollback_failed"
	}
	return "internal_error"
}
