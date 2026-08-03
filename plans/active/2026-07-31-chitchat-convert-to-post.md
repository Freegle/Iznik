# ChitChat: flag duplicates, and convert a post into a real OFFER/WANTED

## Why

ChitChat mods have "Refer to OFFER/WANTED", which posts a canned notice telling the
member to go and post it themselves (`NewsRefer.vue`, `createRefer` in
`newsfeed.go`). It puts the work back on the member and most never follow through.

Two better outcomes:

1. **It's a duplicate** — the member already has a live OFFER/WANTED saying the same
   thing, and the ChitChat post adds nothing. Mods should see that plainly, with the
   matching post named, so they can hide it. Members must not see the note.
2. **It was posted in the wrong place** — mods should be able to create the OFFER or
   WANTED *for* them, then tell them it's been done and point at My Posts.

## Design

### Duplicate detection (mod-only)

`GET /newsfeed/:id/duplicate` (Go, apiv2):

- Embed the newsfeed message with `embedding.EmbedQuery` (`sidecar.go`).
- `embedding.Global.Search` for nearest posts.
- Restrict to the **same author** and to posts still live: a duplicate means *this
  member* already posted it. A similar post by someone else is not a duplicate.
- Return the best match above a cosine threshold, with its id/type/subject.
- Mod-gated: `chitChatMod` audience only, so the response is never fetched for members.

Frontend: `NewsThread.vue` shows a mod-only `NoticeMessage` naming the matching post,
with the existing Hide action beside it.

### Convert to a real post

Newsfeed action `ConvertToPost` (mod-gated, alongside `ConvertToStory`):

- Creates a message with `fromuser` = the **newsfeed author**, not the mod.
  `PostMessage` hardcodes `myid` (message.go:4092), so this needs an explicit author
  parameter, permitted only for a mod.
- Logs the mod action (`logModAction`), as the other on-behalf paths do.
- Posts a reply on the ChitChat thread saying what was done, pointing at My Posts.

Frontend: `NewsConvertModal.vue` — OFFER/WANTED toggle, item name and body
pre-populated from the ChitChat text, a preview of the resulting post, and submit.

## Status

| # | Task | Status | Notes |
|---|------|--------|-------|
| 1 | Explore referTo / newsfeed actions / embeddings / message create | ✅ | Primitives all exist |
| 2 | Plan + branch | ✅ | `feature/chitchat-convert-to-post` |
| 3 | Go: duplicate-detection endpoint | ✅ | `newsfeed/duplicate.go` + route; same-author, live, cosine ≥ 0.82 |
| 4 | FE: mod-only duplicate notice | ✅ | `chitChatMod` gate |
| 5 | Go: `ConvertToPost` action | ✅ | option 1: `PutMessageAs`/`JoinAndPostAs` + `?onbehalfof=` |
| 6 | FE: convert modal + preview | ✅ | prefill item/body |
| 7 | Thread reply + My Posts pointer | ✅ | |
| 8 | Tests (Go + vitest) | ✅ | |
| 9 | Screenshots + PR | ✅ | PR #1216, merged and live |

## Post-ship fixes (2026-08-01, direct on master)

Edward's live test (Gosport WANTED) surfaced four problems, fixed together:

| # | Fix | Status | Notes |
|---|-----|--------|-------|
| F1 | `newsfeed.type` ENUM lacked `ConvertedToPost` — MySQL truncated it to `''`, so the notice rendered as an empty reply from the moderator (their header, no body) | ✅ | Laravel migration `2026_08_01_000001` + prod `_migration.sql`; live ALTER + row-repair SQL handed to Edward |
| F2 | Notice wording said "posted this properly for you" — reads as a telling-off | ✅ | Now "posted a WANTED / an OFFER for you"; `createRefer` stores `msgid`, GET exposes `msgtype` (client can't fetch a pending message itself) |
| F3 | Original ChitChat post stayed visible after the convert | ✅ | `ConvertedToPost` action now hides it exactly as the Hide action does |
| F4 | ChitChat photo was dropped | ✅ | Copied `newsfeed_images` → `messages_attachments` (externaluid images) as primary; modal says "We'll include their photo" |

## Notes

- Item name extraction: reuse whatever `constructSubject`/item parsing already exists
  rather than inventing a parser.
- The mod note must never reach members — gate server-side, not only in the template.

## Posting as another member — the one real obstacle

Creating a post is two owner-gated steps, not one:

- `PutMessage` (message.go:3961) writes the draft with `fromuser` = `myid`
  (the INSERT at :4092 hardcodes it).
- `handleJoinAndPost` (message.go:2943) submits it and refuses outright unless
  `msg.Fromuser == myid` ("Not your message", :2956).

So there is no existing route by which a moderator can post as a member, and the
duplicate-detection half of this work does not depend on solving it.

Options:

1. **Extract the core of both, taking an explicit author.** Handlers stay thin
   wrappers passing `myid`, so no existing caller changes behaviour; the new
   ChitChat path passes the newsfeed poster's id after a `canHidePost` check, and
   logs it via `logModAction`. Correct, but it edits two owner-only auth checks in
   the most sensitive file in the API.
2. **Post it as a draft owned by the member** and notify them to press send. Keeps
   every auth check untouched; does not fully meet "post that as an offer".
3. **Reimplement creation inside the newsfeed package.** Rejected — it would skip
   `messages_groups`, spatial, indexing and embedding work that the real path does,
   producing posts that look right but behave oddly.

Recommendation: option 1, because it is the only one that delivers the asked-for
outcome, and the risk is contained by leaving the existing handlers as pass-throughs.
