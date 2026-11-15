#!/usr/bin/env bash
# One-time upgrade for archive-based restores; application secrets and data are never overwritten.
set -euo pipefail
umask 077
revision='' target='/opt/onenew'
while (($#)); do
  case "$1" in
    --revision|--target) (($# >= 2)) || exit 1; key="$1"; value="$2"; shift 2; case "$key" in --revision) revision="$value";; --target) target="$value";; esac;;
    *) echo 'Unknown argument' >&2; exit 1;;
  esac
done
[[ "$revision" =~ ^[a-f0-9]{40}$ ]] || { echo 'A pinned 40-character revision is required.' >&2; exit 1; }
for command in docker curl tar realpath mktemp; do command -v "$command" >/dev/null || exit 1; done
[[ -d "$target" && ! -L "$target" ]] || exit 1
target=$(realpath -- "$target")
cd -- "$target"
[[ ! -e .git && -f .env.compose.restore && -f compose.yaml && -f .env && -d data/database ]] || { echo 'Expected an archive-based restored installation; refusing other deployments.' >&2; exit 1; }
for path in compose.yaml .env.compose.restore customizations Dockerfile .dockerignore compose.onenew.sample.yaml; do [[ ! -L "$path" ]] || exit 1; done
[[ -z "$(find customizations -type l -print -quit)" ]] || { echo 'Linked build inputs require manual review.' >&2; exit 1; }
[[ $(grep -c '^ONENEW_REVISION=' .env.compose.restore) == 1 ]] || { echo 'Unexpected restore metadata.' >&2; exit 1; }
project=$(sed -n 's/^name: //p' compose.yaml)
[[ "$project" =~ ^onenew-restored-[a-z0-9]{8}$ ]] || { echo 'Unexpected restored project name.' >&2; exit 1; }
[[ $(grep -c '^        ONENEW_REVISION:' compose.yaml) == 1 ]] || { echo 'Unexpected Compose build configuration.' >&2; exit 1; }
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT
curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 "https://github.com/chinahch/onenew/archive/$revision.tar.gz" -o "$work/source.tar.gz"
mkdir "$work/source"
tar -xzf "$work/source.tar.gz" --strip-components=1 -C "$work/source"
for path in Dockerfile .dockerignore compose.onenew.sample.yaml customizations/onenew/migration/restore.sh; do
  [[ -f "$work/source/$path" ]] || { echo 'Incomplete pinned source archive.' >&2; exit 1; }
done
# Back up code/configuration only. Never include or replace .env, database, plugins or theme data.
backup=$(mktemp -d "$target/.onenew-code-backup.XXXXXXXX")
tar -czf "$backup/code.tar.gz" Dockerfile .dockerignore compose.onenew.sample.yaml customizations compose.yaml .env.compose.restore
printf 'Code backup: %s/code.tar.gz\n' "$backup"
# Only the Docker build inputs are replaced. Archive restores have no Git checkout.
cp -- "$work/source/Dockerfile" "$work/source/.dockerignore" "$work/source/compose.onenew.sample.yaml" .
cp -a -- "$work/source/customizations/." customizations/
sed -e 's/^        ONENEW_REVISION:.*/        ONENEW_REVISION: "'"$revision"'"/' compose.yaml > "$work/compose.yaml"
docker compose --project-name "$project" --env-file .env.compose.restore -f "$work/compose.yaml" --project-directory "$target" config --quiet
cat "$work/compose.yaml" > compose.yaml
sed 's/^ONENEW_REVISION=.*/ONENEW_REVISION='"$revision"'/' .env.compose.restore > "$work/env.compose.restore"
cat "$work/env.compose.restore" > .env.compose.restore
docker compose --project-name "$project" --env-file .env.compose.restore -f compose.yaml up -d --build --wait
actual=$(docker compose --project-name "$project" --env-file .env.compose.restore -f compose.yaml exec -T onenew cat /opt/onenew/REVISION)
[[ "$actual" == "$revision" ]] || { echo 'Running image version did not match; retain the code backup.' >&2; exit 1; }
printf 'Version permanently pinned: %s\nNormal docker compose up -d --build now preserves it. Refresh the migration page.\n' "$revision"
