#!/bin/bash
# Hook (PreToolUse / Bash): ENFORCE that every Discourse reply Claude posts
# includes a [quote=...] block excerpting the post being answered.
#
# Why this exists: posting AI-Edward replies to Discourse is allowed, but a bare
# "@User fix applied, please retest" on a multi-bug thread leaves the reporter
# unable to tell WHICH of their reports is being addressed. The memory note
# feedback_monitor_quote_in_discourse_replies.md required this from 2026-04-17,
# but a note is not enforcement — bare replies kept going out (e.g. 9786/24 on
# 2026-06-18). This hook makes the omission impossible: a reply-create POST that
# has no [quote=...] block is BLOCKED (exit 2) before it leaves the machine.
#
# What is blocked: a POST that CREATES a post on an existing topic (a reply) whose
# payload lacks a [quote= block.
# What is allowed: posts that include a [quote= block; new-TOPIC creates (a title,
# nothing to quote); post EDITS (PUT /posts/<id>.json — used to add a missing
# quote retroactively); anything not touching the Discourse host.

INPUT=$(cat)
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // empty')
[ -z "$COMMAND" ] && exit 0

# Discourse host: the real forum is discourse.ilovefreegle.org. Honour DISCOURSE_URL
# from the project .env if it points somewhere else, but always cover the real host.
ENV_FILE="$(dirname "$0")/../.env"
DISCOURSE_URL=""
[ -f "$ENV_FILE" ] && DISCOURSE_URL=$(grep -E '^DISCOURSE_URL=' "$ENV_FILE" | cut -d= -f2- | tr -d '"'"'"'')
CONFIGURED_HOST=$(echo "$DISCOURSE_URL" | sed 's|https\?://||; s|/.*||')

# Only act on commands that hit a Discourse host.
echo "$COMMAND" | grep -qi 'discourse\.ilovefreegle\.org' && HIT=1 || HIT=0
if [ -n "$CONFIGURED_HOST" ] && echo "$COMMAND" | grep -qiF "$CONFIGURED_HOST"; then HIT=1; fi
[ "$HIT" = 0 ] && exit 0

# Must be a CREATE on /posts.json (or /posts). Edits are /posts/<id>.json — leave
# those alone (the slash-id before .json means it won't match /posts.json).
echo "$COMMAND" | grep -qiE '/posts(\.json|[?"'"'"' ]|$)' || exit 0

# Must be a POST: explicit -X POST/--request POST, or any data flag (curl -d et al.
# default to POST). A bare GET (no data, no -X POST) is not a create.
IS_POST=0
echo "$COMMAND" | grep -qiE '(-X[[:space:]]*POST|--request[[:space:]]*POST)' && IS_POST=1
echo "$COMMAND" | grep -qiE '(^|[[:space:]])(-d|--data|--data-raw|--data-binary|--data-urlencode)([[:space:]]|=)' && IS_POST=1
[ "$IS_POST" = 0 ] && exit 0

# Assemble the full payload text: the command itself plus the contents of any
# @file referenced by a data flag (so a quote inside a file isn't missed).
PAYLOAD="$COMMAND"
for ref in $(echo "$COMMAND" | grep -oE '@[^[:space:]"'"'"']+'); do
  f="${ref#@}"
  [ -f "$f" ] && PAYLOAD="$PAYLOAD
$(cat "$f" 2>/dev/null)"
done

# New-TOPIC creation has a title and no reply target — nothing to quote, allow it.
if echo "$PAYLOAD" | grep -qiE '("title"[[:space:]]*:|[[:space:]]title=)' && \
   ! echo "$PAYLOAD" | grep -qiE 'reply_to_post_number'; then
  exit 0
fi

# The rule: a reply must carry a [quote=...] block.
if echo "$PAYLOAD" | grep -qiE '\[quote='; then
  exit 0
fi

cat >&2 <<'EOF'
STOP. This Discourse reply has no [quote=...] block.

Every reply you post MUST start with a quote of the post you're answering, so the
reporter can tell which of their reports the fix addresses (multi-bug threads):

  [quote="<OriginalPoster>, post:<N>, topic:<TopicID>"]
  <one-sentence telling excerpt of their report>
  [/quote]

  @<Username> Fix applied for <plain-English symptom>. Please retest.

Fetch the original first: GET /posts/by_number/{topic}/{post}.json -> .raw, pick the
excerpt, prepend the quote block, then re-issue the POST.
(See memory: feedback_monitor_quote_in_discourse_replies.md)
EOF
exit 2
