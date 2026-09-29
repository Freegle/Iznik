# TrashNothing false merges: findings and repair plan

Status: **the split has been run.** Executed against production at 2026-09-17 11:55:10 on Edward's
instruction, in a single transaction, after two full trial runs that were rolled back. Verified
afterwards. The exact inverse is at
`gs://freegle-tn-merge-evidence/evidence/ROLLBACK-split-43253077.sql`.

All 192 merges in the window were classified against a pre-merge backup. One was a false merge
caused by this defect; that is the one that has been undone. The three same-username cases in §4a
were **not** touched and still await a decision.

No member email addresses here by design. The detailed evidence, which contains them, is in the
private bucket `gs://freegle-tn-merge-evidence` and in `analysis/2026-09-17-tn-false-merges/` on the
FD host.

## 1. The defect

`TNSyncCommand::mergeDuplicateTNUsers` found an account's siblings with
`email LIKE '<username>-g%@user.trashnothing.com'`. The `%` runs past the end of the username, so a
member called `A` also matched a different member called `A-g<something>`. Everything the probe
returned was merged into one account and the rest were deleted. Live from 2026-08-16 (PR #1340,
`0caa8b87b0`) until the exact-username test was added. That fix is live on the FD host and is
carried by PR #1537, which is still open.

## 2. Population, and why it is the right one

192 automatic merges between 2026-08-18 and 2026-09-16, across 103 surviving accounts, from `logs`
where `type='User' AND subtype='Merged'` and the text ends
`(Duplicate TN user created accidentally)`.

`merge-candidates.tsv` lists 193 rows. The extra one is not a 193rd merge: a merge writes two log
rows, one against each account, and for one merge (45069340 into 41265136, 2026-08-18 05:51) the
extraction picked up both. 384 log rows divided by two gives 192, and every merge in the window has
exactly two rows, so nothing is missing from the list.

Checked rather than assumed:

- `User::merge` is called from exactly one place in `iznik-batch`, and that call always passes that
  reason string, so no merge caused by this defect can carry different text.
- The other 24 merges in the window all have a non-null `byuser`, so they are deliberate member or
  moderator merges, not this defect.

## 3. Method

Each merge is classified against the backup taken before it: the same day for a merge after 05:00,
the previous day otherwise. Only the 2026-09-16 03:12 merge needs the previous day. That is 24
backup days, each restored onto a scratch VM and queried read-only, leaving the volunteers'
Yesterday environment alone.

A merge is false when the two accounts held **different TN usernames**, or **two different non-NULL
`users.tnuserid`s**. Those two tests do not always agree, and where they disagree it matters: see
§4a, where the usernames matched at the moment of the merge but the `tnuserid`s did not. The backup
records the usernames as of about 04:19, so a rename later that morning can change what the probe
actually saw; the Loki rename events supply that.

Four independent cross-checks support the backup verdict:

1. **Live state.** `User::merge` moves every `users_emails` row to the survivor and never deletes
   one, so a false merge leaves the survivor holding two username families today. Exactly one
   account does. One other (42971643) looked like two families but is one member whose base address
   simply carries no `-g<NNN>` suffix.
2. **Renames did not erase the evidence.** All 1139 `user-email-rename` events since 2026-08-17 were
   harvested from Loki. None crossed a username boundary, and only two survivors were renamed at
   all, one of them before its merge. So the live screen is trustworthy across the whole window.
3. **Nothing deleted the addresses either.** The only code path that removes a TN address is
   `User::forget`, and Loki records no `user-forget` event in the window. All 103 survivors still
   hold TN addresses today, so no survivor was forgotten.

4. **A screen that does not use addresses at all.** `messages.fromaddr` records the username a
   member posted under at the time, and is never rewritten, so an account's post history is an
   independent record of who used it. Across all 103 survivors, eight have posts under more than one
   TN username. Seven are plain sequential renames: one name stops, the next starts, and the account
   holds exactly that one family today. The eighth is 43346425, which holds two families and has no
   post at all under its own name. Same answer, from different data.

**Where the backup cannot speak, and what covers it.** A backup is taken at about 04:19 and most
merges happen later the same morning, so an account minted in between is not in it. For most merges
that is fine: the deleted account is the one that is missing, the surviving account is present, and
the surviving account is the one that matters. A minority of merges have *both* accounts missing,
because the mail ingest minted both that morning. There the backup gives nothing and the verdict
rests on the live screen alone.

Those cases were checked separately. In every one, the surviving account holds exactly one TN
username family and its addresses were created in the same ingest batch, mostly within a few seconds
of each other. Two accounts minted seconds apart carrying one member's username are that member's,
not two people.

A further corroboration on the ordinary cases: on every backup day, the highest user id present in
the backup is lower than the lowest deleted id absent from it. Ids are handed out in order, so that
is what "these accounts did not exist yet" looks like, and it holds without exception.

The method was validated on the known case before being run across the rest: it reproduced the
answer in the brief exactly.

## 3a. Results

<!-- RESULTS-TABLE -->
All 24 backup days restored and queried; every one of the 193 rows in the candidate file
classified (192 actual merges, one of them listed twice; see §2).

| Outcome | Merges |
|---|---|
| `legit:no-pre-merge-row` | 183 |
| `legit:same-family` | 6 |
| `REVIEW:same-username-two-tnuserids` | 3 |
| `FALSE-MERGE` | 1 |

Four outcomes, and what each means:

- **`legit:no-pre-merge-row`**: the deleted account did not exist when the backup was taken, so the
  mail ingest minted it that same morning and the sync merged it minutes later. The surviving account
  holds one TN username family today. This is the ordinary case the merge exists to handle.
- **`legit:same-family`**: both accounts existed, both carried the same username, and only the
  survivor had a `tnuserid`; the other was minted by the mail ingest. Exactly the legitimate merge the
  brief describes.
- **`REVIEW:same-username-two-tnuserids`**: see §4a. Two live TrashNothing accounts sharing a username.
  Not this defect, and no harm found.
- **`FALSE-MERGE`**: two members with *different* usernames, merged by the prefix defect. §4.

**The search is complete.** Every one of the 24 backup days needed was restored and queried, with no
failures, so no merge in the window is unclassified. One false merge, and it is the one that was
reported.

### The defect explains exactly one merge

Tested directly rather than inferred from the outcome classes. For all 193 rows, both accounts'
usernames **as at the merge instant** were reconstructed (backup usernames, corrected by any Loki
rename between the backup and the merge; for an account minted that morning, the username its address
carries on the survivor today). Result:

- **1 pair** had different usernames at the merge: `bibiana` and `bibiana-gomes`, collision-shaped.
- **192 pairs** had the identical username, which the prefix defect cannot produce.

So the defect accounts for one merge out of 192, and the three `REVIEW` cases in §4a are not instances
of it: their usernames matched exactly.

Where this is inference rather than direct reading: for the 183 merges whose deleted account was
minted that same morning, there is no backup row, so its username is taken from the fact that its
address now sits on the survivor and the survivor holds exactly one family. That rests on three
checked facts, not assumptions: the merge moves every `users_emails` row and never deletes one, no
rename crossed a username boundary in 1139 events, and there were no `forget` events. For a collision
to hide there, a survivor would have to hold two families, and only one ever did.

### Why one is the number to expect

192 merges sounds like a lot to produce a single bad one, so it is worth showing that one is what the
defect could have produced, arrived at without using the classification at all.

The defect only bites when one member's username is another member's username plus `-g`. Across all
TrashNothing addresses in the estate, `collision-pairs-still-separate.tsv` finds **59 such pairs, made
up of 48 distinct shorter names**. That is the entire population at risk.

It then only fires when a **new** address arrives for the shorter-named member, because the
incremental probe looks at nothing else. So the question is how many new addresses arrived for those
48 members between 2026-08-16 and the fix.

**Zero.** Not one of the 48 gained a TrashNothing address in the window. The only member of a
collision-shaped pair who did was `bibiana`, who gained one on 2026-09-13 at 09:05:44, and the merge
followed 21 seconds later. She is absent from the still-separate list for exactly that reason: her
pair is the one that fired.

So the defect had one opportunity in the whole window and took it. Finding a single false merge is
not a thin result; it is the number the exposure predicts, and it agrees with the classification of
all 192 merges and with all four cross-checks in §3.

## 4. The confirmed false merge: 43253077 into 43346425

**2026-09-13 09:06:14, 43253077 merged into 43346425, and 43253077 deleted.**

| | 43253077 (deleted) | 43346425 (survivor) |
|---|---|---|
| TN user | 8893880 | 8996910 |
| Created | 2023-08-04 22:12:39 | 2023-09-14 16:38:00 |
| Location | London SE1 | Carlisle |
| Groups | 14 | 1 |
| Posts | 59 at the backup, 64 on the merged account now | 0 |
| Chat rooms | 176 | 0 |

Two separate members, 300 miles apart, each with their own `tnuserid`.

**How the collision arose.** The London member renamed herself on TrashNothing on 2026-04-11
11:28:05; the sync rewrote all her addresses to the new name, and that new name happens to be a
prefix of the Carlisle member's name. The clash then sat harmless for five months, because the
incremental probe only looks at addresses added since its last run. It fired on 2026-09-13 09:05:44,
when a membership sync added her address for a fourteenth group; 21 seconds later the probe matched
the Carlisle member's address and merged the two accounts.

Her posts still carry both names: 25 under the old one and 39 under the new one, because
`messages.fromaddr` records the name at the time of posting and is not rewritten. All 64 sit on the
Carlisle member's account today.

## 4a. A separate class: same username, two TrashNothing accounts

Several merges in the window united two accounts that each carried **its own non-NULL `tnuserid`**
but had the **same username at the moment of the merge**. By the brief's decisive test they are false
merges. They are not this defect, **the exact-username fix would not have changed any of them**, and
on the evidence none has harmed anyone. They need a decision, not a repair.

| Merged | Deleted | Survivor | Username at merge | TrashNothing users |
|---|---|---|---|---|
| 2026-08-31 16:56:07 | 44946848 | 42730993 | `mrnobody202022` | 10859337, 10584514 |
| 2026-09-02 13:44:02 | 45044348 | 44110508 | `cavanaghryan543` | 10962700, 10452451 |
| 2026-09-04 17:02:04 | 45083300 | 44896143 | `davelee` | 11010238, 10808097 |

In each, the older account goes back a year or more and the newer one was created weeks before the
merge. In the third, the newer account arrived under a different name and TrashNothing renamed it to
match the older one a second before the merge fired.

### The test that matters

Whether these are one person with two TrashNothing accounts is not something Freegle's data can
prove. The question that can be answered, and the one that decides whether to act, is narrower:
**is anyone's content or mail now going to the wrong person?**

For all three, no:

- Only one of the two identities has ever produced anything. The newer account has no posts and no
  chats in any of the three cases; it brought group memberships and nothing else.
- The merged account's preferred address is its own, so mail goes to the identity that is actually
  using it.
- In the `cavanaghryan543` case TrashNothing anonymised the account six days after the merge, renaming
  all twelve addresses to a hex string. That account has been untouched since 2026-09-08. Splitting it
  would mean recreating a Freegle account for a TrashNothing user who has since deleted themselves.

Set against the London case the contrast is complete: there, two username families, two people 300
miles apart, both producing content in the same week, the wrong member receiving the other's chat
mail, and a complaint. Here, one active identity per account and nothing mis-delivered.

One honest caveat: in the `mrnobody202022` case the two accounts' locations are about 100 miles
apart, and the newer account held groups in both areas. That is odder than the other two, where the
locations match. It still produced nothing and nothing is mis-delivered.

**Recommendation: leave all three, and confirm with TrashNothing.** TN can say whether each newer
`tnuserid` replaced the older one for the same person. I have not acted on any of them. If you decide
one should be split, the backups are preserved and the same extraction produces the inventory and
repair for it, about ten minutes each.

One detail for the `davelee` case if it is ever split: that account held two TrashNothing addresses
and only one survives. The other was for the same TrashNothing group as an address the surviving
account had held since May, so when the rename tried to give it that name the unique index on
`users_emails.email` left no room and it was dropped. No mail is lost, because the remaining address
covers that group, but the row is gone and a split cannot simply re-point it.

### This is not rare, and more are queued

Right now **11 TrashNothing usernames in the live database each span two accounts with two different
`tnuserid`s**, still unmerged. They are 10 distinct account pairs, not 11: one member has used two
names over the years and appears under both. The daily full scan groups on username, so it will unite
them as it finds them. On the evidence above that is usually harmless, but it is happening steadily
and silently.

That same pair is a reminder that an account can hold two username families for an ordinary reason.
One of them has been going since 2014 and picked up its second family in 2021, long before this
defect existed, because a rename adds addresses under the new name without always removing the old.
Two families on an account is a signal worth looking at, not proof of a bad merge.

### The wider point, and a correction

`mergeDuplicateTNUsers` merges on username alone and never looks at `tnuserid`. My first instinct was
that it should refuse when both accounts carry different non-NULL `tnuserid`s. Three instances in one
month, plus eleven more waiting, say otherwise: a member who deletes their TrashNothing account and
makes a new one gets a new `tnuserid` and usually the same username, and uniting those is what they
want. A hard guard would split those members' histories instead.

So the exact-username fix in PR #1537 is the right fix for the actual harm, and it is sufficient for
it. What is worth adding is not a refusal but a **record**: log distinctly when a merge unites two
accounts that each carry a different non-NULL `tnuserid`, so this is visible rather than silent.

It also means the brief's decisive test needs a companion. "Each account carried its own non-NULL
`tnuserid`" does catch the London case, but on this month's data it produces three false positives
for one true one. The test that separates harm from housekeeping is whether the **usernames differed
at the moment of the merge**, which the backup alone cannot answer, because it is taken at about
04:19 and a rename later that morning changes what the probe saw. The classification reads the Loki
rename events alongside the backup for exactly that reason.

## 5. What the survivor's account holds now, and who each part belongs to

- **All 178 chat rooms are the victim's.** 176 moved by the merge, named row by row in the write
  trace. The other two (21271946, 21281132) were created *after* the merge, on 2026-09-14 and
  2026-09-16, by the victim's own replies. The survivor has no chat room of her own. Room 21281132
  took a message at 09:38 on 2026-09-17, so the wrong-recipient harm is still live.
- **17 memberships.** One (253520 Carlisle-Freegle) is the survivor's. 14 moved by the merge, one
  more (21277) was added at 09:05:44 by the sync that triggered the merge. Two (21662, 253508) were
  added at 14:41:39 by Freegle's own rippling acting on the merged, wrong location.
- **All five posts since the merge carry a victim `fromaddr`.**
- **Identity fields on the survivor that are now the victim's:** `added` (set by the merge),
  `fullname` (set the next morning by `users:fix-tn-names`, see §8c), `lastlocation` (changed by the
  victim's later activity). `tnuserid` is untouched and still correct: the merge only moves a
  `tnuserid` when the survivor has none, so the victim's 8893880 was simply lost with the row.

**The harm is live and one-directional.** The merge set `preferred=1` on 43346425's own address
(`users_emails` id 130323844, the Carlisle member's), so every notification for the account, including
replies in the London member's chats, is delivered to the Carlisle member. The London member can still
act through TrashNothing's web app, which is how her replies on 2026-09-14 and 2026-09-16 reached us,
but she does not receive the answers. In chat 21281132 the offerer sent two further messages at
09:38 on 2026-09-17, both delivered to the wrong member.

Every post-merge action is attributed from evidence, not inference:

| What | Evidence |
|---|---|
| 176 chat rooms, 14 memberships | `TN-SYNC-TRACE [WRITE]` lines, row by row |
| Chat reply 2026-09-16 11:59:50 | Archived inbound mail, `X-trash-nothing-User-ID: 8893880` |
| Chat reply 2026-09-14 10:22:56 | Loki `incoming_mail`, envelope from the victim's address |
| 5 posts since the merge | `messages.fromaddr`, all the victim's family |
| 2 rippled memberships | `logs` `Group/Joined`, text `Rippled`, no `byuser` |

## 6. What the two members have experienced

Worth knowing before deciding who to contact and what to say.

**The London member (43253077).** Her account vanished on 2026-09-13. Since then she has kept using
Freegle through TrashNothing's web app, apparently without realising: four posts and two
replies to other people's offers. None of the answers reached her, because the account her activity
now lands on delivers its mail to someone else. From her side, Freegle has been silently swallowing
her replies for four days. She also lost her daily digest for three groups. One of her offers on
2026-09-16 was posted again two hours later, which is what someone does when a post does not seem to
have worked.

**The Carlisle member (43346425).** Her account now carries a stranger's 178 chat rooms, 64 posts,
16 group memberships and display name, and she has been receiving that stranger's chat mail,
including two messages at 09:38 on 2026-09-17. She has had no way to tell what happened.

Both are consequences of the merge, and both stop when it is undone.

## 7. The merge destroyed rows it did not move

This is the part the brief's method does not cover, and it changes the repair.

`User::merge` moves 42 tables. The schema has **110 columns with a foreign key to `users.id`**, of
which **80 are `ON DELETE CASCADE` and 30 are `ON DELETE SET NULL`**. 55 of those columns sit on
tables the merge never touches, so when the victim's `users` row was deleted, their rows there were
deleted or nulled rather than moved.

For this case, measured against the pre-merge backup, **163 rows**:

| Table | Rows | Rule | Effect |
|---|---|---|---|
| `users_expected.expectee` | 60 | CASCADE | deleted |
| `visualise.touser` | 44 | CASCADE | deleted |
| `users_donations_asks.userid` | 24 | CASCADE | deleted |
| `email_tracking.userid` | 23 | SET NULL | row survives, attribution lost |
| `users_digests.userid` | 3 | CASCADE | deleted: the member's daily digest settings for 3 groups |
| `firstreply_scouts`, `logs_emails`, `rippling_reach_notified` | 2 each | CASCADE | deleted |
| `messages_matched_notified`, `newsfeed_users`, `users_approxlocs` | 1 each | CASCADE | deleted |

140 rows are gone from the database entirely and have to be re-inserted from the backup. The 23
`email_tracking` rows survive with a NULL `userid` and can be re-pointed.

The `users_digests` loss is member-visible: they stopped getting the daily digest for three groups.

**Reconciliation.** For every table the merge does handle, the pre-merge counts balance exactly
against the live counts: `victim + survivor + (rows created since the merge) = live`, with every
increment accounted for. Nothing is missing from those tables, and nothing is unexplained.

## 8. Three further defects, all still live

### 8a. A merge that unites two TrashNothing accounts is not recorded as such

Covered in §4a. `mergeDuplicateTNUsers` decides purely on username, so it cannot tell a member's
duplicate account from a second TrashNothing account belonging to the same person, and it leaves no
distinct trace when it unites two live `tnuserid`s. That happened twice in a month without anyone
knowing. This is a logging gap rather than a guard: the same-username unions look like what the
members wanted.

### 8b. The same prefix bug is in the rename path

`TNSyncCommand::syncUserChanges` rewrites a member's addresses on a TN name change:

```php
$oldname = User::removeTNGroup($user->fullname ?? '');
foreach ($emails as $email) {
    if (str_contains($email, "{$oldname}-")) {
        $newEmail = str_replace("{$oldname}-", "{$change['username']}-", $email);
```

`str_contains` is not anchored to the username, so with `$oldname = 'A'` it also matches
`A-<suffix>-g4840@...` and rewrites it, corrupting a different member's address. Demonstrated
directly:

```
REWRITES A-g288@...              -> A2-g288@...
REWRITES A-<suffix>-g4840@...    -> A2-<suffix>-g4840@...     <-- a different member
```

The fix is the same shape as the merge fix: compare the extracted username exactly
(`tnUsernameFromAddress($email) === $oldname`) instead of a substring test. It belongs in PR #1537,
which already touches this file.

**This is armed right now.** 43346425's `fullname` is the other member's username, so the next TN
username push for that account will be read as a rename and will rewrite both families, destroying
one member's address and corrupting the other's. Repairing the case clears it, and so does fixing
the code. One of the two should happen before the next TN name change on that account.

### 8c. `users:fix-tn-names` writes once per address, so the last row wins

`FixTNNamesCommand` joins `users` to `users_emails`, so an account with several TN addresses comes
back several times and `fullname` is written once per row in arbitrary order. On an account holding
two families the wrong family can win. That is what happened at 06:31:04 on 2026-09-14, and it is
what set up 7a. It should derive one name per account, not one per address.

## 9. The repair, as run

Run at 2026-09-17 11:55:10. Production writes went one row at a time, never a bulk statement, in one
transaction so no observer saw a half-split account. Edward confirmed db1 was fine to write to, the
cluster being multi-master.

**The rule the script used, and why it changed.** The first version moved the rows listed in the
pre-merge backup. A trial run showed that was wrong: memberships came out 16/1 instead of 17/0,
because the Brent membership was added at 09:05:44, between the 04:19 backup and the 09:06 merge, so
it was not in the backup at all. The same gap affected `logs`, `messages_history` and
`memberships_history`.

The rule was inverted. The Carlisle account was created and never used: its `added` and `lastaccess`
are the same instant, and its entire footprint is nine rows. So the script moves **everything on the
account except those nine rows**, plus the two rows the merge itself created for her (her audit log
entry and her Freegle-side address). That cannot miss anything created in the gap window, which the
backup-driven version did.

The script is `gs://freegle-tn-merge-evidence/evidence/repair-43253077.sql`: **1844 UPDATEs and 140
INSERTs**. Every UPDATE re-asserts the current owner in its `WHERE` clause, so re-running is safe
and a statement matching zero rows means the database is not in the state that was surveyed, which
is a signal to stop.

**Pre-flight, run read-only against production on 2026-09-17.** Every row the script targets was
checked against its current owner. All tables matched 100%, except `email_tracking` (20 of 23 rows
still present) and `messages_history` (70 of 75), where the remainder had aged out under normal
retention since the backup. Those eight statements will match nothing, which is expected; a zero
match anywhere else means the database has moved since the survey and the run should stop.

The 140 re-inserts were pre-flighted too. Nothing anywhere in the database references 43253077, and
every key on those tables includes the user column, so none of the keys can already be taken. Two
of the tables (`messages_matched_notified`, `rippling_reach_notified`) have a composite key of
message and user rather than a surrogate id, which is worth knowing before running them.

1. **Recreate 43253077** with its original id, every column from the 2026-09-13 04:00 backup row,
   including `tnuserid = 8893880`. The id is free (nothing references it) and `AUTO_INCREMENT` is
   45111646, far above it, so there is no collision risk. `users` has no triggers. This must come
   first: the step-4 re-inserts have foreign keys to it.

   Three fields should come from the *live* row rather than the backup, because they changed through
   the London member's own activity after the merge and are therefore hers, not a merge artefact:
   `lastaccess` (now 2026-09-16 13:05:34), `lastlocation` (now an SE1 location, matching where every
   one of her recent posts is) and `settings`. Taking the backup values instead would roll her
   account back five days for no reason.
2. **Give 43346425 its own identity back**: `fullname`, `added = 2023-09-14 16:38:00`, and its
   pre-merge Carlisle `lastlocation`. Also `settings`, which was NULL before the merge and now holds
   a browse-preferences blob (`browseMaxMinutes`, `browseDensityBand`, `browseReachMaxDistance`).
   The merge never wrote it, so the London member's browsing created it after the merge: null it on
   43346425 and carry the live blob across to 43253077, which keeps her most recent preferences
   rather than the five-day-old ones in the backup.
3. **Move back the rows the merge re-pointed**, 1832 statements, plus the nulled `email_tracking`
   rows.
4. **Re-insert the 140 destroyed rows.**
5. **Move the 12 post-merge rows** (2 chat rooms, 2 chat messages, 2 roster rows, 5 posts, 1
   address), each attributed by evidence in §5.
6. **Decision needed:** the two rippled memberships (21662, 253508) exist only because of the merge
   and were nobody's own choice. Both are London groups and the London member's real location is
   London, so rippling would probably add them again from her own account after the split. That
   makes moving them to 43253077 the lower-surprise option, but removing them is the more literal
   undo. Your call; the script does not touch them either way.
7. **Verify**: each account holds one TN username family and its own `tnuserid`; neither sees the
   other's chats; display name and location are their own.

One gap worth stating: the backup is from 04:19 and the merge was at 09:06, so rows created in
between are not in it. For this case the write trace covers that window for the tables that matter,
and the reconciliation in §7 accounts for every row. A false merge on a day before 2026-09-09 would
have no trace, and rows created in that window would need attributing another way.

## 10. Evidence preserved

`gs://freegle_backup_uk` deletes objects at 30 days. The 2026-08-18 backup, the only pre-merge
snapshot for the 28 merges that morning, was already past its deletion age when this started. All 31
daily backups (1.62 TiB) were copied to `gs://freegle-tn-merge-evidence`, which has no lifecycle
rule, together with the Loki `tn-sync` events, the archived inbound mail, the pre-merge row dumps and
the repair script.

Other sources are shorter than the brief assumes and are still shrinking:

| Source | Covers | Note |
|---|---|---|
| Loki `api`, `api_headers`, `client` | 7 days | Already gone for merges before about 2026-09-10 |
| Loki `batch`, `batch_event`, `email` | 31 days rolling | Harvested to file |
| Batch logs on disk | from 2026-09-09 | 8-day rotation |
| Inbound mail archive | 3 days | Relevant messages copied out |
