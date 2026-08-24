#!/bin/bash
# Block any attempt by Claude to change the production database schema.
# This is a CRITICAL safety hook. Schema changes (migrations, DDL) on the
# Galera cluster are run MANUALLY BY THE OPERATOR ONLY — never by Claude.
# History: artisan migrate broke Galera once; on 2026-07-31 Claude applied a
# migration .sql via `ssh db3 'mysql iznik' < file.sql`, which the old
# artisan-only check missed. This hook now blocks every route:
#   1. artisan migrate (any variant)
#   2. mysql / ssh commands whose text contains DDL keywords
#   3. feeding any .sql file into mysql (directly or over ssh) via <, |, cat,
#      or `source` — the file contents aren't visible to the hook, so any
#      .sql-into-mysql is treated as a schema change and blocked.
# Read-only queries (SELECT ... -e) are unaffected.
# Only active on the production machine (hostname "docker"); no-op on CI/dev.

if [ "$(hostname)" != "docker" ]; then
    exit 0
fi

# Tool input arrives as JSON on stdin; older harnesses used an env var.
INPUT=$(cat 2>/dev/null)
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // empty' 2>/dev/null)
if [ -z "$COMMAND" ]; then
    COMMAND="${CLAUDE_TOOL_INPUT_command:-}"
fi
if [ -z "$COMMAND" ]; then
    exit 0
fi

block () {
    echo "BLOCKED: $1 Schema changes on production are run manually by the operator only — show the operator the SQL and ask them to apply it."
    exit 2
}

# 1. artisan migrate, in any form (docker exec, ssh, flags between words).
if echo "$COMMAND" | grep -qiE 'artisan\s+(migrate|db:wipe|schema)'; then
    block "artisan migrate/schema commands must NEVER be run by Claude here."
fi

# Does this command talk to a database at all (mysql client, locally or via ssh)?
TALKS_TO_DB=false
if echo "$COMMAND" | grep -qiE '\bmysql\b|\bmariadb\b'; then
    TALKS_TO_DB=true
fi

if [ "$TALKS_TO_DB" = true ]; then
    # 2. DDL / destructive schema keywords in the command text itself.
    #    (`SHOW CREATE TABLE` is a read — drop the SHOW CREATE form first so it
    #    doesn't false-positive as CREATE TABLE.)
    DDL_SCAN=$(echo "$COMMAND" | sed -E 's/SHOW[[:space:]]+CREATE//Ig')
    if echo "$DDL_SCAN" | grep -qiE '\b(CREATE|ALTER|DROP|RENAME)[[:space:]]+(TABLE|DATABASE|INDEX|VIEW|TRIGGER|FUNCTION|PROCEDURE|EVENT)\b|\bTRUNCATE\b|\b(ADD|DROP|MODIFY|CHANGE)[[:space:]]+(COLUMN|KEY|CONSTRAINT|FOREIGN)\b'; then
        block "DDL against the production database is not allowed from Claude."
    fi
    # 3. Any .sql file being fed into mysql: redirect, pipe, cat, or source.
    #    The hook can't see inside the file, so all of these count as DDL.
    if echo "$COMMAND" | grep -qiE '(<|\bcat\b|\|)[^;&]*\.sql\b|\.sql\b[^;&]*\|\s*(ssh\s+\S+\s+)?["'"'"']?(mysql|mariadb)\b|\bsource\s+\S+\.sql\b'; then
        block "piping a .sql file into the production database is not allowed from Claude."
    fi
fi

exit 0
