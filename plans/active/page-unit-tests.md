# Page Unit Tests Plan

## Goal
Write Vitest unit tests for all pages with non-trivial script setup logic.
Branch: feature/page-unit-tests

## Status Table

| # | Page | Key Logic to Test | Status | Notes |
|---|------|------------------|--------|-------|
| 1 | profile/[id].vue | Route guard, 404 handling | ✅ Complete | Done in previous session |
| 2 | message/[id].vue | Route guard, fetch, error, showtaken query | ⬜ Pending | |
| 3 | mypost/[id]/[[action]].vue | Param parsing, action routing, group fetch | ⬜ Pending | |
| 4 | chats/[[id]].vue | Search filter, loadMore state | ⬜ Pending | Existing test file |
| 5 | chitchat/[[id]].vue | Duplicate detection, keyword upsell, timer | ⬜ Pending | Existing test file |
| 6 | volunteering/[[id]].vue | Route param, fetch, error handling | ⬜ Pending | |
| 7 | communityevent/[[id]].vue | Route param, fetch, error handling | ⬜ Pending | |
| 8 | story/[id].vue | Route param, fetch, error, modal state | ⬜ Pending | |
| 9 | explore/[[id]].vue | Route param, computed group, conditional fetch | ⬜ Pending | |
| 10 | explore/join/[[id]].vue | Route param, join logic, router fallback | ⬜ Pending | |
| 11 | explore/[groupid]/[[msgid]].vue | Multi param parsing, head conditionals | ⬜ Pending | |
| 12 | one-click-unsubscribe/[[uid]]/[[key]].vue | Auth state branching, forget/redirect logic | ⬜ Pending | |
| 13 | stories/authority/[id].vue | Param+query parsing, sort logic | ⬜ Pending | |
| 14 | forgot.vue | Email validation, redirect if logged in | ⬜ Pending | |
| 15 | shortlinks/[id].vue | Route param, fetch, data transform | ⬜ Pending | |
| 16 | give/options.vue | Deadline math, onMounted message lookup | ⬜ Pending | |
| 17 | give/index.vue | Breakpoint redirect logic | ⬜ Pending | |
| 18 | find/index.vue | Same as give/index | ⬜ Pending | |
| 19 | browse/[[term]].vue | Bounds calculation, query params | ⬜ Pending | |
| 20 | microvolunteering/message/[[id]].client.vue | Privilege check, store interactions | ⬜ Pending | |
| 21 | engage.vue | Query params, conditional routing | ⬜ Pending | |
| 22 | facebook/unsubscribe/[[id]].vue | Param, fetch, 404 handling | ⬜ Pending | |
| 23 | jobs.vue | Category building, search handler, watch | ⬜ Pending | |
| 24 | give/whoami.vue | Email validation, async checking | ⬜ Pending | |
| 25 | find/whoami.vue | Same as give/whoami | ⬜ Pending | |
