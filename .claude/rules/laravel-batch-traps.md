---
paths:
  - "iznik-batch/**/*.php"
---

# Traps in the Laravel batch code

All of these fail quietly. A green test run does not clear them.

## `Http::fake()` merges - the first stub wins

`Http::fake($closure)` does **not** replace a previous fake. It merges the callback into the
stub list and the first registered callback that returns a response wins. A test helper called a
second time with different data is therefore silently ignored, and the second run re-reads the
first feed.

The tell is a result array that makes no sense for the feed you think you sent, such as an
`updated` count when the new feed contained only an unseen id. Build the second response inside
the original closure, keyed on the request, rather than registering a second fake.

## Never put a Mailable into its own view data

`build()` doing `array_merge(['mail' => $this], $content)` so templates can call
`$mail->trackedUrl()` **segfaults the Blade renderer (exit 139) under PHP 8.4**, before any MJML
compile. The same template renders fine without the object.

Pass plain strings instead: pre-wrap tracked URLs, hand the open pixel over as a ready MJML
string, and guard with `@if(!empty($trackingPixelMjml))`.

Debugging it is its own trap - the crash looks like an MJML problem and it is not. Bisect by
rendering with and without the object. A segfault kills the process, so capture the MJML and
compile it separately to prove the compiler is fine. After template edits run
`php artisan view:clear` and remove `storage/framework/views/*.php`.

## `Schema::hasTable()` / `hasColumn()` guards rot silently

A guard that hard-codes a name becomes permanently false when a migration renames the thing, and
nothing reports it. One guard sat false for months after its table was renamed, harmless only
because the method returned an empty array on the next line anyway.

When you rename a table or column, grep for the old name in `hasTable`/`hasColumn` guards. When
you find a guard whose migration has long since shipped, delete the guard rather than leaving it.

## A dropped table cannot be restored by running migrations

If a table is missing but its create-migration is already recorded as applied, `migrate` will
never recreate it, and a `hasTable` guard inside that migration makes re-running a no-op anyway.
The symptom is a fixture load aborting part-way with the table not existing, leaving the database
half-populated.

Recreate the table explicitly, or roll the migration back before re-running it. Do not expect
`migrate` to notice.

## A migration that converts a list needs a branch for every kind of entry

A conversion of one keyword table into another had no branch for the protected entries, so
protected place and shop names were written out as live matches. Ordinary words then started
flagging posts. When a migration maps one vocabulary onto another, enumerate every category in
the source and decide what happens to each, including the ones that mean "never match".

## Adding a foreign key needs `foreign_key_checks = 0` to stay in place

MySQL 8 supports `ALGORITHM=INPLACE` for adding a FOREIGN KEY **only when the session has
`foreign_key_checks = 0`**. With checks on, the server silently downgrades the ALTER to
`ALGORITHM=COPY`, a full table rebuild, even when the referencing column is freshly added,
indexed and entirely NULL. On a large table under Galera that is an outage-shaped mistake.

For any foreign key added to a big table:

1. `SET SESSION foreign_key_checks = 0;` - safe when the column is all NULL.
2. State `ALGORITHM=INPLACE` explicitly, so anything copy-shaped refuses rather than proceeding.
3. On the production cluster, run it node by node the way index adds are run.
4. Combine index adds and foreign key adds into one ALTER so that dance happens once.

## An index hint on a shared query builder helps one caller and wrecks the other

`MessageSpatialService::qualifyingMemberships()` is deliberately shared: the reconciler
(`upsertRecentMessages`) and `stillQualifyForIndex()` must agree about what belongs in the
spatial index, and one builder is how that is guaranteed. They want opposite plans.

The reconciler scans a whole date window with no id restriction, so it wants
`FORCE INDEX (arrival)` - without it the optimiser drives from `collection`, a column with 21
distinct values, and reads 5.5M rows to keep 6%. `stillQualifyForIndex()` is handed a handful of
msgids and wants the msgid index; force `arrival` on it and every `ripple:expand` call scans the
window instead of doing a keyed lookup.

Nothing fails. Both return correct rows. One of them just quietly becomes a scan.

So the hint lives on the reconciler's own membership source, passed in as an argument, and the
shared builder stays unhinted by default. If you add a caller, decide which it is.
`MessageSpatialServiceTest` asserts that `stillQualifyForIndex` emits no `FORCE INDEX`.

The same shape applies to `IGNORE INDEX`: `NotificationExhortService` needs one because
`deleted IS NULL` matches 95% of `users`, and it is scoped to that one query for the same reason.
Either way the hint names an index, so it rots if the index is renamed - pin the name in a test.

## A schedule filter skips a fixed-time job, it does not delay it

Laravel's scheduler has no catch-up. `$event->skip()` or `->when()` answering "not now" for a
`dailyAt('04:20')` means that job does not run that day, and nothing says so. Every-minute jobs
are only delayed, which is why a filter that holds work off for a window looks harmless in
testing. The backup drain window (`App\Console\BackupDrain`) had four daily jobs inside it on
the day it was written.

When you add a window-shaped filter, list the once-a-day jobs it covers and move them out;
`BackupDrainWindowTest` does that check for the drain. When you add a `dailyAt()`, keep it out
of the window.

Check in the job's own timezone. The window is in UTC; a `->timezone('Europe/London')` job at
05:00 is 04:00 UTC in summer and 05:00 UTC in winter. The WhatJobs digest-prep sync was inside
the window for half the year while a UTC-only check said it was clear, and the first night
the drain ran it was skipped with nothing to catch it up before the digest.

## An excuse that scales with the threshold switches off the big thresholds

When a monitoring check is told to stand down for a known cause, size the stand-down by the
cause, not by the check. `BacklogCheck` first skipped "while the drain was in force, and for
one maximum age after": right for a ten-minute check, but the rippling check allows a day, so
it reported "not assessed" for the 24 hours after every window, which is always. A 45-minute
hold cannot make a row a day late, so it cannot excuse that check at all.

The rule now is that the hold excuses a backlog only once it has lasted longer than the
check's maximum age, plus a short fixed catch-up. When you add a suppression, run it against
the largest threshold that will pass through it and ask whether the cause could produce that.

## A contextual binding does not reach a `handle()` parameter

`$this->app->when(SomeCommand::class)->needs(Runner::class)->give(...)` only applies while the
container is *building* `SomeCommand`. Parameters of `handle()` are resolved by
`Container::call()` with an empty build stack, so they get the plain binding. A command that
asked for its ssh runner in `handle()` received the monitoring runner and its thirty-second
timeout, and would have reported the node unreachable every night.

Inject through the constructor, and assert in a test which runner the built command holds.

## See also

- `.claude/rules/go-api-traps.md` - the same class of silent wrong answer on the Go side.
- Migrations in `iznik-batch/database/migrations/` are the single source of truth for schema.
