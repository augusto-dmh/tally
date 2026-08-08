#!/usr/bin/env bash
# CI / local: fail if any commit in RANGE has a bad subject or AI attribution.
# Usage: scripts/check-commit-messages.sh [base_ref] [head_ref]
# Default: origin/main..HEAD
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "$root"

base="${1:-origin/main}"
head="${2:-HEAD}"

if ! git rev-parse --verify "$base" >/dev/null 2>&1; then
  echo "check-commit-messages: base ref not found: $base" >&2
  exit 1
fi

range="${base}..${head}"
commits="$(git rev-list --reverse "$range")"
if [[ -z "$commits" ]]; then
  echo "check-commit-messages: no commits in $range"
  exit 0
fi

failed=0
while IFS= read -r sha; do
  [[ -z "$sha" ]] && continue
  tmp="$(mktemp)"
  git log -1 --format='%B' "$sha" >"$tmp"
  if ! "$root/.githooks/commit-msg" "$tmp"; then
    echo "check-commit-messages: failed on $sha ($(git log -1 --format='%s' "$sha"))" >&2
    failed=1
  fi
  rm -f "$tmp"
done <<<"$commits"

if [[ "$failed" -ne 0 ]]; then
  echo "check-commit-messages: one or more commits violate project commit hygiene" >&2
  exit 1
fi

echo "check-commit-messages: ok ($range)"
