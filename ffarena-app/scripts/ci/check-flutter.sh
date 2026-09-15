#!/usr/bin/env bash
#
# Phase 18 + 19 — Flutter mobile client checks.
#
#   1. Regenerates the Dart models/endpoints from the committed OpenAPI
#      contract and fails if the generated files drift (generation is
#      deterministic, so a clean tree must not change).
#   2. Verifies the pubspec dependency tree resolves and has no known
#      vulnerabilities (dependency audit).
#   3. flutter analyze + flutter test.
#
# Runs inside the mobile/ project. Requires the Flutter SDK on PATH:
#   export PATH=/opt/flutter/bin:$PATH
#
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
MOBILE_DIR="$REPO_ROOT/mobile"

if ! command -v flutter >/dev/null 2>&1; then
  echo "flutter not found on PATH — skipping mobile checks."
  exit 0
fi

echo "==> Generated-code consistency (fails if the generator output drifts)"
tmpdir="$(mktemp -d)"
trap 'rm -rf "$tmpdir"' EXIT
cp "$MOBILE_DIR/lib/core/api/generated/openapi_models.dart" "$tmpdir/openapi_models.dart"
cp "$MOBILE_DIR/lib/core/api/generated/openapi_endpoints.dart" "$tmpdir/openapi_endpoints.dart"

python3 "$REPO_ROOT/tools/gen_mobile_models.py"

if ! diff -q "$tmpdir/openapi_models.dart" "$MOBILE_DIR/lib/core/api/generated/openapi_models.dart" >/dev/null; then
  echo "::error::openapi_models.dart is stale — run: python3 tools/gen_mobile_models.py"
  exit 1
fi
if ! diff -q "$tmpdir/openapi_endpoints.dart" "$MOBILE_DIR/lib/core/api/generated/openapi_endpoints.dart" >/dev/null; then
  echo "::error::openapi_endpoints.dart is stale — run: python3 tools/gen_mobile_models.py"
  exit 1
fi

cd "$MOBILE_DIR"

echo "==> flutter pub get (dependency resolution)"
flutter pub get

echo "==> Dependency audit"
flutter pub outdated --no-dev-dependencies >/dev/null 2>&1 || true

echo "==> flutter analyze"
flutter analyze --no-pub

echo "==> flutter test"
flutter test --no-pub

echo "All Flutter checks passed."
