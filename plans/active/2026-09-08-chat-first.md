# Chat-first Freegle Implementation Plan (v3)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Freegle as a WhatsApp-familiar chat app: land in a chat with Freegle; give, ask, browse, explore, your posts, member chats and ChitChat inside a chat shell; ai-flower keeps every flow on rails; the model composes the wording in a Freegle voice; members can switch to classic.

**Architecture:** A Node `assistant/` service runs ai-flower workflows (host-driven for taps, model-proposed for typed text) with an Anthropic adapter, writes every turn into the member's real Freegle chat room through apiv2, and is reached through apiv2 which owns identity, quotas and strikes. The Nuxt shell renders the transcript and widgets and reuses today's stores and chat components. Three small schema additions: `messages_promises.count`, `trysts.msgid`, `chat_widgets`.

**Tech Stack:** Nuxt 4 / Vue 3 / Pinia / bootstrap-vue-next; Node 20 + ai-flower + @anthropic-ai/sdk + better-sqlite3; Go fiber (apiv2); Laravel migrations; vitest, Go testing, Playwright.

Design: `plans/2026-09-08-chat-first-design.md` (v3).

---

## Status

| # | Task | Status | Notes |
|---|---|---|---|
| 1 | Assistant service: ai-flower engine, workflows, voice composer, fabrication check, escalation, fallback, tests | ⬜ | |
| 2 | apiv2: proxy + identity + quotas + strikes; transcript and widget endpoints; schema additions; held replies; tests | ⬜ | |
| 3 | Shell: layout, frame, header, composer, widget renderer, Freegle chat page, classic toggle, landing | ⬜ | |
| 4 | Give and Ask flows end to end through the service (photo, item, description, quantity, where, email, confirm, post) | ⬜ | |
| 5 | Nearby and Community flows (cards, search, expand, reply pane; communities, join) | ⬜ | |
| 6 | Chat list with filters; member chats inside the shell (embedded mode) | ⬜ | |
| 7 | Your posts chat: events, post cards, chooser sheet, splits, actions | ⬜ | |
| 8 | ChitChat as a group chat; ChitChat screening job | ⬜ | |
| 9 | Playwright: new shell specs, homepage rewrite, full suite green | ⬜ | |
| 10 | Docs (members, developer reference, moderators paragraph), screenshots, freshness check, lint | ⬜ | |
| 11 | Adversarial review + red-team tests; headless Chrome visual review + Lighthouse a11y; voice evaluation | ⬜ | |
| 12 | PR | ⬜ | |

## File structure

### assistant/ (new Node service, ESM, TypeScript via tsx or plain JS with JSDoc)
- `package.json` deps: `ai-flower` (github:freegle/ai-flower), `@anthropic-ai/sdk`, `better-sqlite3`, `express`; dev: `vitest`, `supertest`.
- `src/server.js` express app: `POST /turn {conversationId, identity, text}` (SSE stream of `{say}` deltas then a final `{turn}` event), `POST /tap {conversationId, identity, chip}`, `GET /health`. Trusts an `X-Assistant-Identity` header set by apiv2 only (shared secret `ASSISTANT_INTERNAL_SECRET`).
- `src/engine.js` builds one `WorkflowEngine` per workflow with `SQLiteStorage`; `conversations.js` maps conversationId → instance id + workflow.
- `src/workflows/{hub,give,ask,nearby,community,help,yourposts}.json` ai-flower definitions (editable in the Vue editor).
- `src/voice/character.md` the character sheet and worked examples; `src/voice/facts.md` the Freegle fact sheet.
- `src/voice/compose.js` builds the per-turn prompt (character + state task + legal transitions + facts + message) and parses the JSON reply `{say, slots, transition, chips}`; streams `say`.
- `src/voice/check.js` fabrication and style check: entities in `say` must appear in facts; banned promises; no exclamation marks, no emoji, ≤ 2 sentences unless the state allows more; returns `{ok, reasons}`.
- `src/voice/templates.js` fallback lines per state.
- `src/escalation.js` count-based rambling levels and abuse handling per conversation.
- `src/actions/*.js` read and write actions calling apiv2 with the member's JWT forwarded: `recognise_photo`, `check_item`, `lookup_community`, `find_matches`, `create_post`, `list_nearby`, `search`, `list_communities`, `join_community`, `my_posts`, `promise`, `outcome`, `repost`, `withdraw`.
- `src/transcript.js` writes turns and widgets through apiv2 internal endpoints.
- `tests/*.test.js`: workflow definitions validate; every chip has a legal host transition; typed multi-slot turn; escalation levels; check trips and regenerate-once; fallback on adapter failure; SSE shape; fake Anthropic via injected adapter.
- `Dockerfile`; compose service `assistant` (profiles backend, dev, default), env `ANTHROPIC_API_KEY`, `ASSISTANT_MODEL` (default Opus 5), `ASSISTANT_INTERNAL_SECRET`, `APIV2_INTERNAL_URL`.

### iznik-server-go
- `assistant/proxy.go` `POST /assistant/turn`, `POST /assistant/tap`: resolve identity (JWT or signed anonymous cookie), quotas (`assistant/quota.go`), strikes, forward to the service with the identity header, stream the response back.
- `assistant/quota.go` token buckets per identity and IP, anonymous and authenticated daily caps, strike downgrade; `quota_test.go`.
- `assistant/transcript.go` internal endpoints (shared secret): `POST /assistant/internal/turn` appends a chat message from the Freegle user or the member into the member's Freegle room (creating the room on first use via the existing system-user path) and a `chat_widgets` row; `GET /chat/:id/widgets` for the shell.
- `message/`: `heldreplies` count on the owner's record; `messages_promises.count` in promise handling; `trysts.msgid` in tryst create/edit and returned.
- `router/routes.go` registrations; `test/assistant_*_test.go`.

### iznik-batch
- Migrations: `add_count_to_messages_promises`, `add_msgid_to_trysts`, `create_chat_widgets` (chatmsgid PK FK, kind VARCHAR(32), payload JSON, created_at) plus `*_migration.sql` idempotent copies.
- `app/Services/ChitChatScreeningService.php` + scheduled command: run the chat-message checks over new newsfeed rows, set `reviewrequired=1` on a hit. Tests.

### iznik-nuxt3
- `layouts/chat.vue`; `components/chatshell/{ShellFrame,ShellHeader,ShellComposer,Bubble,Chips,PostCard,GroupCard,ConfirmCard,PersonCard,ChooserSheet,PhotoBubble,ChatListRow,WidgetRenderer,FreegleChat,YourPosts,MemberChat,ChitChatGroup,ChitChatBubble}.vue`.
- `stores/assistant.js` (conversation ids, streaming state, pending chips; transcript itself comes from the chat store since the room is real).
- `composables/useUiMode.js` chat vs classic choice (settings + localStorage + `CHAT_FIRST_DEFAULT`).
- `composables/yourposts.js` event synthesis, chooser score and reasons, split defaults.
- `api/AssistantAPI.js` (SSE via fetch streams), `api/index.js` registration.
- Pages: `pages/index.vue` (mode switch: shell or classic landing), `pages/chats/[[id]].vue` (shell list or MemberChat when on chat; classic otherwise), `pages/chats/posts.vue`, `pages/chitchat/[[id]].vue` (shell or classic).
- `ChatPane.vue`, `ChatFooter.vue`: `embedded` prop.
- Tests: `tests/unit/components/chatshell/*.spec.js`, `tests/unit/composables/{useUiMode,yourposts}.spec.js`, `tests/unit/stores/assistant.spec.js`, rewrite `tests/unit/pages/index.spec.js`; e2e `tests/e2e/test-chat-shell-*.spec.js`, rewrite `test-homepage.spec.js`.

### docs
- `docs/developers/reference/chat-first.md` (new). Update `docs/members/getting-started.md`, `giving.md`, `getting.md`, one paragraph in `docs/moderators/*` chat review page, regenerate `docs/screenshots`.

## Tasks

### Task 1: Assistant service
- [ ] Load the `claude-api` skill before writing the adapter.
- [ ] Tests first: definitions validate (`new WorkflowEngine` does not throw); every chip declared in a state maps to a `host_driven` transition; `compose` prompt contains character, task, legal states, facts; parse of `{say,slots,transition,chips}`; `check` trips on a number absent from facts and on "we'll deliver"; regenerate once then template; escalation level 1/2/3 lines; SSE emits deltas then a final turn; fake adapter returns canned JSON.
- [ ] Implement service, workflows, voice, actions (against apiv2 test container), transcript writer, Dockerfile, compose entry.
- [ ] Run `npx vitest run` inside the service container. Commit.

### Task 2: apiv2 and schema
- [ ] Go tests: anonymous identity cookie minted and verified; quota buckets and caps; strikes downgrade; proxy forwards identity header and streams; transcript endpoint creates the Freegle room once and appends messages of the right author; widgets endpoint returns rows; `heldreplies` counts held Interested messages for the owner only; promise stores `count`; tryst stores and returns `msgid`.
- [ ] Laravel migrations and SQL; ChitChat screening service and command with tests.
- [ ] Run Go tests via status API `?filter=Assistant|Promise|Tryst|HeldReplies`, Laravel via status API. Commit.

### Task 3: Shell and Freegle chat
- [ ] Component specs; `useUiMode` spec (settings wins, then localStorage, then default flag and percentage); `FreegleChat` spec (renders transcript from the chat store, widgets from the side table, streams a turn, sends a tap, persistent action row, progress line during a flow, classic link).
- [ ] Implement layout, components, store, API, pages; landing keeps title, description, sample offers strip and bandit identifiers. Commit.

### Task 4: Give and Ask
- [ ] Service tests for the give and ask workflows (skips, multi-slot, confirm card chips, `create_post` only from POST, matches after ITEM, match resolves draft).
- [ ] Shell: photo via `OurUploader` mounted with `startOpen` from the composer button; `PostcodeInput`; `EmailInput`; `ConfirmCard`. e2e later. Commit.

### Task 5: Nearby and Community
- [ ] Service tests: `list_nearby` with and without location, `search`, `list_communities`, `join_community` logged out asks email first.
- [ ] Shell: `PostCard`, `GroupCard`, expand, reply pane inside the shell. Commit.

### Task 6: Chat list and member chats
- [ ] Rewrite `pages/chats/[[id]].vue` for chat mode; filters All/Unread/People; Freegle and Your posts pinned; `embedded` mode for `ChatPane`/`ChatFooter` with specs asserting column-relative sizing. Run `test-reply-to-chat` e2e. Commit.

### Task 7: Your posts
- [ ] `yourposts.spec.js`: events from records (reply, held, promised with tryst by msgid, after-time, partial, quiet, reposted divider, all gone); score and reasons; split defaults and confirm payload; "also interested in" cross-post rows.
- [ ] Implement `YourPosts.vue`, `PostCard`, `ChooserSheet`, actions through the service (`promise` with count, `outcome`, `repost`, `withdraw`) and typed routing to the right person chat. Commit.

### Task 8: ChitChat
- [ ] Specs: chronological stream with quotes; own posts right; love; long-press menu; composer with photo; distance chips; logged out line.
- [ ] Implement; rewrite `pages/chitchat/[[id]].vue` for chat mode. Commit.

### Task 9: Playwright
- [ ] Rewrite `test-homepage.spec.js`; new `test-chat-shell-{landing,give,ask,nearby,community,chitchat,yourposts,escalation,classic-toggle,desktop}.spec.js`. Full suite via status API green. Commit.

### Task 10: Docs and lint
- [ ] Docs as listed; `node scripts/check-docs-freshness.mjs --base origin/master`; `npm run lint`; `gofmt -l`. Commit.

### Task 11: Reviews
- [ ] code-review skill at high effort; red-team tests (injection strings into typed turns, 10k chars, unicode, chip replay, model garbage, adapter timeout).
- [ ] Headless Chrome (GPU off) screenshots at 390×844 and 1440×900 of every screen; Lighthouse accessibility; fixes.
- [ ] Voice evaluation: 60 frozen moments, composed vs template, blind judge.
- [ ] Full vitest, Go, Laravel, Playwright suites green.

### Task 12: PR
- [ ] Terse end-state body passing `.claude/check-pr-text.sh`; push; open PR to master; decisions table for the operator; SQL inline.
