#!/usr/bin/env bash
set -euo pipefail
umask 077
revision='' package='' target='/opt/onenew'
while (($#)); do
  case "$1" in
    --revision|--package|--target) (($# >= 2)) || { echo 'Missing argument' >&2; exit 1; }; key="$1"; value="$2"; shift 2; case "$key" in --revision) revision="$value";; --package) package="$value";; --target) target="$value";; esac;;
    *) echo 'Unknown argument' >&2; exit 1;;
  esac
done
[[ "$revision" =~ ^[0-9a-f]{40}$ ]] || { echo 'A full lowercase source revision is required.' >&2; exit 1; }
for command in docker curl tar mktemp realpath; do command -v "$command" >/dev/null || { echo "Missing prerequisite: $command" >&2; exit 1; }; done
docker compose version >/dev/null
[[ -f "$package" && ! -L "$package" ]] || { echo 'Package must be a regular file.' >&2; exit 1; }
package=$(realpath -- "$package")
[[ "$target" = /* && ! -L "$target" ]] || { echo 'Target must be an absolute, new directory.' >&2; exit 1; }
if [[ -e "$target" ]] && { [[ ! -d "$target" ]] || [[ -n "$(find "$target" -mindepth 1 -maxdepth 1 -print -quit)" ]]; }; then echo 'Target already contains files; refusing to overwrite.' >&2; exit 1; fi
parent=$(dirname -- "$target")
[[ -d "$parent" ]] || { echo 'Create the target parent directory first.' >&2; exit 1; }
work=$(mktemp -d "$parent/.onenew-restore.XXXXXXXX")
# Only this fresh private staging directory is cleaned. An unsuccessful restore never touches target.
cleanup() { unset password; [[ -n "$work" && -d "$work" ]] && rm -rf -- "$work"; }
trap cleanup EXIT
suffix="${work##*.}"
project="onenew-restored-${suffix,,}"
image="$project:$revision"
[[ "$project" =~ ^onenew-restored-[a-z0-9]{8}$ ]] || { echo 'Invalid private restore project.' >&2; exit 1; }
[[ -z "$(docker ps -aq --filter "label=com.docker.compose.project=$project")" ]] || { echo 'Restore project already exists; refusing to replace containers.' >&2; exit 1; }
if docker image inspect "$image" >/dev/null 2>&1; then echo 'Restore image already exists; refusing to replace it.' >&2; exit 1; fi
curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 "https://github.com/chinahch/onenew/archive/$revision.tar.gz" -o "$work/source.tar.gz"
mkdir "$work/source" "$work/data"
tar -xzf "$work/source.tar.gz" --strip-components=1 -C "$work/source"
[[ -f "$work/source/Dockerfile" && -f "$work/source/customizations/onenew/migration/core/restore.php" ]] || { echo 'Source revision lacks migration support.' >&2; exit 1; }
docker build --build-arg "ONENEW_REVISION=$revision" -t "$image" "$work/source"
IFS= read -r -s -p 'Migration password: ' password </dev/tty
printf '\n' >/dev/tty
# Password is transmitted only over protected stdin, never an argument or environment variable.
printf '%s\n' "$password" | docker run --rm -i --network none --read-only --tmpfs /tmp:rw,noexec,nosuid --entrypoint php --mount "type=bind,source=$package,target=/package.onm,readonly" --mount "type=bind,source=$work/data,target=/restore" "$image" /opt/onenew/migration/core/restore.php /package.onm /restore/restored "$revision"
unset password
# Restored files remain data: do not source .env or execute package scripts.
restored="$work/data/restored"
[[ -f "$restored/.env" && -d "$restored/data/database" ]] || { echo 'Required restored data is missing.' >&2; exit 1; }
cp -- "$restored/.env" "$work/source/.env"
mkdir -p "$work/source/data/database" "$work/source/data/redis" "$work/source/plugins" "$work/source/storage/theme" "$work/source/storage/logs"
cp -a -- "$restored/data/database/." "$work/source/data/database/"
for path in plugins storage/theme; do
  if [[ -d "$restored/$path" ]]; then cp -a -- "$restored/$path/." "$work/source/$path/"; fi
done
# The archived source is trusted for this exact revision; package .env is excluded from Compose interpolation.
printf 'ONENEW_REVISION=%s\n' "$revision" > "$work/source/.env.compose.restore"
template="$work/source/compose.onenew.sample.yaml"
[[ $(grep -c '^name: onenew$' "$template") == 1 && $(grep -c '^    image: onenew:local$' "$template") == 1 ]] || { echo 'Unexpected trusted Compose template; refusing to deploy.' >&2; exit 1; }
sed -e "s/^name: onenew$/name: $project/" -e "s/^    image: onenew:local$/    image: $image/" "$template" > "$work/source/compose.yaml"
[[ ! -e "$target" ]] || rmdir -- "$target"
mv -T -n -- "$work/source" "$target"
[[ ! -e "$work/source" ]] || { echo 'Target changed concurrently; refusing to overwrite.' >&2; exit 1; }
cd -- "$target"
ONENEW_REVISION="$revision" docker compose --project-name "$project" --env-file .env.compose.restore -f compose.yaml up -d --build --wait
ONENEW_REVISION="$revision" docker compose --project-name "$project" --env-file .env.compose.restore -f compose.yaml exec -T onenew php artisan onenew:migration-assets
printf 'Restored to %s. Verify the local service and credentials, then switch DNS/proxy/tunnel. Keep the old server available for rollback.\n' "$target"
