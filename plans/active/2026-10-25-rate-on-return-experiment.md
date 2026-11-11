# Experiment `rate-on-return`: ask members to rate the people they dealt with, on their next visit

Edward, 25 Oct 2026: "design an experiment pr, new worktree, push much harder to rate people via modals on
next visit." Worktree `FreegleDocker-rate-on-return`, branch `ux-exp/rate-on-return`.

## What the data says (live DB, 25 Oct)

| Measure | Figure |
|---|---|
| Ratings a day | about 330, of which about 150 are imported from TrashNothing, so about 180 made on Freegle by about 150 members |
| Handovers a day (`messages_by`) | about 430, by about 300 takers |
| Handovers 4 to 11 Oct where the poster rated the taker within the week | 888 of 2,516 (35%) |
| Handovers 4 to 11 Oct where the taker rated the poster | 132 of 2,516 (5%) |

The poster is asked in the outcome modal (OutcomeBy shows the thumbs), which is why 35% rate. Nobody asks the
taker: the thumbs are in the chat header on desktop only. So the pool is about 280 unrated poster sides and
about 410 unrated taker sides a day.

## The change

Arm `b`, signed in, on the web, on arrival at a page that is not mid-flow (the interstitial's `EXCLUDED` list):
`GET /user/unrated` lists the people the member handed over with in the last 14 days (`messages_by`, either
direction) and has not rated. If there are any, a modal "How did it go?" shows up to three of them, each with
"You gave them X" or "They gave you X" and thumbs up / thumbs down (down opens the existing reason modal), with a
Skip per person and Not now. Shown at most once in 24 hours per browser. Control: the fetch and the arm read
happen in the same cases, nothing shows.

Primary event `user_rated` per start of `chat_outcome` (new entry in the two event lists). Finishing
`chat_outcome` becomes the guardrail; touches `chat_outcome,reply,give`.

## Status

| # | Task | Status | Notes |
|---|---|---|---|
| 1 | Go `GET /user/unrated` + tests | ✅ | `user/unrated.go`, route before `/user/:id?` |
| 2 | `user_rated` in both primary-event lists + tests | ✅ | Go `ux/experiments.go`, PHP `UxExperimentService` |
| 3 | Frontend: API, store logging, composable, modal, LayoutCommon, manifest | ✅ | |
| 4 | Unit tests (composable, modal, store, LayoutCommon stub, manifest) | ✅ | |
| 5 | e2e: post, reply, served unrated list, modal, rate, day gate, control, real endpoint | ✅ | passed against rebuilt prod-local | |
| 6 | Docs section + covers, docs freshness | ✅ | |
| 7 | Run Go, Laravel, vitest, playwright in the worktree; push; PR with Blocked label | ✅ | PR #1921, CI running | |
