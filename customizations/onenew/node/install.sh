#!/usr/bin/env bash
# Native binary installation; no Docker is used on the node server.
set -Eeuo pipefail
umask 077
VERSION=onenew-v0.1.0
RELEASE=node-v0.1.0
BASE="https://github.com/chinahch/onenew/releases/download/$RELEASE"
ROOT=/etc/caddy
PANEL= TOKEN= MACHINE= CHECK=0
fail() { printf 'ERROR: %s\n' "$1" >&2; exit 1; }
while [ "$#" -gt 0 ]; do
  case "$1" in
    --check-only) CHECK=1; shift ;;
    --mode) [ "${2:-}" = machine ] || fail 'Only machine mode is supported'; shift 2 ;;
    --panel) [ "$#" -ge 2 ] || fail 'Missing panel'; PANEL="$2"; shift 2 ;;
    --token) [ "$#" -ge 2 ] || fail 'Missing token'; TOKEN="$2"; shift 2 ;;
    --machine-id) [ "$#" -ge 2 ] || fail 'Missing machine ID'; MACHINE="$2"; shift 2 ;;
    *) fail 'Unknown installation argument' ;;
  esac
done
[[ "$PANEL" =~ ^https://[^/@[:space:]\?#]+(:[0-9]+)?(/[^[:space:]\?#]*)?$ ]] || fail 'A valid HTTPS panel URL is required'
[[ "$MACHINE" =~ ^[1-9][0-9]*$ ]] || fail 'A positive machine ID is required'
[ -n "$TOKEN" ] || fail 'A machine token is required'
[ "$(id -u)" -eq 0 ] || [ "$CHECK" -eq 1 ] || fail 'Run as root or sudo'
case "$(uname -m)" in
  x86_64|amd64) ARCH=amd64 ;;
  aarch64|arm64) ARCH=arm64 ;;
  *) fail 'Unsupported architecture' ;;
esac
# Never install a second agent over an unknown live caddy/php-fpm/XB instance.
if [ "$CHECK" -eq 0 ]; then
  for path in "$ROOT/config.yml" /etc/php-fpm/config.yml /etc/xboard-node/config.yml; do
    [ ! -e "$path" ] || fail 'Existing node configuration found. Back it up and migrate the old service before installing; nothing was replaced.'
  done
  [ ! -e /usr/local/bin/caddy ] || fail 'Existing Onenew binary found; refusing an implicit upgrade'
fi
for tool in curl tar sha256sum install; do command -v "$tool" >/dev/null || fail "Missing dependency: $tool"; done
if command -v rc-service >/dev/null && command -v supervise-daemon >/dev/null; then MANAGER=openrc
elif command -v systemctl >/dev/null; then MANAGER=systemd
else fail 'systemd or OpenRC with supervise-daemon is required'; fi
STAGE=$(mktemp -d)
trap 'rm -rf -- "$STAGE"' EXIT
PACKAGE="onenew-node-linux-$ARCH.tar.gz"
curl --proto '=https' --tlsv1.2 -fsSL --retry 2 "$BASE/$PACKAGE" -o "$STAGE/$PACKAGE"
curl --proto '=https' --tlsv1.2 -fsSL --retry 2 "$BASE/SHA256SUMS-$ARCH" -o "$STAGE/SHA256SUMS"
SUM=$(awk -v file="$PACKAGE" '$2 == file && $1 ~ /^[0-9a-f]+$/ && length($1) == 64 { print $1 }' "$STAGE/SHA256SUMS")
[[ "$SUM" =~ ^[0-9a-f]{64}$ ]] || fail 'Missing or ambiguous package checksum'
(cd "$STAGE"; printf '%s  %s\n' "$SUM" "$PACKAGE" | sha256sum -c - >/dev/null) || fail 'Package checksum mismatch; nothing was installed'
# Extract only named executable streams, never arbitrary archive paths.
tar -tzf "$STAGE/$PACKAGE" > "$STAGE/list"
[ "$(wc -l < "$STAGE/list")" -eq 2 ] && grep -qx caddy "$STAGE/list" && grep -qx caddyctl "$STAGE/list" || fail 'Unexpected package contents'
for binary in caddy caddyctl; do
  tar -xzOf "$STAGE/$PACKAGE" "$binary" > "$STAGE/$binary"
  chmod 755 "$STAGE/$binary"
done
OBSERVED=$("$STAGE/caddy" -v)
[[ "$OBSERVED" == "caddy $VERSION (built "* ]] || fail 'Unexpected node version'
if [ "$CHECK" -eq 1 ]; then printf 'Verified %s (%s); no service or configuration changed.\n' "$VERSION" "$ARCH"; exit 0; fi
mkdir -p "$ROOT" /usr/local/bin
chmod 700 "$ROOT"
"$STAGE/caddyctl" config init --mode machine --panel-url "${PANEL%/}" --token "$TOKEN" --machine-id "$MACHINE" --kernel singbox --health-port 65530 --version "$VERSION" --output "$ROOT/config.yml" --credentials-out "$ROOT/credentials.env" --meta "$ROOT/install-meta.json" --install-root "$ROOT" >/dev/null
unset TOKEN
chmod 600 "$ROOT/config.yml" "$ROOT/credentials.env" "$ROOT/install-meta.json"
install -m 755 "$STAGE/caddy" /usr/local/bin/caddy
install -m 755 "$STAGE/caddyctl" /usr/local/bin/caddyctl
if [ "$MANAGER" = systemd ]; then
  cat > /etc/systemd/system/caddy.service <<'UNIT'
[Unit]
Description=Onenew Native Node
After=network-online.target
Wants=network-online.target
[Service]
Type=simple
WorkingDirectory=/etc/caddy
EnvironmentFile=/etc/caddy/credentials.env
ExecStart=/usr/local/bin/caddy -c /etc/caddy/config.yml
Restart=always
RestartSec=5
LimitNOFILE=1048576
UMask=0077
[Install]
WantedBy=multi-user.target
UNIT
  systemctl daemon-reload
  systemctl enable --now caddy.service
  sleep 3
  systemctl is-active --quiet caddy.service || fail 'Node failed to start; inspect journalctl -u caddy'
else
  cat > /etc/init.d/caddy <<'UNIT'
#!/sbin/openrc-run
description="Onenew Native Node"
command="/usr/local/bin/caddy"
command_args="-c /etc/caddy/config.yml"
directory="/etc/caddy"
supervisor="supervise-daemon"
respawn_delay=5
respawn_max=0
output_log="/var/log/caddy.log"
error_log="/var/log/caddy.err"
start_pre() {
  set -a
  . /etc/caddy/credentials.env
  set +a
}
depend() { need net; }
UNIT
  chmod 755 /etc/init.d/caddy
  rc-update add caddy default
  rc-service caddy start
  sleep 3
  rc-service caddy status || fail 'Node failed to start; inspect /var/log/caddy.err'
fi
printf 'Installed %s. Configuration: /etc/caddy. Management: caddyctl\n' "$VERSION"
