# Draft landing exit test

The administrator creates a 60-second encrypted task from the parser's single
unsaved outbound. The selected node's reported runtime must match the parser
kernel. With only a machine ID, all enabled listeners must report the same kernel;
mixed/missing reports require selecting a reported node. Nothing is saved to the
listener configuration.

The native worker starts an isolated client of that kernel on the node machine,
then sends HTTPS through its local SOCKS proxy to the fixed
`https://www.cloudflare.com/cdn-cgi/trace`. Redirects are refused. Headers and body
are bounded to 8 KiB; only a valid IP and two uppercase country letters survive.
No direct HTTP fallback, arbitrary target, command or routing rule is accepted.
The country is Cloudflare's egress location, not an asserted server location.

Both automatic and draft probes acquire `ProbeStore::locked(machineId)`.
`ProbeService::poll` must call `ExitProbeService::busy(machineId)` inside its lock
before scheduling; the exit scheduler refuses a live automatic probe. Each machine
has at most one pending/running exit draft. The deadline removes credentials and
leases even if the worker disappears; terminal results remove them immediately.
Public status is scoped to the creating administrator, contains no credentials,
and expires after three minutes. Routes omit the body audit middleware.

Integration:

* Copy `app/Onenew/*.php` into the app and load `routes.php` from its provider.
* Build the frontend with `landing-probe.mjs` in its module map/export surface.
  Render `LandingProbe` with React, `nodeId`/`machineId`, parser `core`, its single
  generated `outbound`, and authenticated `post`/`get`. Changing any draft input
  invalidates the displayed result; only the current request generation can update
  it. Do not pass all preserved outbounds or custom routes.
* Apply `../node/exit-probe-patch.py --source SOURCE` after the native probe overlay.
  It installs its worker lifecycle, source hashes and `onenew-exit-probe-v1` feature.
* Add this `tests` directory to the installed app's PHPUnit invocation.

Checks: `node --test customizations/onenew/admin/landing-probe.test.mjs`,
`php customizations/onenew/exit-probe/tests/portable-payload-check.php`, and native
`go test ./internal/exitprobe ./internal/panel ./internal/machine` after preparing
the complete source. The native test performs real TLS HTTP via a local SOCKS
server and verifies the proxy receives the fixed target; it needs no Internet.
