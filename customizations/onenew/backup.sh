#!/bin/sh
# A stopped-container snapshot includes SQLite/WAL, Redis RDB, keys and assets.
set -eu
umask 077
cd "$(dirname "$0")/../.."
docker compose config --quiet
out="${1:-/opt/onenew-backups/onenew-$(date -u +%Y%m%dT%H%M%SZ).tar.gz}"
mkdir -p "$(dirname "$out")"
out_dir="$(cd "$(dirname "$out")" && pwd -P)"
source_dir="$(pwd -P)"
case "$out_dir/" in "$source_dir/"*) echo 'Backup must be outside the deployment directory.' >&2;exit 1;;esac
test ! -e "$out" || { echo 'Backup already exists; refusing overwrite.' >&2;exit 1; }
tmp="${out}.partial.$$"
running="$(docker compose ps --status running --services onenew)"
resume() {
  code=$?
  trap - EXIT HUP INT TERM
  rm -f -- "$tmp"
  if [ -n "$running" ];then docker compose up -d onenew || code=1;fi
  exit "$code"
}
trap resume EXIT HUP INT TERM
docker compose stop onenew
tar --exclude='./.git' --exclude='./data/redis/redis.sock' \
    --exclude='*/node_modules' --exclude='*/dist' -czf "$tmp" .
test -s "$tmp"
mv -- "$tmp" "$out"
echo "Backup saved: $out"
echo 'Keep this archive private: it contains application keys and user data.'
