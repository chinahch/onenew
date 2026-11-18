#!/usr/bin/env bash
set -euo pipefail
script=$(cd -- "$(dirname -- "$0")/.." && pwd)/upgrade-restored.sh
base=$(mktemp -d)
trap 'rm -rf -- "$base"' EXIT
mkdir -p "$base/bin" "$base/source/customizations/onenew/migration" "$base/install/data/database" "$base/install/customizations"
for path in Dockerfile .dockerignore compose.onenew.sample.yaml customizations/onenew/migration/restore.sh; do printf new > "$base/source/$path"; done
for path in Dockerfile .dockerignore compose.onenew.sample.yaml; do printf old > "$base/install/$path"; done
printf 'private-original-env' > "$base/install/.env"
printf 'private-original-db' > "$base/install/data/database/database.sqlite"
printf 'ONENEW_REVISION=bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb\n' > "$base/install/.env.compose.restore"
printf 'name: onenew-restored-12345678\nservices:\n  onenew:\n    build:\n      args:\n        ONENEW_REVISION: "${ONENEW_REVISION:-local}"\n' > "$base/install/compose.yaml"
tar -czf "$base/source.tar.gz" -C "$base" source
cat > "$base/bin/curl" <<'MOCK'
#!/bin/bash
while (($#)); do if [[ "$1" == -o ]]; then cp "$TEST_ARCHIVE" "$2"; exit; fi; shift; done
exit 1
MOCK
cat > "$base/bin/docker" <<'MOCK'
#!/bin/bash
printf '%s\n' "$*" >> "$TEST_LOG"
if [[ "$*" == *'artisan migrate --force --no-interaction' ]]; then exit "${MIGRATE_EXIT:-0}"; fi
if [[ "$*" == *'cat /opt/onenew/REVISION' ]]; then printf '%s\n' aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa; fi
MOCK
chmod +x "$base/bin/"*
PATH="$base/bin:$PATH" TEST_ARCHIVE="$base/source.tar.gz" TEST_LOG="$base/log" COMPOSE_PROJECT_NAME=other-production ONENEW_REVISION=wrong bash "$script" --target "$base/install" --revision aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
[[ $(cat "$base/install/.env") == private-original-env && $(cat "$base/install/data/database/database.sqlite") == private-original-db ]]
grep -Fq 'ONENEW_REVISION: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"' "$base/install/compose.yaml"
grep -Fq 'new' "$base/install/customizations/onenew/migration/restore.sh"
[[ $(find "$base/install" -name code.tar.gz | wc -l) == 1 ]]
while IFS= read -r line; do [[ "$line" == *'--project-name onenew-restored-12345678'* && "$line" != *other-production* ]]; done < "$base/log"
grep -Fq 'exec -T onenew php artisan migrate --force --no-interaction' "$base/log"
# A healthy web process is not proof that migrations succeeded.
set +e
PATH="$base/bin:$PATH" TEST_ARCHIVE="$base/source.tar.gz" TEST_LOG="$base/failure-log" MIGRATE_EXIT=19 bash "$script" --target "$base/install" --revision aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa > "$base/failure-output" 2>&1
result=$?
set -e
[[ "$result" == 19 ]]
! grep -Fq 'Version permanently pinned:' "$base/failure-output"
echo 'Archive upgrade pins version, refreshes migration code and preserves secrets/database'
