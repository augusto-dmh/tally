#!/usr/bin/env bash
# Point this clone at versioned hooks under .githooks/
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

chmod +x "$root/.githooks/prepare-commit-msg" "$root/.githooks/commit-msg"
git config core.hooksPath .githooks
echo "install-git-hooks: core.hooksPath=.githooks"
