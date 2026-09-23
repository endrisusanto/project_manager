#!/usr/bin/env bash
# ponytail: Bash Release Script Trigger for GBA Bridge Sync
# Usage: ./release.sh [patch|minor|major|X.Y.Z] [--dry-run] [--skip-build] [--notes="..."]

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$SCRIPT_DIR"

echo -e "\033[1;34m====================================================\033[0m"
echo -e "\033[1;34m   GBA Bridge Sync — Executing Release Pipeline     \033[0m"
echo -e "\033[1;34m====================================================\033[0m"

# Execute the Node.js release pipeline
node scripts/release.js "$@"
