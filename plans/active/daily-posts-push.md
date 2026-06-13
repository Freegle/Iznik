# Daily "new posts near you" push notification (app)

**Branch:** `feature/daily-posts-push` (monorepo) + a branch in the separate `capacitor-push-notifications-cap7` repo.
**Status:** In progress (started 2026-06-13).

## Goal

Now that unified digests exist, send app users **one daily push notification** summarising new
OFFER/WANTED posts near them — the push analogue of the unified daily email digest. The app today
only has chat push. This rides on the unified-digest foundation (`UnifiedDigestService::getPostsForUser()`).

User steer:
- **Rich native rendering on BOTH Android and iOS** where possible (not just a single text line).
- **Two-phase delivery is fine**: ship the app changes (rich rendering) first, then activate the
  server send once those app versions are live in the stores.
- A digest may contain **tens of posts** → show a couple of items + "and N more"; consider other ideas.

## Existing prep we build on (do NOT rebuild)

- `iznik-server/include/user/PostNotifications.php` — complete V1 reference impl (never scheduled). Per-group model.
- `CATEGORY_NEW_POSTS='NEW_POSTS'` → Android channel `new_posts`, iOS passive (V1 `PushNotifications.php:29,62-67`).
- `new_posts` Android channel already registered client-side (`iznik-nuxt3/stores/mobile.js:358`, LOW importance).
- `users_push_notifications` device tokens (FCMAndroid/FCMIOS, apptype User|ModTools).
- `PushNotificationService::sendFcm()` (iznik-batch) — live FCM engine, Android/iOS branching, token cleanup.
- `background_tasks` queue + `ProcessBackgroundTasksCommand`; daily scheduler pattern (`routes/console.php:386-392`).
- `UnifiedDigestService::getPostsForUser()` (`:898`) + `deduplicatePosts()` (`:957`) — the data seam.
- `users_postnotifications_tracking` table migration already deployed in iznik-batch (we'll use `users_digests mode='push'` instead — cleaner unified-per-user cursor).

## Design decisions

1. **Unified per-user, not per-group.** One daily push covering all the user's groups (deduped), matching
   the unified email digest — NOT V1's one-push-per-group (which would re-spam London users). Reuse
   `getPostsForUser()` with a new `users_digests.mode='push'` cursor so it advances independently of email.
2. **Data-only on Android.** NEW_POSTS is sent data-only (no FCM `notification` block, `forceVisible=false`)
   so the forked plugin's `NotificationHelper.onMessageReceived` fires in all app states and renders our
   rich notification. (A `notification` block would make FCM draw a plain one itself.)
3. **Adaptive rich rendering** (the "tens of posts" answer):
   - **1 post** → Android `BigPictureStyle` with the item photo; iOS image attachment. Title = item name.
   - **≥2 posts with ≥2 photos** → a **photo collage** of the top posts (Android `BigPictureStyle` of a
     natively-tiled mosaic; iOS NSE composes the same), title "N new things near you", item names in the
     summary line. (Multiple photos — composed in the native layer from the `images[]` URLs.)
   - **≥2 posts with <2 photos** → Android `InboxStyle`: up to 5 item lines + "+N more" + summary; iOS
     multiline body. (Text fallback when there aren't enough photos.)
   - Collapsed line everywhere: `title` "N new things near you", `message` "Sofa, Coffee table, Bookshelf +2 more".
   Old app versions (pre-Phase-A) just show `title`+`message` (graceful degradation) — this is why the
   server stays gated until adoption.
4. **Opt-in:** new key `settings.notifications.dailypostspush` (default **true**), surfaced in an app-only
   settings section. Eligibility also requires an `apptype=User` FCM token. `simplemail` stays orthogonal
   (push independent of email), per V1 intent.
5. **Timing:** 07:00 UK local, DST-aware, self-healing — mirror the digest scheduler exactly. (Sent slightly
   after the email digest window so it doesn't collide; final offset TBD in B.)
6. **Tap target:** `route='/browse'`.
7. **Gating:** `FREEGLE_POSTS_PUSH_ALLOWLIST` env (default empty = send to nobody), `*` = everyone — mirrors
   `FREEGLE_DIGEST_DAILY_ALLOWLIST`. Lets us ship the server dark and switch on after app adoption.

## FCM data payload contract (NEW_POSTS) — the cross-layer linchpin

All FCM `data` values are **strings** (FCM requirement). Fields:

| field | example | consumed by | notes |
|-------|---------|-------------|-------|
| `channel_id` | `new_posts` | Android native + JS gate | required; JS/native drop notifications without it |
| `category` | `NEW_POSTS` | Android + iOS native | selects rich renderer + (no) action buttons |
| `notId` | `200000001` | Android native | **constant** for the daily digest so today's push replaces yesterday's |
| `count` | `7` | native (badge), JS | total new-post count; drives badge + "+N more" |
| `title` | `7 new things near you` | native collapsed + iOS | |
| `message` | `Sofa, Coffee table, Books +4 more` | native collapsed + iOS body fallback + old apps | single line |
| `route` | `/browse` | JS handler (`router.push`) | tap target |
| `image` | `https://…/img_123.jpg` | Android BigPicture/largeIcon, iOS NSE | first/best post photo; may be empty |
| `images` | `["https://…/a.jpg","https://…/b.jpg"]` | Android collage, iOS NSE collage | JSON array, ≤4 photo URLs across top posts; ≥2 → tiled collage (2=side-by-side, 3=1+2, 4=2×2), else fall back to text list |
| `lines` | `["Offer: Sofa (Kingston)","Wanted: Bike (Surbiton)",…]` | Android InboxStyle, iOS body | JSON-encoded array, ≤5 entries |
| `summary` | `Freegle • 7 new posts` | Android InboxStyle summaryText | |
| `moreCount` | `2` | Android "+N more" line | `count - len(lines)`; "0" if none |
| `timestamp` | `1749800000` | Android `setWhen` | unix seconds (optional) |
| `badge` | `7` | JS `setBadgeCount` | mirrors count |
| `content-available` | `1` | iOS | wake for NSE |
| `modtools` | `false` | JS | FD app, not MT |

Single-post variant: `count=1`, `title` = item name, `lines` = `["Offer: Sofa (Kingston)"]`, `image` set →
native picks BigPicture.

## Phase A — app prep (ships to stores FIRST)

### A1. Native plugin `capacitor-push-notifications-cap7` (separate repo, own PR + npm bump)
- Android `NotificationHelper.java`: when `category==NEW_POSTS`, render adaptive InboxStyle/BigPicture from
  `lines`/`image`/`summary`/`moreCount`; keep single-line fallback for missing fields. Use the `new_posts` channel.
- Android `PushNotificationsPlugin.java`: `CATEGORY_NEW_POSTS` constant; no action buttons (passive).
- iOS: add a **Notification Service Extension** (`mutable-content`) to download `image` and attach it; register
  a passive `NEW_POSTS` `UNNotificationCategory`. Body = item list.
- Bump `dist/definitions.d.ts` if API surface changes; `npm run build`; commit `dist/`.

### A2. iznik-nuxt3 client (monorepo)
- `stores/mobile.js`: ensure `new_posts` handled (route deep-link already generic); optional foreground refresh of
  browse store. Confirm cold-start route propagation.
- `capacitor.config.ts` / iOS: register NSE target.
- New app-only settings toggle → `settings.notifications.dailypostspush`.
- Bump `@freegle/capacitor-push-notifications-cap7` version in `package.json` once A1 is published.

### A3. iznik-server-go (monorepo)
- `user.ApplySettingsDefaultsToJSON`: inject `notifications.dailypostspush=true` default on read.

## Phase B — server activation (monorepo, gated dark until adoption)

### B1. iznik-batch
- `PushNotificationService`: `CATEGORY_NEW_POSTS` const + `notifyDailyNewPosts(User,$posts)` building the payload
  above and calling `sendFcm(...,forceVisible:false)`; `buildDailyNewPostsPayload()` (item-name extraction,
  photo URL, lines, dedup already done upstream).
- Migration: extend `users_digests.mode` enum to include `push` (+ idempotent `*_migration.sql`).
- `push:daily-posts` artisan command (mirror `SendUnifiedDigestCommand`): allowlist-gated, per-user cursor via
  `users_digests mode=push`, eligibility = FCM `apptype=User` token + `dailypostspush` on + active, calls
  `getPostsForUser()` → `deduplicatePosts()` → `notifyDailyNewPosts()`.
- Scheduler entry in `routes/console.php` (07:00 UK, self-healing), gated.
- Tests (port `PostNotificationsTest` scenarios: no-posts, no-subscription, own-posts-excluded, multi-post summary,
  cursor advance, opt-out, allowlist).

## Validation
- Laravel: status-API `/api/tests/laravel` (worktree); Go: status-API; vitest for mobile.js/settings.
- **Android emulator visual inspection** of the rich notification (user asked) — build app, install, fire a test
  data push, screenshot collapsed + expanded.

## Status table

| # | Task | Phase | Repo | Status | Notes |
|---|------|-------|------|--------|-------|
| 1 | Research + inventory | — | — | ✅ | workflow + V1 ref read |
| 2 | Worktree + branch + plan + contract | — | mono | ✅ | this file |
| 3 | Android native rich rendering (Inbox/BigPicture) | A1 | plugin | ✅ | NotificationHelper.applyNewPostsStyle + CATEGORY_NEW_POSTS |
| 4 | iOS NSE + NEW_POSTS category | A1 | plugin+mono | ✅ (code) | NSE source + passive category; Xcode target wiring is a manual step (SETUP.md) |
| 5 | Plugin: build dist, commit, (PR) | A1 | plugin | ✅ | dist unchanged (native-only); PR capacitor-push-notifications-cap7#1, v7.0.3 |
| 6 | Android emulator visual inspection | A1 | — | ✅ | InboxStyle + BigPicture verified on emulator-5554 (API 34); screenshots sent |
| 7 | mobile.js new_posts handling + foreground | A2 | mono | ✅ | vitest mobile.spec 56✓ (5 new) |
| 8 | App settings toggle (dailypostspush) | A2 | mono | ✅ | AppNotificationsSection.vue; vitest 18✓ |
| 9 | package.json plugin bump | A2 | mono | ⬜ | deferred until plugin PR merged+tagged |
| 10 | Go ApplySettingsDefaultsToJSON default | A3 | mono | ✅ | +tests; fixed 2 pre-existing ReturnsSameBytes fixtures my change broke |
| 11 | PushNotificationService: NEW_POSTS + payload | B1 | mono | ✅ | notifyDailyNewPosts + buildDailyNewPostsPayload; sendFcm iOS mutable-content scoped to NEW_POSTS only |
| 12 | users_digests mode=push migration (+SQL) | B1 | mono | ✅ | enum extend + idempotent SQL |
| 13 | push:daily-posts command (allowlist-gated) | B1 | mono | ✅ | FREEGLE_POSTS_PUSH_ALLOWLIST default '' |
| 14 | Scheduler entry 07:30 UK | B1 | mono | ✅ | gated, once-per-day guard, self-healing |
| 15 | Laravel tests | B1 | mono | ✅ | DailyPostsPushTest 18/18 ✓ |
| 16 | Run all suites + emulator validation | — | — | ✅ | Laravel 18✓, vitest 18+56✓, Go 3171✓, Android emulator✓ |
| 17 | PR(s) + session log | — | — | ✅ | monorepo Iznik#741, plugin cap7#1 |

## Result (2026-06-13)
- **Monorepo PR:** https://github.com/Freegle/Iznik/pull/741 (gated server + Go default + client).
- **Plugin PR:** https://github.com/Freegle/capacitor-push-notifications-cap7/pull/1 (Android+iOS rich rendering, v7.0.3).
- All testable suites green; Android rendering verified on emulator (API 34).
- **Remaining (humans / follow-up):** merge plugin PR + tag → bump iznik-nuxt3 package.json to 7.0.3;
  add the iOS NSE Xcode target per plugin `ios/NotificationServiceExtension/SETUP.md`; once apps adopted,
  set `FREEGLE_POSTS_PUSH_ALLOWLIST` (pilot emails → `*`). iOS not buildable here (no macOS).
