# Cron mail migration — parity audit

Adversarial V1-parity audit of four iznik-server cron scripts migrated to Laravel
artisan commands. For each: the V1 source, the Laravel target, the operations
checked, and any deviations (with justification).

Source of truth: `iznik-server/scripts/cron/*.php` and the classes they call.

| V1 script | Artisan command | Service |
|-----------|-----------------|---------|
| `alerts.php` | `mail:alerts:send` | `AlertService` |
| `user_exhort.php` | `notifications:exhort` | `NotificationExhortService` |
| `chat_chaseup_expected.php` | `chats:chaseup-expected` | `ChatChaseupExpectedService` |
| `newsfeed_digest.php` | `mail:newsfeed:digest` | `NewsfeedDigestService` |

---

## 1. alerts.php → `mail:alerts:send`

V1: `Alert::process()` + `Alert::mailMods()` (`include/group/Alert.php`).

| # | V1 operation | Status | Notes |
|---|--------------|--------|-------|
| 1 | `SELECT * FROM alerts WHERE complete IS NULL` | Match | `AlertService::processAlerts()` |
| 2 | Multi-group batch: `type='Freegle' AND id > groupprogress AND publish=1 ORDER BY id LIMIT 50` | Match | |
| 3 | Single-group: `WHERE id = groupid`, complete in one pass | **Fixed** | Was missing — service ignored `alerts.groupid` and fanned every alert to all groups. Now `processAlert()` short-circuits when `groupid` is set. |
| 4 | Mods: `memberships WHERE groupid=? AND role IN ('Owner','Moderator')` | Match | |
| 5 | Per-mod preferred external email (skip bouncing / our domains) | Changed | Sends to the user's single `email_preferred` rather than every non-bouncing address. Avoids duplicate emails to one mod; `email_preferred` already excludes our own alias domains (V1 `Mail::realEmail()`). |
| 6 | Dedup via `alerts_tracking`, always INSERT tracking, send only if not previously sent | Match | |
| 7 | Group contact email + `alerts_tracking` type `OwnerEmail` | **Fixed** | Was missing — `mailGroupContact()` now sends to `groups.contactmail` (when a valid address) and records the `OwnerEmail` row, matching `Alert.php:315-353`. |
| 8 | Update `groupprogress`; mark `complete` | Match | |
| 9 | Standard styling + open-tracking beacon pixel | Match | `AlertMail` is now an `MjmlMailable` using the standard Freegle header/footer, and includes the V1 1×1 beacon (`{site}/beacon/{trackId}`) keyed on the `alerts_tracking` row id (captured via `insertGetId`). Mods use the ModTools site, the group contact uses the user site. The beacon/click endpoints themselves live in the Go/PHP web API (`Alert::beacon()`/`clicked()`), unchanged. |
| 10 | `askclick` confirmation button + `global` "sent to all groups" note | Match | `askclick` renders the "I got this" button (`{site}/alert/viewed/{trackId}`); multi-group (global) alerts show the "sent to all Freegle groups" note. |
| 11 | `cc` self-copy to the alert sender (single-group only) | **Deferred** | Minor diagnostic self-copy; not migrated. |

Tests: `tests/Unit/Services/AlertServiceTest.php` (incl. new single-group and
contact-email cases).

---

## 2. user_exhort.php → `notifications:exhort`

V1: `User::getActiveSince()` + `Notifications::haveSent()` + `Notifications::add()`.

| # | V1 operation | Status | Notes |
|---|--------------|--------|-------|
| 1 | `getActiveSince`: `SELECT id FROM users WHERE lastaccess >= ? AND added <= ?` | Match | `activeSince` / `joinedBefore` parsed with `strtotime`, defaults `5 minutes ago` / `1 week ago`. |
| 2 | Exclude users already sent Exhort in 90 days (`haveSent`) | Match | `users_notifications WHERE touser=? AND type='Exhort' AND timestamp >= now-90d`. |
| 3 | INSERT `users_notifications` (fromuser NULL, type Exhort, url/title/text) | Match | |
| 4 | `from == to` guard | N/A | `fromuser` is always NULL here; guard can't trigger. |
| 5 | Exclude deleted users (`whereNull('deleted')`) | Changed (+) | V1 has no deleted filter. Notifying soft-deleted users is pointless; the filter is a safe improvement. |
| 6 | `PushNotifications::notify($to, FALSE)` after insert | Match | After each insert the service fires a Freegle-app push via the new `PushNotificationService::notifyUser()` (mirrors V1 `notify($uid, FALSE)` + `getNotificationPayload(FALSE)`): badge = unseen chats + notifications, and for the Exhort the title/body/route come from the notification with the `EXHORT` category and `tips` channel. No-op when the user has no registered Freegle-app devices / Firebase is unset. |
| 7 | `-i uid` single-user override (skips cooldown) | **Deferred** | CLI debug option, not used by the production crontab (fixed args only). |

Tests: `tests/Feature/Notification/ExhortUsersCommandTest.php`.

---

## 3. chat_chaseup_expected.php → `chats:chaseup-expected`

V1: `ChatRoom::chaseupExpected()` (`include/chat/ChatRoom.php:2381`).

| # | V1 operation | Status | Notes |
|---|--------------|--------|-------|
| 1 | Join `users_expected`→users→chat_messages→chat_rooms, LEFT JOIN chat_roster | Match | `ChatChaseupExpectedService::chaseupExpected()`. |
| 2 | `chat_messages.date >= midnight 5 days ago` | Match | `Carbon::today()->subDays(5)`. |
| 3 | `replyexpected=1 AND replyreceived=0` | Match | |
| 4 | `chat_roster.status != 'Blocked'` | Match | `status` is NOT NULL (default `Online`), so SQL `!=` parity holds. |
| 5 | `TIMESTAMPDIFF(MINUTE, msg.date, users.lastaccess) >= 1440` | Match | Expectee has been active ≥24h after the message but still not replied. |
| 6 | `chat_rooms.chattype = 'User2User'` | Match | |
| 7 | `GROUP BY expectee, chatid` (one email per user per chat) | Match | Deduped in PHP keeping the most recent qualifying message. |
| 8 | Mail only if `notifsOn(EMAIL)` or TN user | Match | |
| 9 | Skip when the message is the recipient's own (`$justmine`) | Match | |
| 10 | Email = standard user2user chat notification with subject prefixed `WAITING FOR REPLY:` | Match | Reuses `ChatNotification` (template, previous-message context, threading, unsubscribe) via new `waitingForReply` flag + `ChatNotificationService::sendChaseupExpected()`. |
| 11 | LoveJunk users → API call instead of email; `recordSend()` | **Deferred** | LJ chat routing is handled by the existing LoveJunk integration path, not this chase-up. |

Tests: `tests/Feature/Chat/ChaseupExpectedCommandTest.php`.

---

## 4. newsfeed_digest.php → `mail:newsfeed:digest`

V1: `Newsfeed::digest()` (`include/newsfeed/Newsfeed.php:806`).

| # | V1 operation | Status | Notes |
|---|--------------|--------|-------|
| 1 | Groups: `type='Freegle' AND onhere=1 AND publish=1 AND nameshort NOT LIKE '%playground%'` | Match | `NewsfeedDigestService::sendDigests()`. |
| 2 | Per-group `getSetting('newsfeed', TRUE)` | Match | Default on. |
| 3 | Approved members per group; one digest per user per run | Match | newsfeed_users marker + in-run dedupe. |
| 4 | Entry condition: `sendOurMails()` && preferred email && has location && `notificationmails` (default true) | Match | Reuses `User::sendOurMails()` / `getLatLng()`. |
| 5 | Feed: recent root posts of types Message/Story/AboutMe/Noticeboard, not deleted/hidden, unseen, exclude own | Match | |
| 6 | 14-day window; LIMIT 5 items; text length > 40; per-type formatting | Match | AboutMe quoted; Noticeboard "I put up a poster…"; Story "Here's my Freegle story:…". |
| 7 | Up to 5 replies per item | Match | |
| 8 | `REPLACE INTO newsfeed_users` marker (highest id) | Match | `updateOrInsert`. |
| 9 | Subject `"<snippet>" (N conversations from your neighbours[ in locations])` | Match | The `in <locations>` clause is built from each poster's public location (group suffix stripped), as V1 does. |
| 10 | "Nearby" = per-user lat/lng bounding box (`getNearbyDistance` + `MBRContains`) | Match | Ported `GreatCircle::getPositionByDistance` and `getNearbyDistance` (start 800m, double until ~10 posters in range, cap ~20mi) and select via `MBRContains(box, newsfeed.position)` — the same box V1 builds (NE 45°, SW 225°). `minhourage=12` and the user's `mylocation`→`lastlocation` lat/lng are honoured. |
| 11 | Magic login links into `/chitchat` and `/settings` | Match | New `User::loginLink()` produces `?u=&k=` auto-login links using the same `users_logins` (type='Link') key the Go API validates. |
| 12 | Story headline/body from `users_stories` | Changed | Uses `newsfeed.message`; story-row enrichment simplified (the digest text for a Story is the post's own message). |

Tests: `tests/Feature/Newsfeed/SendDigestCommandTest.php`.

---

## Mailpit verification

`alerts`, `chat_chaseup_expected` and `newsfeed_digest` send email; each was run
against the worktree database with the spooler delivering to Mailpit and the
message confirmed present (recipient, subject, body). `user_exhort` sends no
email — verified by the `users_notifications` row insert instead. See the PR
description for the captured Mailpit results.
