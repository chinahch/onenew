# Remote node upgrades

The server management toolbar and server details expose single and selected-server upgrades. The administrator selects an official version, reviews server names/count and the connection interruption warning, then confirms. Only previously authenticated upgrade assistants are eligible. Existing nodes must bootstrap the assistant once using the generated upgrade command; a normal node heartbeat alone never marks a machine remote-ready.

Jobs and assistant heartbeats are persisted in SQL. Every state mutation first updates the singleton mutex row inside a transaction. This serializes batch creation and claims across processes and database drivers. Waiting jobs expire after 24 hours and consume no execution slot. There is one globally active lease, lasting 900 seconds, with a further 180-second safety grace before the slot is released. The assistant must complete rollback before its lease deadline. Cancellation is limited to waiting jobs. A lost initial claim response can leave a lease waiting until expiry; it is never issued to a second worker.

Both the machine's immutable identity and its credential hash bind heartbeats, jobs and reports. Reusing a numeric machine ID or rotating credentials cannot inherit previous jobs or readiness. An already claimed old generation retains its bounded global slot until expiry, since its independent process may still be finishing rollback. Only SHA256 lease hashes are stored. Repeated identical terminal reports are accepted; conflicting replays, other-machine leases, backwards progress and expired reports are rejected.

`app/Onenew/RemoteUpgrade/catalog.json` is a source-controlled version/architecture allowlist. An empty catalog disables creation. Each entry must use an exact official GitHub package path and a 64-character lowercase SHA256. It must be populated only after the corresponding public asset has been built and verified. User-supplied URLs, commands, checksums and raw result logs are rejected. Verification uses HTTPS and SHA256, not a signature claim.

## Assistant protocol

All requests carry `Authorization: Bearer <machine token>`. Tokens never enter URLs or result payloads. Agent quotas are separated by a hash of the machine ID and credential, so machines behind the same egress IP do not consume one shared quota.

- `GET /api/v3/server/upgrade/poll?machine_id=N&arch=amd64&version=v0.1.11&assistant_version=v0.1.12` updates capability and actual-version heartbeat; returns `{"job":null}` or `{"job":{"id":"uuid","lease":"64 hex characters","expires_at":<Unix seconds>,"release":{"version":"v0.1.12","arch":"amd64","sha256":"...","url":"https://github.com/chinahch/onenew-releases/releases/download/node-v0.1.12/onenew-node-linux-amd64.tar.gz"}}}`.
- `POST /api/v3/server/upgrade/{id}/status` carries `{machine_id,lease,state,version,error_code}`. `version` is the actual installed version. States: `downloading`, `verifying`, `installing`, `checking`, `succeeded`, `failed`, `rolled_back`. Successful reports must match the requested target; rollback must match the version reported when the job was claimed. Error codes: `download_failed`, `verification_failed`, `install_failed`, `health_failed`, `rollback_failed`, `interrupted`, `invalid_task`, `expired`, `unsupported`, `internal_error`. Progress and success have an empty error code.

Admin-only APIs: `GET /api/onenew/admin/upgrades`, `POST` to the same path with `{machine_ids:[...],version,confirmed:true}` (1–100 distinct positive IDs), and `POST /api/onenew/admin/upgrades/{id}/cancel`. Public projections contain no token, credential hash, lease, raw log or configuration.

## Validation

`tests/RemoteUpgradeTest.php` runs in the container PHPUnit suite and covers authorization, bootstrap capability, fixed catalog, one global active lease, offline waiting, persisted status, progress ordering, result version truthfulness, replay rejection, pending cancellation, lease expiry grace, rotated/reused identity and rollback. Admin tests exercise eligibility, bounded payloads and the actual confirmation component without a request on initial render.
