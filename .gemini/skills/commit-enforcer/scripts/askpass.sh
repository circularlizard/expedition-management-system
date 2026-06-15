#!/usr/bin/env bash
# This script is used by GIT_ASKPASS to provide the SSH passphrase.
# It reads GITHUB_PASSPHRASE from the .env.local file in the project root.

# Find the project root (assuming this script is in .agents/skills/commit-enforcer/scripts/)
PROJECT_ROOT=$(git rev-parse --show-toplevel)
ENV_FILE="$PROJECT_ROOT/.env.local"

if [ -f "$ENV_FILE" ]; then
    # Extract GITHUB_PASSPHRASE value
    PASSPHRASE=$(grep "^GITHUB_PASSPHRASE=" "$ENV_FILE" | cut -d'=' -f2-)
    if [ -n "$PASSPHRASE" ]; then
        echo "$PASSPHRASE"
        exit 0
    fi
fi

# Fallback or error
exit 1
