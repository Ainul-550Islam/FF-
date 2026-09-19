#!/bin/bash
# FF Arena — G6 Rollback Script
set -euo pipefail
ENVIRONMENT="${1:-production}"
ROLLBACK_VERSION="${2:-$(cat .last_successful_version 2>/dev/null || echo 'latest')}"
echo "=== FF Arena Rollback ==="
echo "Environment: $ENVIRONMENT"
echo "Rollback to: $ROLLBACK_VERSION"
echo "Timestamp: $(date -u +%Y-%m-%dT%H:%M:%SZ)"

# Confirm in production
if [[ "$ENVIRONMENT" == "production" ]]; then
  read -p "Are you sure you want to rollback production to $ROLLBACK_VERSION? (yes/no): " confirm
  if [[ "$confirm" != "yes" ]]; then echo "Rollback cancelled"; exit 0; fi
fi

# Execute rollback via deploy.sh
./deploy/deploy.sh $ENVIRONMENT $ROLLBACK_VERSION "" rollback

echo "Rollback complete"
