#!/bin/bash
# Block any attempt to run artisan migrate on the production server.
# This is a CRITICAL safety hook — artisan migrate on a Galera cluster
# can take nodes out of sync and cause downtime.
# Only blocks on the production machine (hostname "docker"); safe on CI and dev.

if [ "$(hostname)" != "docker" ]; then
    exit 0
fi

COMMAND="${CLAUDE_TOOL_INPUT_command:-}"

if echo "$COMMAND" | grep -qE 'artisan\s+migrate'; then
    echo "BLOCKED: artisan migrate must NEVER be run by Claude on this production system ($(hostname)). Database migrations are run manually by the operator only."
    exit 2
fi

exit 0
