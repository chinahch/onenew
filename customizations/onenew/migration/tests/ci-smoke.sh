#!/usr/bin/env bash
set -euo pipefail
umask 077
[[ "${ONENEW_MIGRATION_CI:-}" == isolated-only && "${COMPOSE_PROJECT_NAME:-}" == onenew-ci ]] || { echo 'Disposable CI only' >&2; exit 1; }
work=$(mktemp -d /tmp/onenew-migration-ci.XXXXXXXX)
cleanup() { if [[ -f "$work/source/compose.yaml" ]]; then (cd "$work/source" && docker compose --env-file .env.compose.restore -p onenew-migration-ci down) || true; fi; }
trap cleanup EXIT
original=$(docker compose exec -T -e ONENEW_CI_FIXTURE=isolated-only onenew php /opt/onenew/migration/tests/ci-fixture.php seed)
auth=$(curl --fail --silent -H 'Content-Type: application/json' -d '{"email":"ci-onenew@example.test","password":"ci-onenew-disposable-2048"}' http://127.0.0.1:7001/api/v1/passport/auth/login | python3 -c 'import json,sys;print(json.load(sys.stdin)["data"]["auth_data"])')
echo "::add-mask::$auth"
id=$(curl --fail --silent -H "Authorization: $auth" -H 'Content-Type: application/json' -d '{"password":"ci-migration-disposable-2048","password_confirmation":"ci-migration-disposable-2048"}' http://127.0.0.1:7001/api/onenew/admin/migration/exports | python3 -c 'import json,sys;print(json.load(sys.stdin)["id"])')
state=''
for attempt in $(seq 1 90); do
  state=$(curl --fail --silent -H "Authorization: $auth" "http://127.0.0.1:7001/api/onenew/admin/migration/exports/$id" | python3 -c 'import json,sys;print(json.load(sys.stdin)["state"])')
  [[ "$state" != failed && "$state" != expired ]] || { echo "Export $state" >&2; exit 1; }
  [[ "$state" != ready ]] || break
  sleep 2
done
[[ "$state" == ready ]] || { echo 'Migration worker timed out' >&2; exit 1; }
ticket=$(curl --fail --silent -H "Authorization: $auth" -X POST "http://127.0.0.1:7001/api/onenew/admin/migration/exports/$id/ticket" | python3 -c 'import json,sys;print(json.load(sys.stdin)["ticket"])')
echo "::add-mask::$ticket"
curl --fail --silent --data-urlencode "ticket=$ticket" http://127.0.0.1:7001/api/onenew/migration/download -o "$work/package.onm"
mkdir "$work/unpack" "$work/source"
printf '%s\n' ci-migration-disposable-2048 | docker run --rm -i --network none --read-only --tmpfs /tmp --entrypoint php --mount "type=bind,source=$work/package.onm,target=/package.onm,readonly" --mount "type=bind,source=$work/unpack,target=/restore" onenew:local /opt/onenew/migration/core/restore.php /package.onm /restore/restored "$ONENEW_REVISION"
git -c safe.directory="$PWD" archive HEAD | tar -x -C "$work/source"
cp -a "$work/unpack/restored/." "$work/source/"
mkdir -p "$work/source/data/redis" "$work/source/storage/logs"
sed -e '/ONENEW_REVISION:/c\        ONENEW_REVISION: "'"$ONENEW_REVISION"'"' -e '/ONENEW_BIND_IP/c\      - "127.0.0.1:7002:7001"' "$work/source/compose.onenew.sample.yaml" > "$work/source/compose.yaml"
printf 'ONENEW_REVISION=%s\nONENEW_PORT=7002\n' "$ONENEW_REVISION" > "$work/source/.env.compose.restore"
(
  cd "$work/source"
  docker compose --env-file .env.compose.restore -p onenew-migration-ci up -d --no-build --wait
  docker compose --env-file .env.compose.restore -p onenew-migration-ci exec -T onenew php artisan onenew:migration-assets
  restored=$(docker compose --env-file .env.compose.restore -p onenew-migration-ci exec -T -e ONENEW_CI_FIXTURE=isolated-only onenew php /opt/onenew/migration/tests/ci-fixture.php digest)
  [[ "$original" == "$restored" ]] || { echo 'Restored key or credentials changed' >&2; exit 1; }
  # Reproduce normal operator rebuild with neither exported revision nor special env-file.
  env -u ONENEW_REVISION docker compose -p onenew-migration-ci config --format json > "$work/config.json"
  python3 -c 'import json,sys;assert json.load(open(sys.argv[1]))["services"]["onenew"]["build"]["args"]["ONENEW_REVISION"]==sys.argv[2]' "$work/config.json" "$ONENEW_REVISION"
  env -u ONENEW_REVISION docker compose -p onenew-migration-ci up -d --build --wait
  actual=$(env -u ONENEW_REVISION docker compose -p onenew-migration-ci exec -T onenew cat /opt/onenew/REVISION)
  [[ "$actual" == "$ONENEW_REVISION" ]] || { echo 'Normal rebuild lost the restored revision' >&2; exit 1; }

)
curl --fail --silent -H 'Content-Type: application/json' -d '{"email":"ci-onenew@example.test","password":"ci-onenew-disposable-2048"}' http://127.0.0.1:7002/api/v1/passport/auth/login > "$work/login.json"
python3 -c 'import json,sys;assert json.load(open(sys.argv[1]))["data"]["auth_data"]' "$work/login.json"
# The rebuilt restored panel must itself be capable of generating the next migration.
auth=$(python3 -c 'import json,sys;print(json.load(open(sys.argv[1]))["data"]["auth_data"])' "$work/login.json")
echo "::add-mask::$auth"
curl --fail --silent -H "Authorization: $auth" http://127.0.0.1:7002/api/onenew/admin/migration > "$work/info.json"
python3 -c 'import json,sys;d=json.load(open(sys.argv[1]));assert d["supported"] and d["revision"]==sys.argv[2]' "$work/info.json" "$ONENEW_REVISION"
id=$(curl --fail --silent -H "Authorization: $auth" -H 'Content-Type: application/json' -d '{"password":"ci-migration-disposable-2048","password_confirmation":"ci-migration-disposable-2048"}' http://127.0.0.1:7002/api/onenew/admin/migration/exports | python3 -c 'import json,sys;print(json.load(sys.stdin)["id"])')
state=''
for attempt in $(seq 1 90); do
  state=$(curl --fail --silent -H "Authorization: $auth" "http://127.0.0.1:7002/api/onenew/admin/migration/exports/$id" | python3 -c 'import json,sys;print(json.load(sys.stdin)["state"])')
  [[ "$state" != failed && "$state" != expired ]] || { echo "Second export $state" >&2; exit 1; }
  [[ "$state" != ready ]] || break
  sleep 2
done
[[ "$state" == ready ]] || { echo 'Second migration worker timed out' >&2; exit 1; }
echo 'Encrypted export, fresh Compose restore, normal rebuild, second export and login passed' 
