#!/bin/bash
# Tests for check-discourse-post.sh. Run: bash .claude/check-discourse-post.test.sh
# Each case feeds a synthetic tool_input.command and asserts the exit code.
#   0 = allowed, 2 = blocked.

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOOK="$SCRIPT_DIR/check-discourse-post.sh"
PASS=0
FAIL=0

run_case() {
  local name="$1" want="$2" cmd="$3"
  local got
  jq -n --arg c "$cmd" '{tool_input:{command:$c}}' | bash "$HOOK" >/dev/null 2>&1
  got=$?
  if [ "$got" = "$want" ]; then
    PASS=$((PASS + 1))
    echo "ok   - $name"
  else
    FAIL=$((FAIL + 1))
    echo "FAIL - $name (wanted exit $want, got $got)"
  fi
}

HD="'BODY'"
PLAIN='No. She is only joined to a community when her own post travels there. That is what lets people there write back to her. Taking something from someone else never joins her to anything.'
DENSE='The outbound distance-preference resolution chain consults the member-scoped myPostsMaxDistance key before falling back to browseMaxDistance, deliberately excluding the inbound-only density-band default, so that a band-derived radius cannot propagate into the author-side reach cap enforcement clause.'

# A create with a quote and readable plain prose is fine.
run_case "create: quoted, plain" 0 "$(printf 'cat > /tmp/r.md <<%s\n[quote="X, post:4, topic:1"]\nthe report\n[/quote]\n\n%s\nBODY\ncurl -X POST https://discourse.ilovefreegle.org/posts.json -d @/tmp/p.json\n' "$HD" "$PLAIN")"

# A create with no quote is blocked, as before.
run_case "create: no quote" 2 "$(printf 'cat > /tmp/r.md <<%s\n%s\nBODY\ncurl -X POST https://discourse.ilovefreegle.org/posts.json -d @/tmp/p.json\n' "$HD" "$PLAIN")"

# A create whose prose is dense is blocked even though it is quoted.
run_case "create: quoted but dense" 2 "$(printf 'cat > /tmp/r.md <<%s\n[quote="X, post:4, topic:1"]\nthe report\n[/quote]\n\n%s\nBODY\ncurl -X POST https://discourse.ilovefreegle.org/posts.json -d @/tmp/p.json\n' "$HD" "$DENSE")"

# The quoted block is the other person's words, so a dense QUOTE must not fail my plain reply.
run_case "create: dense quote, plain reply" 0 "$(printf 'cat > /tmp/r.md <<%s\n[quote="X, post:4, topic:1"]\n%s\n[/quote]\n\n%s\nBODY\ncurl -X POST https://discourse.ilovefreegle.org/posts.json -d @/tmp/p.json\n' "$HD" "$DENSE" "$PLAIN")"

# A create with a body flag but no resolvable text blocks rather than passing silently.
run_case "create: unresolvable body" 2 'curl -X POST https://discourse.ilovefreegle.org/posts.json -d @"$SP/payload.json"'

# Edits are checked for prose but carry no quote obligation.
run_case "edit: plain, unquoted" 0 "$(printf 'cat > /tmp/r.md <<%s\n%s\nBODY\ncurl -X PUT https://discourse.ilovefreegle.org/posts/68528.json -d @/tmp/p.json\n' "$HD" "$PLAIN")"
run_case "edit: dense" 2 "$(printf 'cat > /tmp/r.md <<%s\n%s\nBODY\ncurl -X PUT https://discourse.ilovefreegle.org/posts/68528.json -d @/tmp/p.json\n' "$HD" "$DENSE")"

# Reads are untouched.
run_case "read: topic json" 0 'curl -s https://discourse.ilovefreegle.org/t/10157.json -o /tmp/t.json'
run_case "read: post by number" 0 'curl -s https://discourse.ilovefreegle.org/posts/by_number/10157/5.json'

# Nothing to do with Discourse.
run_case "unrelated command" 0 'curl -s https://example.com/posts.json -d @/tmp/p.json'

echo
echo "$PASS passed, $FAIL failed"
[ "$FAIL" = 0 ]
