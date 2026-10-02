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

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

INPUT=$(cat)
COMMAND=$(echo "$INPUT" | jq -r '.tool_input.command // empty')
[ -z "$COMMAND" ] && exit 0

# Discourse host: the real forum is discourse.ilovefreegle.org. Honour DISCOURSE_URL
# from the project .env if it points somewhere else, but always cover the real host.
ENV_FILE="$(dirname "$0")/../.env"
DISCOURSE_URL=""
[ -f "$ENV_FILE" ] && DISCOURSE_URL=$(grep -E '^DISCOURSE_URL=' "$ENV_FILE" | cut -d= -f2- | tr -d '"'"'"'')
CONFIGURED_HOST=$(echo "$DISCOURSE_URL" | sed 's|https\?://||; s|/.*||')

# Build the regex of Discourse hosts we cover: the real forum, plus any host
# configured via DISCOURSE_URL (regex-escaped).
HOSTS='discourse\.ilovefreegle\.org'
if [ -n "$CONFIGURED_HOST" ]; then
  CH_ESC=$(printf '%s' "$CONFIGURED_HOST" | sed 's/[][\.*^$/]/\\&/g')
  HOSTS="$HOSTS|$CH_ESC"
fi

# Only act on commands that hit a Discourse host.
echo "$COMMAND" | grep -qiE "($HOSTS)" || exit 0

# ---------------------------------------------------------------------------
# PLAIN ENGLISH
#
# A Discourse reply is read once, usually on a phone, by a volunteer who does not
# work on the code. It gets the same complexity scorer the PR hook uses, tuned
# harder: grade 11 rather than 13, sentences of 30 words rather than 40.
#
# Only MY words are scored. The [quote=...] blocks are the other person's writing
# and I cannot rewrite those, so they are stripped first - otherwise quoting a long
# rambling report would fail my own reply.
#
# Resolving the text: heredoc bodies in the command (how these replies are written),
# then any @file a data flag points at, read as JSON (.raw / .post.raw) or plain. If
# a body-bearing call has text I cannot resolve, this BLOCKS rather than passing -
# the lesson check-pr-text.sh learned the hard way, that a hook which silently
# inspects nothing is worse than no hook.
check_plain_english() {
  local text f
  # Heredoc bodies: everything between <<'TAG' and the closing TAG.
  text=$(echo "$COMMAND" | awk '
    tag != "" { if ($0 ~ ("^[[:space:]]*" tag "[[:space:]]*$")) { tag=""; next } print; next }
    match($0, /<<-?["'"'"']?[A-Za-z_][A-Za-z0-9_]*["'"'"']?/) {
      t = substr($0, RSTART, RLENGTH); sub(/^<<-?/, "", t); gsub(/["'"'"']/, "", t); tag = t
    }
  ')
  # Any @file a data flag points at (unexpanded shell vars simply will not exist).
  for ref in $(echo "$COMMAND" | grep -oE '@[^[:space:]"'"'"']+'); do
    f="${ref#@}"
    [ -f "$f" ] || continue
    text="$text
$(jq -r '.raw // .post.raw // empty' "$f" 2>/dev/null || cat "$f" 2>/dev/null)"
  done

  # Strip what is not my prose: quoted blocks, code fences, bare URLs, list bullets.
  text=$(printf '%s\n' "$text" \
    | perl -0777 -pe 's/\[quote=.*?\[\/quote\]//gs' \
    | perl -0777 -pe 's/```.*?```//gs' \
    | sed -E 's#https?://[^ )]*##g; s/^[[:space:]]*[-*][[:space:]]+//')

  if [ -z "$(printf '%s' "$text" | tr -d '[:space:]')" ]; then
    cat >&2 <<'EOF'
STOP. I cannot read the text of this Discourse post, so I cannot check it is plain English.

Write the body in a heredoc in the same command, then reference the file:

  cat > "$SP/reply.md" <<'BODY'
  [quote="..."]...[/quote]
  Your reply.
  BODY
  jq -n --rawfile raw "$SP/reply.md" '{topic_id:N, raw:$raw}' > "$SP/payload.json"
  curl ... -d @"$SP/payload.json"
EOF
    exit 2
  fi

  local problems
  problems=$(printf '%s\n' "$text" \
    | PROSE_MAX_GRADE=11 PROSE_MAX_SENTENCE_WORDS=30 node "$SCRIPT_DIR/pr-complexity.mjs" 2>/dev/null)
  [ -z "$problems" ] && return 0

  cat >&2 <<EOF
STOP. This Discourse post is not plain enough for the people who will read it.

$problems

Volunteers read this once, on a phone. Short sentences, ordinary words, one idea each.
Say what happens, not how it is implemented. (See memory: feedback_keep_discourse_replies_short)
EOF
  exit 2
}

# Post EDITS (PUT <host>/posts/<id>.json) carry no quote obligation - the quote is
# already in the post being edited - but the prose still has to be readable, and an
# edit is exactly how a reply gets rewritten.
if echo "$COMMAND" | grep -qiE "($HOSTS)/posts/[0-9]+(\.json)?" \
   && echo "$COMMAND" | grep -qiE '(-X[[:space:]]*PUT|--request[[:space:]]*PUT)'; then
  check_plain_english
  exit 0
fi

# Must be a CREATE on the reply endpoint: <host>/posts.json or <host>/posts.
# Anchoring /posts DIRECTLY to the host is what distinguishes a reply-create from the
# read/edit endpoints that also contain the word "posts" — those must NOT be blocked:
#   <host>/t/<id>/posts.json       topic listing (GET)  -> host is followed by /t/, not /posts
#   <host>/posts/by_number/<t>/<n> single-post read(GET)-> /posts is followed by '/', excluded
#   <host>/posts/<id>.json         post edit  (PUT)     -> /posts is followed by '/', excluded
echo "$COMMAND" | grep -qiE "($HOSTS)/posts(\.json)?([^/a-z0-9]|\$)" || exit 0

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
if echo "$PAYLOAD" | grep -qiE '("title"[[:space:]]*:|[^a-z0-9_]title=)' && \
   ! echo "$PAYLOAD" | grep -qiE 'reply_to_post_number'; then
  exit 0
fi

# The rule: a reply must carry a [quote=...] block.
if echo "$PAYLOAD" | grep -qiE '\[quote='; then
  check_plain_english
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
