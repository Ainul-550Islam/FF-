#!/usr/bin/env bash
# Phase 16 — CI OpenAPI validation gate.
#
# Regenerates storage/api-docs/openapi.json and verifies every documented
# path exists as a route and every routed /api/v1 business endpoint is
# documented. Fails the build on any mismatch.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
cd "$ROOT"

if ! command -v python3 >/dev/null 2>&1; then
  echo "python3 is required for OpenAPI generation."
  exit 1
fi

python3 tools/gen_openapi.py
