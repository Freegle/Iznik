#!/bin/bash
# PreToolUse hook (Write|Edit): a trap about THIS codebase belongs in .claude/rules/,
# where the whole team gets it, not in a machine-local auto-memory file only one
# person will ever see.
#
# Why this exists: 598 auto-memory files accumulated on one laptop. A large part of
# them were findings about this repository - things that fail silently and cost a day
# each - and nobody else could read any of it. The fix was to publish them as
# path-scoped rules. This hook stops the pile rebuilding: when a memory write looks
# like a codebase trap, it points at the rule file that should hold it instead.
#
# What is BLOCKED: writing an auto-memory file whose content names this repo's source
# trees or file types, which is the signature of a finding about the code.
#
# What is ALLOWED, and must stay allowed - auto memory is the right home for it:
#   - preferences, corrections and working style (type: user / feedback)
#   - project status, decisions and in-flight work (type: project)
#   - pointers to things outside the repo: dashboards, tickets, hosts (type: reference)
#   - anything naming a host, key, member or support case. Those must NOT be published;
#     mine the mechanism into a rule by hand and leave the specifics in memory.
#
# Override: MEMORY_OK=1, meaning "I considered a rule and memory is genuinely right".

INPUT=$(cat)
TOOL=$(echo "$INPUT" | jq -r '.tool_name // empty' 2>/dev/null)
case "$TOOL" in
  Write|Edit|MultiEdit) ;;
  *) exit 0 ;;
esac

FILE=$(echo "$INPUT" | jq -r '.tool_input.file_path // empty' 2>/dev/null)
[ -z "$FILE" ] && exit 0

# Only auto-memory topic files. MEMORY.md is the index and is always fine to write.
case "$FILE" in
  */.claude/projects/*/memory/*.md) ;;
  *) exit 0 ;;
esac
case "$FILE" in */MEMORY.md) exit 0 ;; esac

[ "${MEMORY_OK:-}" = "1" ] && exit 0
echo "$INPUT" | jq -r '.tool_input.command // empty' 2>/dev/null | grep -q 'MEMORY_OK=1' && exit 0

CONTENT=$(echo "$INPUT" | jq -r '
  (.tool_input.content // "") + "\n" +
  (.tool_input.new_string // "") + "\n" +
  ((.tool_input.edits // []) | map(.new_string // "") | join("\n"))' 2>/dev/null)
[ -z "$(printf '%s' "$CONTENT" | tr -d '[:space:]')" ] && exit 0

# Does it name our source trees, or talk about our code in a trap-shaped way?
SOURCES='iznik-server-go|iznik-nuxt3|iznik-batch|status-nuxt|modtools/|\.circleci'
LOOKS_LIKE_CODE=0
echo "$CONTENT" | grep -qE "$SOURCES" && LOOKS_LIKE_CODE=1
[ "$LOOKS_LIKE_CODE" = 0 ] && exit 0

# Confidential specifics belong in memory, not in a published rule. If the note carries
# any, let it through: the mechanism can be lifted into a rule by hand, redacted.
if echo "$CONTENT" | grep -qE '\b([0-9]{1,3}\.){3}[0-9]{1,3}\b|root@|_APIKEY|_AUTH_TOKEN|[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}'; then
  exit 0
fi

# Suggest the rule file whose area this is about.
SUGGEST=".claude/rules/"
echo "$CONTENT" | grep -q 'iznik-server-go'      && SUGGEST=".claude/rules/go-api-traps.md"
echo "$CONTENT" | grep -q 'iznik-batch'          && SUGGEST=".claude/rules/laravel-batch-traps.md"
echo "$CONTENT" | grep -q 'iznik-nuxt3'          && SUGGEST=".claude/rules/frontend-traps.md"
echo "$CONTENT" | grep -qE 'test|spec|CI|circleci' && SUGGEST=".claude/rules/tests-and-ci.md"
echo "$CONTENT" | grep -qE 'container|worktree|file-sync|compose' && SUGGEST=".claude/rules/dev-containers.md"

cat >&2 <<EOF
STOP. This reads like a finding about THIS codebase, so it belongs in a rule the whole
team gets, not in a memory file on one machine.

  $FILE

Put it here instead, and the right people see it automatically:

  $SUGGEST

Those files declare the paths they cover, so Claude Code loads them when someone opens a
file in that area, and a person reading the repo finds them too. That is the whole reason
they exist: a memory only helps the machine it was written on.

Keep using auto memory for what it is good at - your preferences, corrections, project
status, and anything naming a host, key, member or support case, which must not be
published at all.

If memory really is right for this, prefix the command with MEMORY_OK=1.
EOF
exit 2
