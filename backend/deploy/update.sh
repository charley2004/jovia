#!/usr/bin/env bash
# Jovia Network — pull the latest code from GitHub and apply it.
# Run from the browser terminal:  bash ~/domains/joviaplatform.ng/app/backend/deploy/update.sh
set -euo pipefail
source "$(dirname "$0")/common.sh"

git -C "$REPO_DIR" pull --ff-only origin main
install_packages
"$PHP" artisan migrate --force
publish_web_root
optimize
echo "✔ Updated to $(git -C "$REPO_DIR" log -1 --format='%h %s')"
