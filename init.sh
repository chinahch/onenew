#!/usr/bin/env bash
set -euo pipefail
cat >&2 <<'EOF'
ERROR: Upstream init.sh is disabled in onenew.

Deploy using compose.onenew.sample.yaml and php artisan onenew:install as documented in customizations/onenew/DEPLOY.md.
EOF
exit 1
