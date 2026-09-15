#!/usr/bin/env bash
# Phase 16 — CI secret scanner.
#
# Fails the build when a committed file contains an obvious real secret.
# Never prints the secret itself. .env is git-ignored and never scanned;
# .env.example intentionally contains empty placeholders and is allowed.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

# Patterns that indicate a REAL (non-placeholder) secret. All are anchored to
# avoid matching config keys or documentation.
PATTERNS=(
  'APP_KEY=base64:[A-Za-z0-9+/=]\{20,\}'           # a real Laravel app key
  'AKIA[0-9A-Z]\{16\}'                               # AWS access key id
  '-----BEGIN [A-Z ]*PRIVATE KEY-----'              # private key material
  'ghp_[A-Za-z0-9]\{30,\}'                          # GitHub personal access token
  'sk_live_[A-Za-z0-9]\{10,\}'                      # live secret key
  'pk_live_[A-Za-z0-9]\{10,\}'                      # live public key
  'xox[baprs]-[A-Za-z0-9-]\{10,\}'                  # Slack tokens
  'AIza[0-9A-Za-z_-]\{30,\}'                        # Google API key
)

VIOLATIONS=0

scan_file() {
  local file="$1"
  local pattern
  for pattern in "${PATTERNS[@]}"; do
    if grep -Eq "$pattern" "$file" 2>/dev/null; then
      # Report the file and pattern CLASS, never the matched value.
      echo "::error file=$file::Potential secret detected (pattern class: ${pattern%%\\*}…)"
      VIOLATIONS=$((VIOLATIONS + 1))
    fi
  done
}

# Scan tracked source trees only — never vendor/, node_modules/, storage/ or
# the git-ignored .env.
while IFS= read -r -d '' file; do
  case "$file" in
    vendor/*|node_modules/*|storage/*|.git/*|.env|.env.example) continue ;;
    *) scan_file "$file" ;;
  esac
done < <(find . -type f -not -path './vendor/*' -not -path './node_modules/*' \
  -not -path './storage/*' -not -path './.git/*' -print0)

if [ "$VIOLATIONS" -gt 0 ]; then
  echo "Secret scan failed: $VIOLATIONS potential secret(s) found."
  exit 1
fi

echo "Secret scan passed."
