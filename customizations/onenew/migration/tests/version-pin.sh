#!/usr/bin/env bash
set -euo pipefail
script=$(cd -- "$(dirname -- "$0")/.." && pwd)/restore.sh
# Extract the real rendering block and execute it with the actual trusted template.
base=$(mktemp -d)
trap 'rm -rf -- "$base"' EXIT
mkdir -p "$base/source"
cp "$(dirname -- "$script")/../../../compose.onenew.sample.yaml" "$base/source/compose.onenew.sample.yaml"
work="$base" revision=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa project=onenew-restored-12345678 image=test-image port=7001
export ONENEW_REVISION=wrong-inherited-version
block=$(sed -n '/^template=/,/^\[\[ ! -e /p' "$script" | sed '$d')
eval "$block"
grep -Fq 'ONENEW_REVISION: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"' "$base/source/compose.yaml"
if grep -Fq '${ONENEW_REVISION' "$base/source/compose.yaml"; then echo 'Revision still depends on environment' >&2; exit 1; fi
echo 'Compose revision remains pinned without env-file or shell export'
