# Native upgrade assistant

`caddy-updater run --root /etc/caddy --manager systemd` (or `openrc`) runs as
root under its own OS service, independently of caddy. It holds an advisory
exclusive lock on `/etc/caddy/updater.lock` for its lifetime. A manual installer
must refuse while that lock is held or a recovery journal exists.

For helpers installed before v0.1.16, bootstrap the corrected helper once:
`bash <(curl -fsSL https://get.timi.baby/upgrade) --refresh-assistant`.
This downloads and verifies the replacement first, stops only the upgrade
assistant, checks its lock and journal, and replaces/restarts only the helper.
It does not replace or stop caddy, and does not change node credentials.
Pending journals are refused and the old helper is restarted for recovery;
never delete a journal or lock to bypass it. Future core upgrades use the panel.

Archive extraction and executable copies use bounded streaming writes, synced
private temporary files and atomic renames instead of whole-file allocations.
CI checks a realistic 64 MiB executable inside a 96 MiB Alpine container.
An historical OOM counter is evidence of a system event, not proof that a
particular upgrade process was killed. Safe diagnostics identify recovery,
polling or execution without logging credentials or raw errors. A known
same-process journal write failure is reported as `journal_failed`, rather
than being mistaken for an interrupted process on the next loop.

`caddy-updater binding --root /etc/caddy --field panel|machine|token` reads the
saved single machine binding. Token output is intended only for a private
installer capture and must never be printed or evaluated as shell input.
Configuration supports the installer-generated single `instances` entry and
legacy top-level machine mode. Ambiguous layouts fail closed. Credentials are
parsed as literal assignments; shell syntax is never evaluated. Linux requires
private configuration and credential file modes.

The assistant sends a bearer-authenticated GET poll every 15 seconds to
`/api/v3/server/upgrade/poll`, carrying machine_id, arch, actual version and
assistant_version. Task IDs are UUID strings. Progress and terminal results POST
to `/api/v3/server/upgrade/{id}/status`, carrying machine_id, lease, state,
actual version and fixed error_code. Expiry is Unix seconds. An unauthorized
response stops the process with an explicit diagnostic; service restart policy
may retry it after credentials are repaired.

Tasks permit strictly increasing semantic official releases. Downloads are
constructed independently under the fixed HTTPS GitHub onenew-releases
repository; the task URL must match exactly. An independently fetched official
architecture checksum list must match the task SHA, then the archive SHA must
match it. This is HTTPS plus checksums, not a digital signature. Redirects are
limited to GitHub asset hosts. Only two regular named archive members are
accepted. This supports future allowlisted panel releases without replacing
the assistant.

Before stopping caddy, the assistant records a synced journal and retains both
previous executables. Each executable is replaced by a synced same-directory
rename. Config, credentials and certificates are never written. Stable health
requires the OS caddy service and the exact configured machine row to be healthy;
individual pre-existing unhealthy child nodes are not a rollback trigger.
Recovery of a mutating journal restores both executables before reporting.
Result journals remain until acknowledgment; an expired lease rejection clears
only an already safe terminal result. Failed rollback retains emergency files
and blocks new jobs. The assistant never replaces itself.

Run `go test ./...` and `go vet ./...`. Tests cover systemd/OpenRC transactions,
subsequent versions, checksums, archives, literal credentials, UUID wire format,
health rollback, interrupted recovery, acknowledgment retry and expired results.
The optional real-assets test exercises actual release ELF binaries on Linux;
its environment hooks exist only in `_test.go` code.
