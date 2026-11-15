#!/usr/bin/env bash
set -euo pipefail
base=$(mktemp -d)
trap 'rm -rf -- "$base"' EXIT
script=$(cd -- "$(dirname -- "$0")/.." && pwd)/restore.sh
bash_bin=$(command -v bash)
rev=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
mkdir "$base/bin" "$base/empty-bin" "$base/occupied"
printf 'keep' > "$base/occupied/marker"
: > "$base/package.onm"
cat > "$base/bin/docker" <<'MOCK'
#!/bin/sh
if [ "$1" = image ]; then exit 1; fi
exit 0
MOCK
cat > "$base/bin/curl" <<'MOCK'
#!/bin/sh
exit 42
MOCK
chmod +x "$base/bin/"*
if PATH="$base/empty-bin" "$bash_bin" "$script" --revision "$rev" --package "$base/package.onm" --target "$base/missing" >"$base/out" 2>&1; then echo 'Missing Docker should fail' >&2; exit 1; fi
grep -q 'Missing prerequisite: docker' "$base/out"
if PATH="$base/bin:$PATH" "$bash_bin" "$script" --revision "$rev" --package "$base/package.onm" --target "$base/occupied" >"$base/out" 2>&1; then echo 'Nonempty target should fail' >&2; exit 1; fi
grep -q 'refusing to overwrite' "$base/out"
[[ $(cat "$base/occupied/marker") == keep ]]
if PATH="$base/bin:$PATH" "$bash_bin" "$script" --revision "$rev" --package "$base/package.onm" --target "$base/new" >"$base/out" 2>&1; then echo 'Download failure should fail' >&2; exit 1; fi
[[ ! -e "$base/new" ]]
[[ -z $(find "$base" -maxdepth 1 -name '.onenew-restore.*' -print -quit) ]]
# Verify deployment identities are generated per restore and guarded before mutation.
grep -Fq 'project="onenew-restored-${suffix,,}"' "$script"
grep -Fq 'image="$project:$revision"' "$script"
grep -Fq 'label=com.docker.compose.project=$project' "$script"
grep -Fq 's/^name: onenew$/name: $project/' "$script"
grep -Fq 's/^    image: onenew:local$/    image: $image/' "$script"
echo 'Restore bootstrap refusal/cleanup tests passed'
