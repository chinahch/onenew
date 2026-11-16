# Onenew corresponding native source

This archive contains the prepared Go source used for the native caddy and caddyctl binaries. It does not include the PHP panel application or deployment credentials. Existing licenses, notices, go.mod and go.sum are retained.

Use Go 1.26.10. The release build-info JSON records the pinned upstream commit, patches, version and source date epoch. This source has already been patched and branded; do not run the preparation patches again.

Example for v0.1.11 amd64 (change GOARCH to arm64 for that binary):

```bash
export CGO_ENABLED=0 GOOS=linux GOARCH=amd64 GOAMD64=v1
stamp=$(python3 -c 'import datetime,json; d=json.load(open("build-source.json")); print(datetime.datetime.fromtimestamp(d["source_date_epoch"],datetime.timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ"))')
flags="-s -w -X main.version=onenew-v0.1.11 -X main.buildTime=$stamp"
go build -p 2 -mod=readonly -trimpath -buildvcs=false -ldflags "$flags" -tags 'with_quic with_utls with_wireguard with_acme with_clash_api' -o caddy ./cmd/xboard-node
go build -p 2 -mod=readonly -trimpath -buildvcs=false -ldflags "$flags" -o caddyctl ./cmd/xbctl
```
