# Native node proxy health bridge

This overlay implements the existing caddy luma-v2/v3 worker wire protocol. It does
not create a second worker or change/release caddy. Original xbnode builds without
the probe worker will remain pending; their heartbeat is not proof of connectivity.

## Installation contract

Copy `health/app/Onenew/*.php` into `/www/app/Onenew/`. The root Onenew provider
requires `/opt/onenew/health/routes.php` once, outside the `web` middleware group.
The route file adds `api` middleware itself. Add `use
\App\Onenew\NodeHealthAttribute;` inside `App\Models\Server`; the trait initializes
the appended `proxy_health` accessor. No other upstream controller needs changing.
The production store is always Laravel's configured `redis` cache store. Normal
Xboard database, APP_KEY and Redis configuration remain authoritative.

## Wire and scheduling

* `GET /api/v3/server/probes?machine_id=N`, Authorization Bearer native machine
  token: returns `{"tasks": []}` or exactly one leased task. No server-wide token
  fallback exists. Disabled machine tokens fail closed.
* `POST /api/v3/server/probes/{uuid}/result`, same Bearer token and JSON
  `machine_id`, `lease`, `state`, optional allowlisted `error_code`, `latency_ms`,
  `ping_ms` and `ping_error_code`. Wrong ownership, changed configuration/account,
  wrong lease, late results and duplicate terminal results cannot set online state.
* `GET /api/onenew/user/nodes`, native `user` middleware: returns a JSON array of
  enabled, visible native group/quota-authorized nodes with only id, name, type,
  active, machine_last_report_at and latest_probe. Exhausted, banned and expired
  users receive an empty list. No outbound, credentials, machine token or lease is
  included. The model accessor has the same safe projection.

The existing worker polls drive the schedule; no cron or queue daemon is required.
Nodes are attempted oldest-first, no more frequently than once per five minutes,
with one active task per machine, a 120-second lease and a 10-second proxy request
budget. Polls with another active task return no task. Capacity is bounded by the
worker polling interval and request duration, so five minutes is a minimum spacing,
not a guarantee that an arbitrarily large machine completes every listener in five
minutes. Unsupported/no-account nodes consume their own interval, without starving
other nodes. No task creation endpoint accepts an arbitrary URL, command, account,
outbound or node id from a client. The only target is
`https://www.gstatic.com/generate_204`.

Redis stores only the latest node status (24-hour TTL), a bounded per-machine active
id, and short-lived tasks. Task payloads are encrypted with APP_KEY. Only a SHA256
lease hash is persisted. Credentials and lease hashes expire at the 120-second
deadline even when polling stops; result transitions remove them immediately.
Per-machine Redis locks serialize scheduling and result transitions. Stored status
is invalidated by machine ownership/configuration changes. Results older than ten
minutes and uncompleted expired leases project as `expired`, never `succeeded`.
Configuration revisions include the resolved native routing rules in their emitted
order (`match`, `action`, `action_value`), so editing a route under an existing ID
invalidates pending leases and prior successes. Administrator route labels and
timestamps do not invalidate a connectivity result.
Heartbeat timestamps are reported independently and never set proxy success.

## Candidate protocol support

Regular supported client outbounds use the native SingBox subscription builders
and existing worker's `singbox` client kernel. ENC/XHTTP use an explicit native
Xray envelope with `stream_settings`, public client encryption and public REALITY
parameters. Private decryption/REALITY keys are never exported or derived.
Both clients can test a compatible server listener:
the client protocol speaks to the actual local server listener. The selected native
user is checked afresh through `ServerService::getAvailableUsers`, then reloaded
and checked again for group, quota, ban, expiry and UUID. SS2022 passwords use
`Server::generateServerPassword`, including native key derivation.

| Listener configuration | Bridge behavior |
| --- | --- |
| VMess TCP / WS / gRPC / HTTPUpgrade, plain or TLS | Candidate supported |
| VLESS TCP / WS / gRPC / HTTPUpgrade, plain / TLS / REALITY and flow | Candidate supported |
| Trojan TCP / WS / gRPC / HTTPUpgrade, TLS / REALITY | Candidate supported |
| Shadowsocks native cipher / SS2022 without obfs or external plugin | Candidate supported |
| Hysteria 2, TUIC 5, AnyTLS | Candidate supported with native TLS/obfs parameters |
| SOCKS5 without TLS; HTTP with optional native TLS | Candidate supported |
| Xray VLESS native ENC on supported transport, with plain / TLS / REALITY | Candidate supported with explicit Xray client schema |
| VMess / VLESS / Trojan XHTTP or SplitHTTP, with applicable TLS / REALITY | Candidate supported with explicit Xray client schema |
| XHTTP extra safe tuning / headers / XMUX | Preserved; alternate download endpoints, dial/TLS overrides and unknown fields explicitly unsupported |
| ENC/XHTTP using ECH or insecure TLS flags removed by the pinned Xray | Explicit `unsupported`, pending UI; never silently stripped |
| H2 / QUIC transport, fake TCP HTTP header, unknown transport options | Explicit `unsupported` |
| TLS-wrapped SOCKS, Trojan plaintext, VMess REALITY | Explicit `unsupported` |
| Hysteria 1, TUIC 4, Naive, Mieru, SS obfs/plugins | Explicit `unsupported` |
| Child/alias node without an independent proven listener mapping | `configuration_unavailable`, pending UI |

Regular listeners dial `127.0.0.1:server_port` (or a numeric native port if no
server_port exists). The original public host is retained as TLS SNI fallback;
configured SNI, REALITY keys/short-id, uTLS, flow, supported transport headers and
paths, and native account passwords are preserved. Local-loopback tests exercise
the node's actual authentication, routing and configured landing outbounds. They
do not verify external firewall reachability, CDN traversal, remote DNS propagation
or every geographic client path. The fixed target may independently be unavailable.
`configuration_unavailable` and `unsupported` returned by the worker remain pending,
while actual proxy/target/timeouts produce failed connectivity results.

The panel does not know a node's local certificate-manager state. The pinned Xray
server enables listener TLS only when it has a certificate; a separately configured
Caddy/CDN termination can therefore make the local backend plaintext while the
panel advertises TLS. The bridge preserves panel TLS semantics. Such deployment
topologies need an authoritative local-ingress mapping before interpreting their
loopback result; no TLS stripping or speculative insecure fallback is attempted.

## Validation

`tests/ProbeHealthTest.php` is a Laravel feature suite for the installed overlay.
It covers Bearer auth and disabled machines, one active task, real native user
selection, loopback target, encrypted cache storage, owner/lease/duplicate results,
expiry/freshness/five-minute spacing, entitlement filtering, safe projections and
SNI/REALITY preservation. Run with normal upstream development dependencies and
an isolated migrated database, for example:

```
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: CACHE_DRIVER=array \
  vendor/bin/phpunit --bootstrap vendor/autoload.php customizations/onenew/health/tests
```

Tests select the array cache only in `testing`; production always selects Redis.
`tests/portable-builder-check.php` also runs without Composer dependencies. It uses
a minimal Eloquent attribute-container shim to load the actual upstream models,
password derivation and SingBox builders, and checks 18 protocol projections plus
three unsafe/incomplete configuration refusals. This checks wire construction only,
not database entitlement, HTTP middleware or native handshake success.

Neither suite proves a live kernel handshake. Actual per-protocol native
listener/client roundtrips and production node migration still require Linux/native
worker validation. A portable PHP 8.2 runtime is available in the Windows authoring
environment: all overlay PHP files pass `php -l`, and the portable builder smoke
checks pass. Laravel vendor dependencies and Docker are absent, so no local
feature-test or native-kernel success is claimed here.
