# Onenew corresponding native source

This archive contains the prepared Go source used for the native caddy and caddyctl binaries. It does not include the PHP panel application or deployment credentials. Existing licenses, notices, go.mod and go.sum are retained.

Use Go 1.26.10. The release build-info JSON records the pinned upstream commit, patches, version and source date epoch. This source has already been patched and branded; do not run the preparation patches again.

Example for v0.1.15 amd64 (change GOARCH to arm64 for that binary):

```bash
export CGO_ENABLED=0 GOOS=linux GOARCH=amd64 GOAMD64=v1
stamp=$(python3 -c 'import datetime,json; d=json.load(open("build-source.json")); print(datetime.datetime.fromtimestamp(d["source_date_epoch"],datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"))')
flags="-s -w -X main.version=onenew-v0.1.15 -X main.buildTime=$stamp"
go build -p 2 -mod=readonly -trimpath -buildvcs=false -ldflags "$flags" -tags 'with_quic with_utls with_wireguard with_acme with_clash_api' -o caddy ./cmd/xboard-node
go build -p 2 -mod=readonly -trimpath -buildvcs=false -ldflags "$flags" -o caddyctl ./cmd/xbctl
```

The independent upgrade assistant corresponding source is in `onenew-updater/`.
Build it from that directory with the same Go toolchain:

```bash
CGO_ENABLED=0 GOOS=linux GOARCH=amd64 go build -mod=readonly -trimpath -buildvcs=false -ldflags '-s -w' -o caddy-updater-linux-amd64 .
```

Remote tasks can select only the panel's source-controlled official catalog. The
assistant independently derives the fixed public release URL, validates HTTPS,
checks the official SHA256SUMS manifest against the task, and rejects downgrades.
Checksums and HTTPS authenticate the distribution channel; builds are not signed.
The assistant remains running when caddy restarts. Bootstrap existing nodes once
with the current public installer and `--upgrade`; saved binding and credentials
are read literally, without shell evaluation. Later catalog versions can be
installed with the same assistant, without entering credentials again.

Licensing notice: no root license file was present in the inherited prepared
Xboard-Node baseline or its referenced upstream tree. This distribution does not
relicense that baseline as MIT. Existing copyright statements and dependency
licenses remain applicable; upstream provenance is recorded in build-source.json.
The separately authored assistant license applies only to onenew-updater/.

The archive also contains complete source trees (including licenses) for the Go
modules used by the dual-kernel build in corresponding-dependencies/. Their exact
versions and directories are recorded in corresponding-dependencies.json. They
are supplied for source availability; the normal Go module commands above use
the pinned go.mod/go.sum. For an offline build, add local module replacements
for those manifest entries before running the same build commands.
