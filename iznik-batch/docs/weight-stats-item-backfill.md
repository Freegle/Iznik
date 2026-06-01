# Weight stats drop (Feb 2026) — missing `messages_items` links

## Symptom

The `/stats` page "Weights" chart shows the monthly diverted-weight figure roughly
halving from **February 2026** onwards (~1,000,000 kg/month → ~450,000 kg/month),
and continuing to drift down. Item **volume** (the Outcomes figure) is unchanged.

## Root cause

The Weight stat is computed by `StatsGenerationService` (V1: `iznik-server`
`Stats::generate()`), which sums `items.weight` over the items linked to each
taken/received message:

```
messages_outcomes
  JOIN messages_groups
  JOIN messages
  JOIN messages_items      <-- INNER JOIN: a message with no item link is dropped
  LEFT JOIN items
WHERE outcome IN ('Taken','Received')
```

Because that join to `messages_items` is an **inner** join, any taken message
with no `messages_items` row contributes **0 kg** while still counting as an
Outcome.

When incoming-email processing was migrated from V1 (`iznik-server`
`Message::save()`) to V2 (`iznik-batch` `IncomingMailService`), the item-extraction
step was not ported. V1, after inserting a message, parsed a well-formed
`TYPE: item (location)` subject, found/created the `items` catalog row and wrote
a `messages_items` link. `IncomingMailService::createGroupPostMessage()` created
the message and `messages_groups` row but **never created the item link**.

Posts that arrive by email — chiefly **TrashNothing** posts (`sourceheader`
`TN-native-app` / `TN-web-app`), which are ~half of all OFFER/WANTED posts — went
through this path. Posts from the Freegle app/web (`source = Platform`) were
unaffected and kept ~100% item coverage.

### Evidence (production)

Item-link coverage of taken messages by month:

| Month   | taken msgs | with `messages_items` | %     |
|---------|-----------:|----------------------:|------:|
| 2026-01 |     29,371 |                29,364 | 100%  |
| 2026-02 |     25,020 |                17,015 | 68%   |
| 2026-05 |     30,783 |                15,337 | 49.8% |

Exact cutover: **2026-02-04** (100% on 2026-02-03 → 65% on 2026-02-04).

## Fix (forward)

`IncomingMailService::createGroupPostMessage()` now calls
`ItemService::recordFromSubject()` immediately after creating the message and
`messages_groups` row, restoring V1 parity. `App\Services\ItemService` ports V1's
`Item::create()` (case-insensitive find-or-create + weight estimate from the
`weights` table) and `Message::addItem()` (idempotent `messages_items` link).

## Backfill (historical repair)

`messages:backfill-items` rebuilds the missing links for OFFER/WANTED messages in
a date range (by `messages.arrival`) and can regenerate the affected `stats` rows.

```bash
# Dry run first — see how many messages would be linked.
php artisan messages:backfill-items --from=2026-02-04 --dry-run

# Backfill the links, then regenerate the daily stats for the same range.
php artisan messages:backfill-items --from=2026-02-04 --stats
```

Notes:

- Only messages with **no** existing `messages_items` row are touched; messages
  that already have items (e.g. Platform posts) are skipped.
- Messages whose subject is not a well-formed `TYPE: item (location)` are counted
  as "skipped (no well-formed subject)" and left alone.
- `--stats` regenerates **all** stat types for **every group** for each date in
  the range (mirrors `stats:generate-daily`), so for a multi-month repair it is
  cheaper to run a month at a time:

  ```bash
  for m in 2026-02 2026-03 2026-04 2026-05; do
    php artisan messages:backfill-items --from=${m}-01 --to=${m}-31 --stats
  done
  ```
