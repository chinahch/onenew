# Administrator daily traffic drilldown

## Native data audit

`UserService::trafficFetch` captures `Server::getCurrentRate()` after native hooks,
chunks incoming user deltas, and independently dispatches `TrafficFetchJob`,
`StatUserJob` and `StatServerJob`. The native `v2_stat_user` table stores billed
upload/download grouped by user, archive day and rate. It has no node identifier.
`v2_stat_server` stores node totals without user attribution. These two aggregates
cannot reconstruct a historical user/node breakdown. Existing node reports carry
no stable report ID, so identical incoming delta bodies cannot safely be deduplicated
by content. This overlay does not invent such an ID or infer old node records.

The native daily statistics job truncates each report's `raw_bytes * captured_rate`
with `intval`. The new ledger uses the same operation, preserving the original rate
snapshot, raw up/down and billed up/down separately. It never multiplies already
billed archives or applies a node's current rate to historical data. The existing
quota-update job is unchanged; native quota SQL conversion and daily archive integer
truncation can differ at fractional-byte edges. The drilldown reconciles against
the daily archive being viewed, rather than claiming to replace the quota ledger.

## Installation contract

The root overlay installer must:

1. Copy `traffic/app/` into `/www/app/` alongside the other overlays.
2. Require `traffic/install.php` and call `applyOnenewTrafficPatch('/www')`.
   This verifies and replaces exactly one pinned `UserService` import:
   `use App\Jobs\StatUserJob;` becomes
   `use App\Onenew\Traffic\LedgerStatUserJob as StatUserJob;`.
3. In the Onenew provider, call
   `$this->loadMigrationsFrom('/opt/onenew/traffic/database/migrations')`, require
   `/opt/onenew/traffic/routes.php`, and register
   `$this->commands([\App\Onenew\Traffic\PruneTelemetry::class])`.
4. Run the normal database migration before starting/restarting web/queue workers.
   Schedule `onenew:prune-telemetry` hourly or daily with overlap protection.

`LedgerStatUserJob` inherits native queue, retries and native archive implementation.
It wraps the entire daily chunk, node ledger increments and one durable job UUID
receipt in a single database transaction. Partial failures roll everything back;
queue retries, serialization and manual retries of the same new job cannot apply
the archive/ledger twice, including across midnight. Fresh jobs with identical
deltas remain distinct legitimate reports. Old already-queued native jobs retain
their original class and have no new node projection. Monthly jobs keep native
behavior and are not misrepresented as daily node data.

Durable traffic replay receipts must not be pruned independently: deleting one
would make a sufficiently old manual queue replay bill twice. They contain only
job UUID and creation time, one row per dispatched chunk, not per user. Node ledger
rows are aggregated per user/day/node/protocol/rate. There are no foreign-key
cascades, so node deletion retains captured name/type attribution. Names reflect
the captured report snapshots, not necessarily today's administrator labels.

## Administrator APIs

All routes below use native `api` + `admin` middleware. User IDs are required,
existing users are resolved explicitly, page is 1..100000 and per_page is 1..100.
Responses disable HTTP caching and contain no node host, credentials, outbound,
URL or content. `record_at` must be the exact native daily archive epoch, at
midnight in `config('app.timezone')`, from 2000 through today. This avoids browser
timezone reinterpretation and handles calendar days without assuming 86400 seconds.

* `GET /api/onenew/admin/traffic/records?user_id=N&page=1&per_page=50` returns
  `{data,total,current_page,per_page,timezone}`. Rows retain native
  `id,user_id,server_rate,u,d,record_type,record_at` and add canonical panel `date`.
  Monthly rows remain distinguishable with `record_type=m` and should not have
  the daily drilldown button.
* `GET /api/onenew/admin/traffic/nodes?user_id=N&record_at=EPOCH&page=1&per_page=50`
  returns rows containing `server_id,node_name,server_type,server_rate,raw_u,raw_d,
  u,d,total,node_deleted`, pagination and `date,record_at,timezone`. The same node
  can have multiple rate rows. `u,d,total` are billed bytes; raw bytes are separate.
  `totals` covers all matching rows, not just the page. `native_totals` is the
  original daily archive and `unattributed` is its positive missing portion.
  Coverage is `complete`, `partial`, `unavailable` or `no_traffic`, with a fixed
  reason code and `started_at`. Legacy archives without genuine node records
  return no rows and `unavailable`; partial deployment days are not fabricated.
* `GET /api/onenew/admin/traffic/domains` takes the same day/user/page parameters.
  Rows contain `domain,server_id,node_name,server_type,first_seen,last_seen,
  hit_count,node_deleted`. `hit_count` counts actual reported connection metadata
  events, not page visits or HTTP requests. Coverage is `available`, `disabled`,
  `unavailable` or `expired`, with reason and `retention_days`. An empty response
  proves no recorded metadata is available; it does not prove no domains were used.
* `GET` / `POST /api/onenew/admin/traffic/audit-config` gets or changes
  `{enabled:boolean,retention_days?:1..30}`. Responses also report
  `metadata_only:true` and fixed `retention_days:1`. Collection defaults to
  disabled with a fixed one-day retention window (legacy `1..30` inputs are
  accepted for backward compatibility without extending retention).
  The admin domains tab must only change this after an explicit administrator
  action. Existing `domain-settings` GET/PUT aliases expose the same settings.

## Native domain metadata ingestion

The backend does not enable or infer an audit stream from existing traffic totals.
It requires the matching native collector. Administrative configuration is polled
by the machine using `GET /api/v3/server/domain-audit/config?machine_id=N` with its
Bearer machine token. Response: `{enabled:boolean,retention_days:int}`.

`POST /api/v3/server/domain-audit`, with the same Bearer token, accepts at most
64 KiB and 200 events:

```json
{
  "machine_id": 1,
  "node_id": 2,
  "batch_id": "00000000-0000-4000-8000-000000000001",
  "events": [
    {"user_id": 3, "domain": "example.com", "observed_at": 1794690000}
  ]
}
```

Only active native machine tokens and enabled nodes belonging to that machine are
accepted. No global server-token fallback exists. A disabled collector returns
`{enabled:false,accepted:0}` without persisting events. A transactional unique
machine/node/batch receipt makes exact retries safe. Enabled responses include
`enabled,accepted,duplicate`.

Before inserting either events or the replay receipt, the entire batch is checked
for existing users belonging to the node's permission groups. A nonexistent or
different-group user rejects the whole batch. Current quota, expiry and ban changes
do not reject otherwise valid buffered metadata from an earlier connection.

Domains are validated DNS hostnames, ASCII/punycode, lowercased with an optional
single trailing DNS dot removed. IP literals, URLs, paths, userinfo, newlines,
wildcards and extra per-event fields are rejected. Times must be inside the current
retention window and no more than five minutes in the future. The native source
must map an actual authenticated user and actual node to each hostname; it must not
reverse-resolve IPs or submit a full URL, query, HTTP content or private payload.
Only the normalized hostname, timestamp, user/node IDs and node label/type snapshot
are stored. Bounded collector drops or connections without domain metadata create
gaps; the API does not claim complete browsing history or classify legality.

`onenew:prune-telemetry` deletes expired domain rows (older than 1 day) and
expired domain batch receipts (older than 1 day plus the 5-minute future clock
skew allowance) in 500-row batches, scheduled daily at 02:00 Asia/Shanghai.
Queries exclude expired events immediately even before the scheduled deletion.
Changing settings or disabling collection does not invent or backfill historical records.

## Verification

The authoring host can run portable PHP syntax checks. Full Laravel tests require
the installed overlay, migrations, Composer development dependencies and an
isolated database:

```
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_DRIVER=array \
  vendor/bin/phpunit --bootstrap vendor/autoload.php customizations/onenew/traffic/tests
```

The feature suite covers native billing/truncation, retry idempotence, rollback,
multiple rates, deleted nodes, truthful legacy coverage, admin permissions,
pagination/date bounds, panel dates/monthly records, native machine ownership,
opt-in/domain validation, retention, replay and user isolation. Test-only Redis
cache aliases use the array driver because native settings explicitly select the
Redis store. Production cache configuration is unchanged. Native collector/kernel
and complete browser flow verification remain separate integration checks.


Domain view filters health-check hosts (`www.cloudflare.com`, `cloudflare.com`,
`cp.cloudflare.com`, `connectivitycheck.gstatic.com`, `www.gstatic.com`) and
first-label `api`, `telemetry`, `analytics`, `metrics` domains by default. SQL
filtering runs before grouping, totals and pagination, including existing history.
The admin can enable “显示检测及 API / 遥测域名” (`include_background=1`) to inspect
all retained events. This is a hostname heuristic, not proof of user intent;
raw records remain subject to the existing retention policy.
