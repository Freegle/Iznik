# Build plan: auto-split multi-item posts

Candidate and decisions: `plans/token-burn/auto-split-multi-item-posts.md`. Built unattended
(Edward, 2026-11-07). Worktree `FreegleDocker-autosplit`, project `freegle-autosplit`, branch
`feature/auto-split-multi-item-posts`.

## Shape

**Data.** New table `messages_autosplit`, one row per post the splitter has decided on: `id`,
`msgid` (unique, cascade), `state` (`Shadow`, `Split`, `NotSplit`, `Undone`), `kind`, `items`
(JSON, the text splitter's answer), `photos` (JSON, the photo splitter's answer),
`createdattachments` (JSON ids of crop rows, for undo), `originalavailableinitially`,
`originalavailablenow`, `model`, token counts, `created`, `undoneat`. A split post is an Offer
with `messages_bulk_items` rows **and** a `Split` row here.

**"Is a clearance" changes meaning.** Today most code reads "has bulk items" as "is a
clearance". After this, a clearance is "has bulk items and no `Split` row". One SQL helper per
language (`ClearanceSql` in batch, `utils.IsClearanceSQL` in Go) and one frontend helper
(`isClearance(message)`) replace the bulk-items tests at the clearance-only sites: freebie and
partner feeds (Go approve, ContentCheck, postFeedPayload, LoveJunk), electricals stats, the
reply flow swap, My Posts clearance card, outcome arithmetic, ModTools label.

**Batch, `App\Services\AutoSplit`.**
- `GeminiJson`: one client, header key, JSON schema, retries on 429/5xx, token usage.
- `TextSplitter`: subject + description to `{kind, split, items[{name, quantity}]}`; validation
  (2 to 12 items, names non-empty, quantities 1 to 99, items not all the same name).
- `PhotoSplitter`: fetch each photo through delivery with its rotation, get its size from
  `output=json`, ask the vision model which items each photo shows with a `box_2d` per item;
  then a deterministic rule: one item -> the whole photo goes to it; several items, every box
  clean (big enough, not the whole frame, little overlap) -> one crop row per item; anything
  else -> shared post photo.
- `AutoSplitService`: candidates (native Platform Offers, no tnpostid, no bulk items, no row,
  arrived in the last 2 hours, not deleted, no outcome), process one post, write in one
  transaction: items, crop rows (same externaluid, crop in externalmods), photo links, the row.
- Commands: `messages:auto-split` every minute (mode `off`/`shadow`/`on`, env
  `AUTOSPLIT_MODE`, default off), `autosplit:backtest` over a JSONL sample with no writes.

**Crops.** A crop is a new `messages_attachments` row reusing the original `externaluid`, with
`externalmods` `{"rotate":R,"cx":..,"cy":..,"cw":..,"ch":..,"precrop":true}` in pixels of the
rotated frame (proved on live delivery 2026-11-07: weserv crops after `ro`; `precrop` crops
before `w`). Go `GetImageDeliveryUrl` and `rotateParam` emit them; the Nuxt weserv provider
passes unknown modifiers through. Rotating a cropped photo must carry the crop with it.

**Go.**
- Message output gains `autosplit` (state) so the frontend can tell.
- `POST /message` actions: `AutoSplitUndo` (owner or mod; removes items, links and crop rows,
  restores the availability counts, row -> `Undone`) and `BulkItemAvailable` (owner or mod;
  per-item taken, completes the outcome when the last goes).
- `PATCH /message` with `bulkitems` allowed for the owner of a `Split` post (edit).
- An outcome on a split post marks every item gone, so per-item stats agree.

**Frontend.**
- My Posts keeps split posts in the normal list; `MyMessage` shows "We've listed this as N
  items" with Undo and Edit, and a per-item Taken toggle. The just-posted page refreshes the new
  OFFER for a couple of minutes so the split appears without a reload.
- `MessageExpanded`: split posts show the item list (photos, taken badges) and the normal reply
  flow, with a "Which would you like?" picker whose choice heads the reply.
- ModTools: "Split automatically into N items" instead of "Bulk clearance".

## Status

| # | Task | Status | Notes |
|---|---|---|---|
| 1 | Measure on the 30-day sample | ✅ | plans/reports/2026-11-07-auto-split-measurement.md: about 3% of native OFFERs split |
| 2 | Text splitter (batch) + tuning | ✅ | five prompt rounds, each checked on unseen posts; reviewer agrees with 91% |
| 3 | Photo splitter + crops | ✅ | one photo a request; crops proved on live delivery (crop after ro) |
| 4 | Conversion, table, is-clearance sites (batch + Go), undo, per-item taken | ✅ | |
| 5 | Frontend: My Posts, reply, ModTools | ✅ | checked in a browser on the worktree with a real split |
| 6 | Backtest through the PHP path, reviewer model, misses listed | ✅ | every crop clean in review |
| 7 | Docs, rules trap | ✅ | docs/developers/reference/auto-split.md; three traps |
| 8 | Suites, review, push, PRs | 🔄 | independent review: 5 of 6 findings fixed, 1 disproved on the live service |
