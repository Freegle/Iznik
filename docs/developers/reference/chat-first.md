---
last_reviewed: 2026-09-12
owner: Freegle dev team
covers:
  - iznik-server-go/assistant/**
  - iznik-nuxt3/layouts/chat.vue
  - iznik-nuxt3/nginx-proxy.conf
  - iznik-nuxt3/components/chatshell/**
  - iznik-nuxt3/pages/browse/**
  - iznik-nuxt3/stores/assistant.js
  - iznik-nuxt3/api/AssistantAPI.js
  - iznik-nuxt3/composables/useUiMode.js
  - iznik-nuxt3/composables/useHostActions.js
  - iznik-nuxt3/composables/yourposts.js
  - iznik-nuxt3/composables/useNavbarVisibility.js
  - iznik-nuxt3/tests/e2e/test-chat-shell-landing.spec.js
  - iznik-nuxt3/tests/e2e/test-chat-shell-give.spec.js
  - iznik-nuxt3/tests/e2e/test-chat-shell-chats.spec.js
  - iznik-nuxt3/tests/e2e/utils/uiMode.js
  - iznik-server-go/test/assistant_test.go
  - iznik-server-go/test/assistant_schema_test.go
  - iznik-server-go/newsfeed/newsfeed.go
  - iznik-server-go/tryst/tryst.go
  - iznik-batch/app/Services/PurgeService.php
---

# The chat shell and the Freegle assistant

Freegle has two front doors. **Classic** is the set of pages this documentation has
always described. **Chat** makes the whole site a WhatsApp-like chat: you land in a chat
with Freegle, and giving, asking, browsing, finding your community, your posts, member
chats and ChitChat all happen inside a phone-sized shell. Members choose between them
and the choice is remembered. Design and decisions: `plans/2026-09-08-chat-first-design.md`.

## Choosing chat or classic

`composables/useUiMode.js` decides the mode once per page load, in shared state carried
from the server to the browser, and only `setMode` changes it afterwards (a cookie coming or going
mid-page must not flip the interface). The order is: the member's `settings.uiMode`, then the
`freegle-ui-mode` cookie (so the server renders the same choice as the browser), then
the runtime default `CHAT_FIRST_DEFAULT` (`chat`, `classic`, or a percentage of members
by user id; also the kill switch). The shell's menu offers "Classic Freegle"; the classic
pages carry the reverse link. The production image caches server-rendered pages for a minute
(`nginx-proxy.conf`); the cache key includes the mode cookie, so a chat page is never served to
a classic request or the other way round. Pages that can render as the shell declare
`definePageMeta({ layout: false, chatShell: true })` and switch between
`<NuxtLayout name="chat">` and the classic layout; `useNavbarVisibility` hides the global
navbar on those routes while the member is on chat. The classic page bodies moved
unchanged into `components/Classic{Landing,Chats,ChitChat}.vue`.

## The shell

`layouts/chat.vue` and `components/chatshell/ShellFrame.vue`: full screen below 768px,
a 390 × min(844px, 92vh) column above it, with `transform: translateZ(0)` so fixed
children stay inside. `ShellHeader`, `ShellComposer` (persistent Give · Ask · Nearby row,
progress line during a flow), `ShellBubble`, `ShellChips` (at most three). `ChatPane` and
`ChatFooter` take an `embedded` prop so the shell's header and the column's height are
used instead of the classic ones.

Screens: `FreegleChat` (the assistant), `ChatList` (Freegle and Your posts pinned, ChitChat
as a group, people; filters All · Unread · People), `MemberChat` (the existing pane and
footer with a shell header), `YourPosts` (one chat of events about all your posts, with
`ChooserSheet` for who gets what and how many), `ChitChatGroup` (the newsfeed as a group
chat: one stream, replies quote what they answer, ❤ to love), `NearbySheet` (on
`ShellSheet`, the base `ChooserSheet` also uses: backdrop, handle, title, a body that
scrolls inside its own bounds). The sheet holds the search box, the All/Offers/Wanted
filter and one `PostRow` per item, nearest first, ten more at a time, each opening in
place with Reply. `/browse` in chat mode renders the chat with the sheet up. The
transcript never holds post cards: what a look around, a search or the matches before an
Ask found is one `PostStrip` (count, three thumbnails, Look), and the landing's sample
offers are the same strip. The rows behind both live in `assistant.nearby`, filled by
`useHostActions.fetchNearby`; `assistant.cards` carries only the top three and the
count. Decision note: `plans/2026-09-12-nearby-sheet-design.md`.

## The assistant

`iznik-server-go/assistant` is a Go port of the engine core of
[ai-flower](https://github.com/Freegle/ai-flower). The workflow definition,
`assistant/workflows/freegle.json`, is ai-flower's JSON format and can be edited in
ai-flower's Vue editor; `GET /assistant/workflow` serves it. States are the questions
(`GIVE_PHOTO`, `GIVE_ITEM`, …, `ASK_*`, `NEARBY`, `COMMUNITY`, `HELP`), transitions are
the only moves allowed.

One endpoint does the work: `POST /assistant/turn` with `{conversation, text | tap |
event}`, answering with server-sent events (`identity`, `delta`, `turn`). The turn runs on its own
goroutine while the stream carries a comment every five seconds, so a proxy or a phone does not
drop a silent stream; a panic ends as an `error` event. If the browser loses the end of a reply it
sends one `resume` event, which returns the current state, chips and progress without saying or
moving anything (`api/AssistantAPI.js`). Taps and host
events move the flow deterministically (`host.go`: chips, `NextAfter`, `TargetForChip`,
`ApplyEvent`). Typed text goes through the rules first (`rules.go`: commands, intents,
postcode, email, quantity, the item validation mirrored from
`composables/useItemValidation.js`); only when rules cannot decide does the model
propose one of the legal transitions (`engine.go`, `Decide`).

The model composes every reply (`compose.go`, `llm.go`: Anthropic Messages API,
`ASSISTANT_MODEL` default `claude-opus-5`, `ASSISTANT_EFFORT` default `low`, streamed,
the stable prefix cached). `ANTHROPIC_API_KEY` is the production credential. For local work through a Claude Code gateway such as the broker, leave the key empty and set `ASSISTANT_GATEWAY_TOKEN` (its bearer token), `ASSISTANT_GATEWAY_URL` (its address from inside the container) and `ASSISTANT_SYSTEM_PREFIX` (the Claude Code identity line a subscription expects, sent as the first system block). Compose passes the first two to apiv2 as `ANTHROPIC_AUTH_TOKEN` and `ANTHROPIC_BASE_URL`, which the SDK reads; they are named apart so a shell running Claude Code through the same gateway does not leak its settings into the container. The voice is `assistant/voice/character.md`; the facts it may
rely on are `voice/facts.md` plus the context of the conversation. `check.go` stands
between the model and the member: no exclamation marks, no emoji, no banned phrases, no
promises Freegle cannot keep, and every number and proper noun must appear in the facts,
what the member said, or the fact sheet. A trip regenerates once; a second trip falls
back to `templates.go`. No key configured means template lines only.

Escalation (`escalation.go`) follows Answerbot's count-based levels for wandering turns;
three abusive verdicts in an hour downgrade an identity to templates. Quotas
(`quota.go`): 60 model turns an hour per identity and per IP, daily caps
`ASSISTANT_ANON_DAILY_CAP` and `ASSISTANT_DAILY_CAP`. Anonymous visitors carry a signed
token (`identity.go`) in `X-Assistant-Anon`.

### The browser does the work

The service is the brain and the browser is the hands (`composables/useHostActions.js`):
a `hostAction` in a turn tells the browser to look up a postcode, check an email, post
through the compose store, find matches, list nearby or communities, search, sign in, or
open a route; the browser reports back with an `event` (`photo_added`,
`postcode_confirmed`, `email_confirmed`, `posted`, `matches`, …). Nothing the model says
is ever published; posts, replies and joins go through the existing stores and APIs.

### Transcript

Every turn is written into the member's real Freegle chat room (`transcript.go`: the one
User2User room with the Freegle system user, as the first-reply code creates it) as
`chat_messages`; chips and cards go to `chat_widgets`, keyed by message id, so ModTools
and older apps render the text and the shell renders the widgets. A visitor's turns are
held on the instance and written when an account exists.

### Data

`assistant_instances` (one row per open conversation), `chat_widgets`,
`messages_promises.count` (how many were promised to a person), `trysts.msgid` (which
post a collection time is for), and `heldreplies` on the owner's post record (replies
waiting for a volunteer). `composables/yourposts.js` turns the post records into the
events Your posts shows, orders repliers by a fixed score with its reasons shown, and
prefills a split.

## When a chat resets

The transcript stays on the device like any chat, capped at a few hundred lines, and on the
server until the instance is pruned after thirty days idle. "Start again" in the menu and
signing out clear it. A give or ask left half-done for more than an hour lapses: the next turn
is met at the hub with a line naming what was left unfinished (`flowLapse` in
`conversation.go`), and the flow starts afresh if they pick it up.

## Abuse, limits and races

The engine takes only edges the workflow defines. A tap, event or rule that names a state
with no edge from the current one is logged and ignored (`conversation.go`, `move`); an
`edit:` tap accepts only the flow's own questions. `TestAsstHostMovesAreLegal` walks every
state, chip, event and skip against the graph, so a missing edge fails the build rather than
a member. End states (`CANCELLED`) are never rested in: the conversation returns to the hub
with a note, whether the host or the model ended the flow.

Turns on one conversation run one at a time (an in-process lock keyed by conversation id),
and `create_post` carries a key the browser runs once, so a double tap or a second tab
cannot post twice. Each model call has a 60 second deadline; each identity may hold two
streams open at once.

Quotas (`quota.go`): 60 model turns an hour per identity, ten times that per address, 60
a day per anonymous identity and 300 per member, then the sitewide daily caps
(`ASSISTANT_ANON_DAILY_CAP`, `ASSISTANT_DAILY_CAP`), which are the spend breaker.
Identities are rationed too: 600 new anonymous tokens an hour per address and 3000
sitewide; over that a visitor gets a shared, model-free identity and the template lines.
The address used is the last hop of `X-Forwarded-For`, the one our own proxy wrote, never
the first, which the client chooses. Anonymous tokens lapse after 30 days. These counters
live in the apiv2 process, so they are per replica.

A member's own lines to Freegle get the worry-word check any chat message gets and are held
for review on a hit. ChitChat posts and edits get the same check; a hit takes the post out
of the feeds and tells the ChitChat volunteers through the existing report path. Idle
conversations are pruned after 30 days by `purge:chats` (`PurgeService::purgeAssistantInstances`).

A conversation belongs to the identity that started it. A visitor who signs in part way through
(posting creates the account) keeps it: the browser sends the member token and the anonymous one
together, and the instance is adopted by the member (`instanceFor`, `Identity.WasKey`). Anyone
else naming that conversation id gets a fresh one.

Signing out, from the shell's menu or the classic navbar, forgets the chat on that device
(`stores/assistant.js`, `forget`, watching the signed-in id); a visitor who signs in keeps
theirs, because that is the flow continuing.

## Tests

Go: `assistant/*_test.go` (rules, check, host, engine, quotas, identity, streaming
extractor, conversation flows with a fake model), `test/assistant_test.go` (the endpoint
through the app), `test/assistant_schema_test.go`. Vitest: `tests/unit/components/chatshell`,
`stores/assistant`, `api/AssistantAPI`, `composables/{useUiMode,yourposts,useNavbarVisibility}`.
Laravel: `PurgeServiceTest::test_purge_assistant_instances_removes_only_idle_ones`.
Playwright: `tests/e2e/test-chat-shell-*.spec.js` run on the chat front door; every other
spec starts with the classic cookie (`tests/e2e/utils/uiMode.js`, set by the fixtures and put back
whenever the helpers clear cookies).
