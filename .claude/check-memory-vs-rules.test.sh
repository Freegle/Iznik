#!/bin/bash
# Tests for check-memory-vs-rules.sh.  bash .claude/check-memory-vs-rules.test.sh
# 0 = allowed, 2 = blocked.

HOOK="$(cd "$(dirname "$0")" && pwd)/check-memory-vs-rules.sh"
MEM="/home/x/.claude/projects/-repo/memory"
pass=0; fail=0

check() { # name want tool file content
  local name="$1" want="$2" got
  jq -n --arg t "$3" --arg f "$4" --arg c "$5" \
    '{tool_name:$t, tool_input:{file_path:$f, content:$c}}' | bash "$HOOK" >/dev/null 2>&1
  got=$?
  if [ "$got" = "$want" ]; then printf '  PASS  %s\n' "$name"; pass=$((pass+1))
  else printf '  FAIL  %s (exit %s, wanted %s)\n' "$name" "$got" "$want"; fail=$((fail+1)); fi
}

check "codebase trap is blocked" 2 Write "$MEM/finding_x.md" \
  "GORM Order(clause.Expr) is dropped in iznik-server-go with no error."
check "frontend trap is blocked" 2 Write "$MEM/finding_y.md" \
  "iznik-nuxt3 SpinButton spins 20s when the handler returns early."
check "Edit of a memory is blocked too" 2 Edit "$MEM/finding_z.md" \
  "iznik-batch Http::fake merges rather than replaces."

check "a preference is allowed" 0 Write "$MEM/feedback_style.md" \
  "Edward prefers short Discourse replies with no preamble."
check "project status is allowed" 0 Write "$MEM/project_thing.md" \
  "The rippling rollout is paused until the reach engine lands."
check "a note with a host is allowed" 0 Write "$MEM/reference_host.md" \
  "The batch host is 10.0.0.1 and iznik-batch runs there; ssh root@10.0.0.1."
check "a note with an email is allowed" 0 Write "$MEM/reference_mail.md" \
  "iznik-batch sends from someone@example.org for the digest."
check "MEMORY.md index is allowed" 0 Write "$MEM/MEMORY.md" \
  "- iznik-server-go traps. finding_x"
check "a file outside memory is allowed" 0 Write "/repo/iznik-server-go/x.go" \
  "package main // iznik-server-go"
check "a non-edit tool is ignored" 0 Bash "$MEM/finding_x.md" \
  "iznik-nuxt3 something"
check "empty content is allowed" 0 Write "$MEM/finding_empty.md" ""

echo
echo "  $pass passed, $fail failed"
[ "$fail" = "0" ]
