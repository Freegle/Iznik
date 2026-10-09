---
last_reviewed: 2026-10-08
owner: Freegle dev team
covers:
  - iznik-server-go/emailtracking/journal.go
  - iznik-server-go/emailtracking/emailtracking.go
  - iznik-server-go/emailtracking/compact.go
  - iznik-server-go/test/emailtracking_journal_test.go
  - iznik-batch/app/Services/Mail/EmailTrackingFoldService.php
  - iznik-batch/app/Console/Commands/Mail/FoldEmailTrackingCommand.php
  - iznik-batch/app/Services/Mail/DeliveryHealthService.php
  - iznik-batch/tests/Feature/Mail/EmailTrackingFoldTest.php
  - iznik-batch/database/migrations/2026_10_08_000001_create_email_tracking_journal_table.php
---

# Email tracking journal

How an email being opened gets recorded without every image load locking the email's tracking
row.

## The problem it fixed

An email's images load together when it is opened. Each load used to insert an
`email_tracking_images` row and update the parent `email_tracking` row (`opened_at`,
`scroll_depth_percent`). The insert's foreign key takes a shared lock on the parent row and the
update takes an exclusive one, so the images of one email queued on one row. Measured on db3 it was
the largest wait family: about 2.2 million inserts and 1.8 million updates a day, 40.7% of
statement time, nearly all of it lock wait rather than CPU, and a COMMIT cost on top because
every write set is certified across the cluster.

## How it works now

1. **Request time (Go, `emailtracking/journal.go`).** The image and pixel handlers do no database
   work. They note the event in memory and answer exactly as before (the 302 to the image, or the
   1x1 GIF). A flusher appends the buffer to `email_tracking_journal` in one multi-row `INSERT`,
   about once a second or at 500 events. The journal is a bare table: no foreign key, no secondary
   index, no lookup of the tracking row. A `ref` is the tracking id as the request carried it (the
   full 32 characters or the 12-character compact ref).
2. **Overnight (Laravel, `mail:tracking:fold`, 01:35 UTC).** `EmailTrackingFoldService` reads the
   journal in 5,000-row chunks and, per chunk and in one transaction, resolves each ref to its
   `email_tracking` row, stamps `opened_at`, raises `scroll_depth_percent`, inserts the
   `email_tracking_images` rows and deletes the journal rows. It keeps the old rules: `opened_at` is
   the first open (an earlier journal event moves a later stored value back), scroll depth only
   rises, one images row per load. Re-running is harmless.

Clicks (about 12,000 a day) are not journalled. They still write through at request time, so
`clicked_at`, `links_clicked`, unsubscribe and the click rows stay immediate.

## What reads tracking, and how it copes

| Reader | Needs | How it copes |
|---|---|---|
| ModTools email stats, time series, by type, the member email list | Daily figures | Today's opens read low until the next fold; every earlier day is complete. |
| Re-engagement and digest-position reports, community news engagement | Daily or weekly | Unaffected. |
| User data dump | Per member | Loads from the last day are missing until folded. |
| `mail:digest:mark-seen` (hourly) | Opens in the last 3 hours | Folded opens are many hours old, so the fold command runs it over a window covering every open it applied. Digest posts are marked seen overnight rather than within the hour. |
| Delivery health check (13:00) | Opens up to six hours ago | It judges mail only up to the oldest unfolded journal event, otherwise unopened-so-far mail would read as a collapse. See `DeliveryHealthService::settledBefore()`. |
| `scroll_depth_percent` | Nothing reads it | - |

## If the journal cannot be written

The Go side falls back to the old direct writes for the events it could not append (table missing,
database refusing), so a deploy that gets ahead of the migration costs load and loses nothing.
`EMAIL_TRACKING_JOURNAL=off` forces direct writes everywhere. If the process dies, up to a second of
buffered events is lost; for open and scroll analytics that is noise.

If the fold stops running the journal grows and the open figures stop updating. The command
logs an error and exits non-zero when the oldest unfolded event is more than 36 hours old.

## Cost of the journal itself

One append-only insert carries hundreds of events, so a day is tens of thousands of write sets
instead of about four million. The fold does the same row counts in the overnight trough in sorted,
uncontended batches. The `email_tracking_images` rows are still written because the user data dump
reads them; nothing else does, so whether 2.2 million rows a day are worth keeping is a separate
product question.
