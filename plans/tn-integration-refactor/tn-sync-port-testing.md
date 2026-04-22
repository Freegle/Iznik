# TN Sync Side-by-Side: Log Every DB Write, Neuter Writes in the Port

## Context

`TNSyncCommand` is a 1:1 Laravel port of [iznik-server/scripts/cron/tn_sync.php](iznik-server/scripts/cron/tn_sync.php). The port is code-complete and the user wants to run it in production alongside the legacy cron and diff logs to prove behavioural parity. **The full logic must run on both sides** — including deep cascades like `User::forget()` and `User::merge()` — so the *traversal path* is compared, not just the top-level branches. The only difference between the two runs is: **legacy actually writes to the database; the Laravel port does not.**

Goal:
1. At every DB write site reachable from the `TNSyncCommand` code path, emit an identical `TN-SYNC-TRACE [WRITE] …` log line on **both** sides.
2. On the Laravel side only, comment out the actual write while leaving all surrounding logic (cache updates, loop iteration, event dispatching, control flow) intact so `forget`/`merge`/etc. still traverse every branch they would normally traverse.
3. Legacy `iznik-server` is never modified except to add the matching trace `error_log` calls; it continues to write to the DB normally (it is the production truth).

## Trace log format

One line per write, identical on both sides, grep-able with a single prefix:

```
TN-SYNC-TRACE [WRITE] table=<table> op=<insert|update|delete|upsert|replace> where=<k=v[,k=v…]> set=<k=v[,k=v…]>
```

Rules:
- `where=` describes the row being targeted (primary key or identifying clause). Omit if `op=insert` has no natural where.
- `set=` lists the columns being written. Omit for `op=delete`.
- Values are printed raw (no quoting). Long strings (`about_me`, `message`) are replaced by `len=<n>` to keep diffs stable.
- Timestamps use the TN payload string verbatim when derived from the payload; otherwise `NOW()` is printed literally so both sides match.
- Emit **before** the write on both sides, so the trace line is produced even if the write itself is commented out (Laravel) or throws (legacy).

Non-write observable events (branch choices, loop iterations, API fetches) continue to use `TN-SYNC-TRACE [<EVENT>] …` as per the earlier plan — those remain useful for proving the *path* matches before the writes even happen. See "Non-write trace events" below.

Emit channel:
- **Legacy** (`iznik-server`): `error_log("TN-SYNC-TRACE …")` — goes to PHP stderr / the cron log.
- **Laravel port** (`iznik-batch`): `Log::info("TN-SYNC-TRACE …")` — goes through the configured channel and Loki. Do **not** use `$this->info()` or `LokiService::logEvent()` for these traces (Loki service file writes are themselves commented out, and `$this->info` interleaves with progress output).

## Write sites — inventory and action

Every site below gets: (a) matching trace line added on both sides, (b) the write line itself commented out on the Laravel side only. Grouped by origin in the call graph.

### 1. Direct writes in `TNSyncCommand` / `tn_sync.php`

| Write | Legacy file / line | Laravel file / line |
|---|---|---|
| `ratings` upsert (INSERT … ON DUPLICATE KEY UPDATE) | [tn_sync.php:60–68](iznik-server/scripts/cron/tn_sync.php#L60-L68) | [TNSyncCommand.php:190–198](iznik-batch/app/Console/Commands/TrashNothing/TNSyncCommand.php#L190-L198) |
| `ratings` delete | [tn_sync.php:70–73](iznik-server/scripts/cron/tn_sync.php#L70-L73) | [TNSyncCommand.php:205–209](iznik-batch/app/Console/Commands/TrashNothing/TNSyncCommand.php#L205-L209) |
| `users_replytime` replace | [tn_sync.php:110–117](iznik-server/scripts/cron/tn_sync.php#L110-L117) | [TNSyncCommand.php:286–290](iznik-batch/app/Console/Commands/TrashNothing/TNSyncCommand.php#L286-L290) |
| `users_aboutme` replace | [tn_sync.php:122–129](iznik-server/scripts/cron/tn_sync.php#L122-L129) | [TNSyncCommand.php:299–303](iznik-batch/app/Console/Commands/TrashNothing/TNSyncCommand.php#L299-L303) |
| `users.fullname` set (name change) | [tn_sync.php:140](iznik-server/scripts/cron/tn_sync.php#L140) via `setPrivate` | [TNSyncCommand.php:321](iznik-batch/app/Console/Commands/TrashNothing/TNSyncCommand.php#L321) + save at 356 |
| `users.lastlocation` set | [tn_sync.php:168](iznik-server/scripts/cron/tn_sync.php#L168) via `setPrivate` | [TNSyncCommand.php:351](iznik-batch/app/Console/Commands/TrashNothing/TNSyncCommand.php#L351) + save at 356 |
| Sync-date file write | [tn_sync.php:213](iznik-server/scripts/cron/tn_sync.php#L213) | [TNSyncCommand.php:134](iznik-batch/app/Console/Commands/TrashNothing/TNSyncCommand.php#L134) |
| `LokiService::logEvent` file append | n/a | all `$this->loki->logEvent(...)` in TNSyncCommand — writes to `/var/log/freegle/batch_event.log` |

Also: lock/PID file on Laravel side ([PreventsOverlapping](iznik-batch/app/Console/Concerns/PreventsOverlapping.php)) is local-only to the batch container and harmless; leave it.

### 2. `User::forget()` cascade

Legacy: `iznik-server/include/user/User.php::forget()` ~line 5887.
Laravel: `iznik-batch/app/Models/User.php::forget()` ~line 1336.

Writes to trace + comment out (Laravel):

- `users` UPDATE for each of `firstname`, `lastname`, `fullname`, `settings`, `yahooid` nulled — this is the sequence of property assignments followed by `$this->save()`. **Log each assignment with its own WRITE line**; keep the assignments, comment out the `save()`.
- `users_logins` DELETE for each row.
- `messages` UPDATE clearing `fromip`, `message`, `deleted=NOW()` per message.
- `messages_groups` UPDATE `deleted=1` per row.
- `messages_outcomes` UPDATE `comments=NULL`.
- `chat_messages` UPDATE `message=NULL`.
- `communityevents`, `volunteering`, `newsfeed`, `users_stories`, `users_searches`, `users_aboutme`, `users_addresses`, `users_images`, `messages_promises` DELETEs (per row).
- `ratings` DELETEs — two queries (`rater=?` and `ratee=?`).
- Membership removal per group (calls into `removeMembership()` — see §4).
- `users` UPDATE setting `forgotten=NOW()`, `tnuserid=NULL`.
- `sessions` DELETEs.
- `logs` INSERT (the forget log entry).

Per-iteration strategy: keep the outer loop intact; inside it, the trace line goes before the `->delete()` / `->save()`, and the delete/save is commented. Counters and collections still accumulate correctly.

### 3. `User::merge()` cascade

Legacy: `iznik-server/include/user/User.php::merge()` ~line 2832.
Laravel: `iznik-batch/app/Models/User.php::merge()` ~line 919.

Writes to trace + comment out (Laravel):

- `DB::beginTransaction()` / `commit()` / `rollBack()` — **leave intact on both sides**. With all inner writes commented, the transaction is empty; no data persists. Transactions matter for legacy-side consistency and don't change the port's observable behaviour. (If the user prefers, the `beginTransaction/commit` pair can also be no-op'd on Laravel; call this out during review.)
- Memberships merge: `memberships` UPDATEs (`userid`, `role`, `added`, `configid`, `settings`, `heldby`), `memberships` DELETE for the id2-redundant row.
- Emails merge: `users_emails` UPDATE (userid → id1, preferred=0), `users_emails` UPDATE (preferred=1 on the primary).
- ~40 FK reparents via `EloquentUtils::reparentRow` / `reparentRowIgnore` (legacy: raw `UPDATE IGNORE` across `locations_excluded`, `chat_roster`, `sessions`, `users_comments`, `users_logins`, `users_banned`, `chat_messages`, `chat_rooms`, `logs`, `messages`, `messages_history`, `memberships_history`, `giftaid`, …).
  - **Single helper approach:** wrap/modify `EloquentUtils::reparentRow` and `reparentRowIgnore` to emit the trace line per updated row and skip the actual save. This is the cleanest single point to neuter the merge cascade rather than commenting inside every call site.
- `users_logins` UPDATE (`uid` for Native logins).
- `users_banned` reparent + `memberships` DELETE (banned-groups).
- Chat rooms merge: `chat_messages` UPDATE (`chatid`), `chat_rooms` UPDATE (`latestmessage`, `user1`, `user2`), `chat_messages` UPDATE (`userid`).
- Attribute merge (`fullname`, `firstname`, `lastname`, `yahooid`): `users` UPDATE on id2 (NULL), `users` UPDATE on id1 (value).
- `users` UPDATE (`systemrole`, `added`, `lastupdated`, `tnuserid`).
- `giftaid` DELETE non-best + UPDATE best.
- `logs` INSERTs (two — one per side of the merge).
- Post-transaction: `memberships` DELETE + `users` DELETE on id2.

### 4. `User::addEmail()` / `User::removeEmail()` / `unbounce()` / `assignUserToToDonation()`

Reached via the name-change branch in user-changes sync.

- `addEmail`: INSERT `users_emails`, UPDATE `users_emails` preferred flags (two update loops), then `unbounce()` which UPDATEs `bounces_emails` and `users.bouncing`, then `assignUserToToDonation()` which UPDATEs `users_donations` rows.
- `removeEmail`: DELETE `users_emails`.

Trace all, comment all writes on the Laravel side.

### 5. `Location::closestPostcode()`

Read-only (spatial SELECTs only). No action needed; already produces the input the trace for `users.lastlocation` uses.

### 6. Laravel Auditing observers

Every `$model->save()` / `$model->delete()` on an `Auditable` model normally triggers an INSERT into `audits`. With the underlying `save()`/`delete()` commented out, the audit observer never fires — nothing extra to do, but note this explicitly: **audit writes are not separately traced** because they are strictly derivative of the primary write and cannot occur without it.

### 7. Loki event writes inside `LokiService`

`LokiService::logEvent()` appends JSON to `/var/log/freegle/batch_event.log`. The `TNSyncCommand` file has ~7 explicit `$this->loki->logEvent(...)` calls. Comment all of them. Leave `LogsBatchJob::runWithLogging` intact (start/stop only; useful for confirming the command ran).

## Non-write trace events (kept from earlier plan)

Preserve these lightweight trace lines on both sides so path/ordering divergences surface even when no write happens:

- `[START] from=<iso> to=<iso>`
- `[RATINGS-PAGE] page=<n> count=<n>`
- `[RATING] id=<rating_id> ratee=<fd_user_id> rating=<v> action=<upsert|delete|skip-no-user-id|skip-user-not-found|error>`
- `[CHANGES-PAGE] page=<n> count=<n>`
- `[USER-CHANGE] fd_user_id=<id> action=<processed|skip-no-user-id|skip-user-not-found|skip-not-tn|account-removed|error>`
- `[NAME-CHANGE] fd_user_id=<id> old=<name> new=<name>`
- `[LOCATION] fd_user_id=<id> lat=<f> lng=<f> old_loc=<id> new_loc=<id>`
- `[DUP-SCAN] count=<n>`
- `[MERGE] from=<id> into=<id>`
- `[END] ratings=<n> changes=<n> merges=<n> max_date=<iso|null>`

## Files to modify

**Legacy (add traces only, never comment out writes):**
- [iznik-server/scripts/cron/tn_sync.php](iznik-server/scripts/cron/tn_sync.php)
- [iznik-server/include/user/User.php](iznik-server/include/user/User.php) — inside `forget`, `merge`, `addEmail`, `removeEmail`, `setPrivate` (user-scoped branches)
- [iznik-server/include/misc/Entity.php](iznik-server/include/misc/Entity.php) — `setPrivate` base write

**Laravel port (trace + comment out writes):**
- [iznik-batch/app/Console/Commands/TrashNothing/TNSyncCommand.php](iznik-batch/app/Console/Commands/TrashNothing/TNSyncCommand.php)
- [iznik-batch/app/Models/User.php](iznik-batch/app/Models/User.php) — `forget`, `merge`, `addEmail`, `removeEmail`, plus any private helpers called from these (`unbounce`, `assignUserToToDonation`, etc.)
- [iznik-batch/app/Utils/EloquentUtils.php](iznik-batch/app/Utils/EloquentUtils.php) (or wherever `reparentRow` / `reparentRowIgnore` live) — single chokepoint for the ~40 FK reparent writes in `merge`
- `LokiService` calls in `TNSyncCommand.php` — commented at call sites, no need to touch the service itself

## Operational caveats (no code action, just user-facing)

- **Shared code reach**: commenting writes in `User::forget`, `User::merge`, `User::addEmail`, `User::removeEmail`, and `EloquentUtils::reparentRow*` neuters those writes for **every** `iznik-batch` caller during the test window, not just `TNSyncCommand`. If other batch jobs touching users run during the test, they also won't write. The user should pause/disable unrelated batch jobs during the comparison window, or revert these edits immediately after the test.
- **`$from` race**: `$from` comes from `/etc/tn_sync_last_date.txt`, written only by legacy (port write is commented). Start both scripts back-to-back to ensure identical `$from`.
- **API pagination**: both scripts hit the live TN API with the same query params. A record appearing between the two runs could land in one but not the other — keep runs as close in time as possible.

## Verification

1. Capture output:
   ```
   php iznik-server/scripts/cron/tn_sync.php 2>legacy.log
   docker exec freegle-batch php artisan tn:sync 2>&1 | tee port.log
   ```
2. Extract traces and diff:
   ```
   grep -oE 'TN-SYNC-TRACE .*' legacy.log > legacy.trace
   grep -oE 'TN-SYNC-TRACE .*' port.log > port.trace
   diff legacy.trace port.trace
   ```
   A clean diff proves event-and-write-level parity.
3. Prove the port didn't write:
   - Count rows in affected tables (`ratings`, `users_aboutme`, `users_replytime`, `users`, `users_emails`, `logs`, `audits`) before and after the port run (with legacy paused) — must be unchanged.
   - `/etc/tn_sync_last_date.txt` mtime must not advance from the port's run.
   - `/var/log/freegle/batch_event.log` must not grow from the port's run (beyond the `runWithLogging` start/stop pair if left in).
