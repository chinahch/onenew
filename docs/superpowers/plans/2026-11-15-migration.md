# Encrypted one-command migration plan

Approved design: ../onenew-一键迁移设计.md (workspace outputs). No SSH credentials, Docker socket, or overwrite of an existing deployment. Scope is the official SQLite Compose setup; Redis queues/caches and host reverse proxies/tunnels/DNS are not copied.

Implementation split: portable authenticated streaming bundle/restore tooling, and Laravel export jobs/API/admin UI. Existing deployment stays live; exported data is a point-in-time snapshot. Final cutover uses a maintenance window.

- [ ] Portable bundle pack/unpack with authenticated revision, bounded records, final marker, integrity checks, safe allowlisted paths, and cleanup on wrong password/tamper.
- [ ] Dedicated one-worker migration queue with retry_after longer than timeout. Admin export/status endpoints; encrypted queued password, SQLite VACUUM INTO snapshot, private staging files and expiration cleanup.
- [ ] One-use, short-lived POST-body download tickets, streamed browser download, progress polling and pinned restoration command.
- [ ] System-settings migration entry with password confirmation, package download, restoration/cutover steps.
- [ ] Disposable source/restore test checks APP_KEY, subscription and machine token identity; crypto/path/tamper/permissions failure tests; existing full suites; fresh review; publish.

Limits: 2 GiB package input, finite file count, 12–128 byte migration password, encrypted artifacts expire after one hour. A downloaded package remains usable with its password; no source service modification occurs during restore.
