# Digest: reduce repeat views by tracking what we've mailed / shown per member

> **IMPLEMENTED (PR #1085, feature/digest-browse-seen-dedup) — but with a KEY PIVOT.**
> The send-tracking design below (a per-(member,post) `channel`/`digest_mailed` ledger) was
> DROPPED: sending a digest doesn't mean it was read, so it would hide posts from people who
> dip in and out. Instead the shipped design keys off **digest READ/CLICK**: `mail:digest:mark-seen`
> turns an opened/clicked digest (email_tracking + metadata.post_msgids) into a `messages_likes`
> 'View' marker per (msgid,userid). That one signal (in-app view ∪ digest open/click) drives BOTH
> the daily digest (seen_penalty in scoreAndSortAvailable) AND the browse feed (already sorts
> unseen-first on the same join — no browse change). Live coverage check: 65% of digest recipients
> have an open/click within 30d; the rest keep current behaviour (nothing hidden). Opens are
> down-rank-only (Apple MPP false opens); click/in-app-view are the strong signals.
> The section below is kept as the superseded design rationale.

---

Status: design (scratch). 2026-07-16.

## Problem

The digest's only repeat-avoidance is an **arrival watermark**, not a memory of what
each member has seen:

- Daily: per-user cursor `users_digests.lastmsgid/lastmsgdate` (advanced by
  `updateDigestTracker()`).
- Immediate: per-group cursor `groups_digests`.

Both are keyed on `messages_groups.arrival`. **Auto-reposts bump `arrival`**
(`AutoRepostService`: offer ~3d, wanted ~7d, up to 5×), so a reposted item
re-crosses the watermark and is mailed again — even to a member who already saw it,
in-app or in a previous digest. There is no per-(member, post) record for the digest.

## What we already have

- **Per-member in-app views**: `messages_likes (msgid, userid, type IN
  ['Love','Laugh','View'], count)`, unique `(msgid,userid,type)`. A `View` row is
  written client-side when a **signed-in** member actually looks at a post in the
  web/app (feed dwell or detail page), source-tagged (`browse` / `message_page` /
  email `?src=` click-through). NOT email opens, NOT logged-out. Today the digest
  only uses the **global** `SUM(count) WHERE type='View'` as a freshness signal in
  `DigestPostScorer` ("less-seen [globally] floats up") — never per recipient.
- **Per-(member, post) mail tracking precedent**: `rippling_reach_notified
  (msgid, userid, notified_at)` PK `(msgid,userid)` — exactly the shape we need, but
  only for Rippling reach mail.
- **Per-user daily scoring**: `getPostsForUser()` → `DigestPostScorer::score(...)`
  runs per user, so a per-member penalty drops straight in.

## Proposal

### 1. Track sends per member — GENERALISE the notified ledger (decided)

Rather than a new table, generalise `rippling_reach_notified (msgid, userid,
notified_at)` into one per-(member, post) ledger keyed by **channel**:
`messages_notified (msgid, userid, channel, notified_at)`, PK
`(msgid, userid, channel)`. Existing rows become `channel='reach'`; the digest
writes `channel='digest'`.

- Write on **spool success** (not selection — a failed send must not mark mailed):
  daily writes a `digest` row per included post alongside `updateDigestTracker()`;
  immediate writes per recipient per message actually mailed (batch `insertOrIgnore`).
- **Bounded storage** (per-user × per-post): only rows for posts actually mailed,
  plus a nightly prune `DELETE WHERE notified_at < now() - 90d` (Message::EXPIRE_TIME).
  Reach already relies on FK cascade for message deletion; keep that.

#### DDL (Laravel migration — source of truth)

```php
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('messages_notified')) return;           // already generalised

        if (!Schema::hasTable('rippling_reach_notified')) {          // fresh env: create directly
            Schema::create('messages_notified', function (Blueprint $t) {
                $t->unsignedBigInteger('msgid');
                $t->unsignedBigInteger('userid');
                $t->string('channel', 16)->default('reach');
                $t->timestamp('notified_at')->useCurrent();
                $t->primary(['msgid', 'userid', 'channel']);
                $t->index('userid');
            });
            DB::statement('ALTER TABLE messages_notified ADD CONSTRAINT messages_notified_msgid_foreign
                FOREIGN KEY (msgid) REFERENCES messages (id) ON DELETE CASCADE');
            DB::statement('ALTER TABLE messages_notified ADD CONSTRAINT messages_notified_userid_foreign
                FOREIGN KEY (userid) REFERENCES users (id) ON DELETE CASCADE');
            return;
        }

        // Generalise in place, then rename (FKs + userid index move with the table).
        if (!Schema::hasColumn('rippling_reach_notified', 'channel')) {
            DB::statement("ALTER TABLE rippling_reach_notified
                ADD COLUMN channel VARCHAR(16) NOT NULL DEFAULT 'reach'");   // existing rows -> 'reach'
        }
        // Single ALTER: msgid stays leftmost so the msgid FK index is never lost.
        DB::statement('ALTER TABLE rippling_reach_notified
            DROP PRIMARY KEY, ADD PRIMARY KEY (msgid, userid, channel)');
        Schema::rename('rippling_reach_notified', 'messages_notified');
    }

    public function down(): void
    {
        if (!Schema::hasTable('messages_notified')) return;
        DB::table('messages_notified')->where('channel', '!=', 'reach')->delete(); // avoid dup PK
        Schema::rename('messages_notified', 'rippling_reach_notified');
        DB::statement('ALTER TABLE rippling_reach_notified
            DROP PRIMARY KEY, ADD PRIMARY KEY (msgid, userid)');
        if (Schema::hasColumn('rippling_reach_notified', 'channel'))
            DB::statement('ALTER TABLE rippling_reach_notified DROP COLUMN channel');
    }
};
```
Prod: also ship idempotent `*_migration.sql` (ADD COLUMN IF NOT EXISTS pattern +
rename guard). FK constraint names stay `rippling_reach_notified_*` after rename
(cosmetic) — optionally rename them in the same migration.

#### Code migration — MUST land before any `digest` row is written
Existing consumers treat "row exists" as "reach mail sent". Once other channels
share the table they MUST filter `channel='reach'`, or reply attribution
(`notified_at <= replied_at`) silently counts digest sends as reach:
- Rename table `rippling_reach_notified` -> `messages_notified` everywhere.
- Reach WRITES (make channel explicit): `UnifiedDigestService.php:438,739`
  insertOrIgnore -> add `'channel' => 'reach'`.
- Reach READS (add `AND channel='reach'`): `UnifiedDigestService.php:664` (EXISTS);
  `ReplyAttributionBackfillService.php:71,93`; Go `rippling/metrics.go:235`;
  Go `chat/chatmessage.go:395`.
- Go tests that `CREATE TABLE ... rippling_reach_notified` / INSERT:
  `chatmessage_reach_test.go`, `rippling_metrics_test.go` — add `channel` + rename.
- Sequence: (a) migration + rename + `channel='reach'` filters shipped and green;
  (b) THEN digest starts writing `channel='digest'`; (c) THEN scorer/suppression uses it.

### 2. Use it — reorder (daily) / suppress (immediate)
- **Daily** (`DigestPostScorer`, per user): add a **penalty** when the post is in
  `digest_mailed` for this user, and/or has a per-user `messages_likes 'View'` row.
  Down-rank rather than hard-drop, so a still-available high-value repost can still
  appear, just below fresh posts. Hard-drop only after K mailings with no engagement.
- **Immediate** (per-group, not personalised ordering): simplest win is to **skip**
  a message for a member when `digest_mailed(msgid,userid)` already exists — i.e. an
  already-mailed repost isn't re-sent. Directly fixes "got this same offer 3 days ago".

### 3. Fold in existing per-user views
`messages_likes` `View` rows already mark "seen in app". Feed a per-user
`seenByYou` term into the same penalty so ordering reflects both "we mailed it" and
"you already looked at it". Keep the global `views` term for freshness.

## Risks / decisions
- **Repost = availability signal.** Suppressing entirely could reduce takes.
  Mitigate: daily = down-rank (not remove); cap repeats at K; immediate = suppress
  only exact already-mailed repost.
- **Immediate insert volume**: N recipients × message → batch `insertOrIgnore`.
- **Rollout**: config flag; dark-ship the table (write, don't use) → enable the
  penalty → then the immediate suppression. No backfill (track forward).

## Tests
- Scorer: already-mailed / already-viewed post ranks below an equivalent unseen one.
- Feature: a reposted post in `digest_mailed` is down-ranked (daily) / skipped
  (immediate); a never-mailed post is unaffected.
- Prune: rows older than 90d removed; recent kept.

## Open questions
- Penalty shape (multiplicative decay vs subtractive) and K (max repeats) — tune
  against the `/rippling` "Digest preview" so preview and live stay aligned.
- Do we also want to suppress in the digest a post the member **replied to / took**?
  (Outcome/interested already partly handled via `messages_outcomes` exclusion.)
