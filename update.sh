#!/usr/bin/env bash
set -euo pipefail
cat >&2 <<'EOF'
ERROR: Upstream update.sh is disabled in onenew to protect pinned dependencies, overlays, and private data permissions.

Use the supported onenew upgrade procedure instead (see customizations/onenew/DEPLOY.md):
  1. Archive / restored deployment:
     bash <(curl -fsSL https://get.timi.baby/panel/upgrade) --revision <40-char-commit-sha> --target <panel-dir>
  2. Git-cloned Docker deployment:
     sh customizations/onenew/backup.sh
     git pull --ff-only
     export ONENEW_REVISION="$(git rev-parse HEAD)"
     docker compose up -d --build --wait
EOF
exit 1
