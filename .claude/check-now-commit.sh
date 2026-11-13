#!/bin/bash
# PreToolUse hook for git commit: prompt when the commit adds SQL that reads the database's clock.
#
# NOW() and CURRENT_TIMESTAMP do not mean the same thing on the two engines. On MySQL they are the
# time the STATEMENT started. On PostgreSQL they are the time the TRANSACTION started, so every
# statement in one transaction gets the same value, and it can be earlier than a time the
# application took a moment ago. A row stamped NOW() inside a transaction and then compared with a
# time from the application's clock can therefore fall on the wrong side of it: no error, and a
# member silently not mailed (ResumedReachTest, 2026-11-13). MySQL also keeps whole seconds where
# PostgreSQL keeps microseconds, so two times that tie on MySQL are ordered on PostgreSQL.
#
# WARNING ONLY - it never blocks. Most uses are harmless. The point is that the author looks at
# the ones that are compared with another clock. docs/developers/reference/database-engines.md,
# "The database's clock", has the detail.

INPUT=$(cat)
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // ""' 2>/dev/null)

# Only fire for git commit.
if ! echo "$COMMAND" | grep -qE '\bgit\s+commit\b'; then
  exit 0
fi

. "$(dirname "$0")/lib/commit-repo.sh"
REPO_ROOT=$(commit_repo_dir "$COMMAND" "$(echo "$INPUT" | jq -r '.cwd // ""' 2>/dev/null)") || exit 0
[ -n "$REPO_ROOT" ] || exit 0
cd "$REPO_ROOT" 2>/dev/null || exit 0

# What is about to be committed. `git commit -a` sweeps in tracked-but-unstaged changes too.
DIFF=$(git diff --cached -U0 2>/dev/null)
if echo "$COMMAND" | grep -qE '\bgit\s+commit\b[^|;&]*\s-[a-zA-Z]*a'; then
  DIFF="$DIFF"$'\n'$(git diff -U0 2>/dev/null)
fi
[ -z "$DIFF" ] && exit 0

# Added lines of Go, PHP and SQL that name a database clock. The names are matched in upper case
# only, as SQL is written here: PHP's now() helper and Go's time.Now() are the application's clock
# and are not what this is about. A name that follows a letter, a dot or an arrow is part of
# something else (time.Now, ->NOW, SNOW). A bare > is a comparison and does count (ts>NOW()).
REPORT=$(echo "$DIFF" | awk '
  /^\+\+\+ b\// { file = substr($0, 7); next }
  /^\+/ && !/^\+\+\+/ {
    if (file !~ /\.(go|php|sql)$/) next
    line = substr($0, 2)
    if (line ~ /^[[:space:]]*(\/\/|#|\*|--)/) next          # comments
    if (line !~ /(^|[^A-Za-z_.>]|[^-]>)(NOW|CURDATE|CURTIME|SYSDATE|UTC_TIMESTAMP)[[:space:]]*\(/ &&
        line !~ /(^|[^A-Za-z_.>]|[^-]>)(CURRENT_TIMESTAMP|CURRENT_DATE|CURRENT_TIME)([^A-Za-z_]|$)/) next
    gsub(/^[[:space:]]+/, "", line)
    if (length(line) > 110) line = substr(line, 1, 110) "..."
    hits[file] = hits[file] "      " line "\n"; n++
  }
  END {
    if (n == 0) exit 1
    printf "  %d added line(s) read the database clock:\n", n
    for (f in hits) printf "    %s\n%s", f, hits[f]
  }')

[ -z "$REPORT" ] && exit 0

MSG="Database clock check: this commit adds SQL that reads the database's clock.

$REPORT
NOW() and CURRENT_TIMESTAMP are the time the statement started on MySQL and the time the TRANSACTION started on PostgreSQL. Before committing, check each line above:

  1. Is the value compared with a time from the application's clock (PHP now(), Go time.Now(), a stored mark)? Inside a transaction PostgreSQL's value can be earlier than that time. Use one clock on both sides: bind the application's time, or use the statement's time.
  2. Does it run inside a transaction (DB::transaction, db.Transaction, or a test, which is one long transaction)? Then PostgreSQL gives every statement the same value. Laravel: App\\Database\\Expressions\\Now renders the statement's time on both engines.
  3. Does anything rely on two times being equal or ordered? MySQL keeps whole seconds and PostgreSQL microseconds, so a tie on MySQL is an order on PostgreSQL.

See docs/developers/reference/database-engines.md, \"The database's clock\". Warning only - nothing is blocked."

# systemMessage shows it to the person; additionalContext puts it in front of the agent making the commit.
jq -n --arg m "$MSG" '{systemMessage: $m, hookSpecificOutput: {hookEventName: "PreToolUse", additionalContext: $m}}'
exit 0
