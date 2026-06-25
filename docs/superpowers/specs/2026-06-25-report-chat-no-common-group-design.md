# Reporting a chat when there's no group in common

- **Date:** 2026-06-25
- **Discourse:** https://discourse.ilovefreegle.org/t/reporting-chat-with-no-group/9828
- **Branch:** `feature/report-chat-no-common-group`

## Problem

A member (Melissa) received unwanted direct messages from people she met via
ChitChat (the central newsfeed), not via a Freegle group. When she tries to
**Report** the chat, the report modal forces her to pick a community ("Which
community is this about?"). Because the other person shares no group with her,
there is no correct community to choose, so she cannot report at all.

There are two faults today:

1. **No fallback.** If there is no group in common, the report cannot be sent.
   The submit handler silently does nothing when no group is selected
   (`ChatReportModal.vue` `send()` guards on `groupid.value && ...`).
2. **Latent misrouting.** The dropdown is populated from the **reporter's own**
   group memberships (`GroupSelect` defaults `allMy: true`), *not* the groups the
   two participants have in common. So even when it "works" the reporter can pick
   a community the other person isn't in, and the report lands with mods who have
   no context.

## Goals

- When the two participants **share a group**, keep today's behaviour: the report
  goes to that community's moderators. The community selector stays.
- When they **share no group**, provide a fallback: the report goes to the central
  **spam team**, with no community selector and no choice of destination for the
  reporter — a simple click. A note is added so the spam team understands why the
  report reached them.
- Fix the silent no-op so the report button always does something.

## Non-goals

- No change to how mods review group-routed chat reports.
- Not auto-nominating the reported user into the `spam_users` queue. The spam team
  decides that after reviewing — the fallback only *delivers the report to them*.
- No redesign of `GroupSelect` beyond restricting its options for this modal.

## Current behaviour (references)

- **Modal:** `iznik-nuxt3/components/ChatReportModal.vue` — `GroupSelect`, reason
  (`Spam`/`Other`), free-text comment. `send()` requires
  `groupid && reason && comments`, then `chatStore.openChatToMods(groupid)` →
  `chatStore.report(chatid, reason, comments, refchatid)`.
- **Invocation:** `iznik-nuxt3/components/ChatHeader.vue` (~242-302) — Report
  button only for `chattype === 'User2User'`; passes `:user="otheruser"` and
  `:chatid="chat.id"`.
- **Report submit:** `chatStore.report()` → `POST /chat/{roomid}/message` with
  `reportreason` + `refchatid`. `openChatToMods()` → `PUT /chat/rooms`
  (`chattype: User2Mod`, `groupid`).
- **Go API:** `chat.CreateChatMessage` (`iznik-server-go/chat/chatmessage.go`
  ~338) sets `CHAT_MESSAGE_REPORTEDUSER` when `refchatid` is present;
  `chat.PutChatRoom` (`chatroom.go` ~406-431) opens the User2Mod room and adds the
  group's mods to the roster.
- **Mutual-membership SQL** already exists in `canSeeChatRoom`
  (`chatmessage.go` ~782-792): `memberships m1 JOIN memberships m2 ON
  m1.groupid = m2.groupid WHERE m1.userid = ? AND m2.userid IN (?, ?)`.
- **Precedents for central/email routing:**
  - `ReferToSupport` (`chatroom.go handleReferToSupport` ~1459) queues a
    `refer_to_support` background task → `iznik-batch` `ReferToSupportMail` →
    `support_addr`, with a `/modtools/support/refer/{chatid}` link. Currently a
    mod-only action, not wired to the user Report button.
  - Newsfeed `Report` (`newsfeed/newsfeed.go` ~970) queues
    `email_chitchat_report` → `ChitchatReportMail` → `chitchat_support_addr`.
- **The spam team:** `SpamAdmin` permission (granted via the `teams` table) gates
  the `/modtools/spammers` queue (`spammers/spammers.go`, `session.go` ~1195).

## Design

### Routing decision

The report routes by whether the two participants share a group:

- **≥1 common group** → existing User2Mod report to that community's mods.
- **0 common groups** → fallback: email the spam team.

The decision needs data the frontend does not currently have (the other user's
memberships), so a small backend lookup supplies it.

### Frontend — `ChatReportModal.vue`

- On open, call `GET /chat/{chatid}/commongroups`.
- **Common groups returned:** render "Which community is this about?" with
  `GroupSelect` **restricted to those groups** (pass them in; preselect if exactly
  one). Submit via the existing `openChatToMods` → `report` flow. (This also fixes
  the misrouting fault.)
- **No common groups:** hide the community selector. Show one reassuring line,
  e.g. *"We'll pass this to our central volunteers who deal with this kind of
  thing."* Submit via the fallback route (below). The explanatory note for the
  spam team is added **server-side** when the email is built (so it's
  authoritative and can't be edited away by the client), not typed by the user —
  e.g. *"No Freegle group in common — reported to the central spam team."*
- Submit guard fixed: require `reason` (+ comment when present), never silently
  no-op. The free-text **comment is optional in the fallback** (simple click);
  the **reason** selector stays (one click, useful triage signal for the team).

### Backend — Go `apiv2` (`iznik-server-go`)

1. **`GET /chat/{id}/commongroups`** → `[{ id, namedisplay }]`.
   - Caller must be a participant of chat `{id}` (user1 or user2); else 403.
   - Query the groups in common between the two participants (reuse the
     mutual-membership pattern, returning the group rows not just a count).
2. **Fallback report path.** A new action that, for a no-common-group report,
   queues a background task to email the spam team. Mirrors
   `handleReferToSupport`: insert into `background_tasks` with task type
   `email_chat_spam_report` carrying `chatid`, reporter `userid`, `reason`,
   `comment`. Validate the caller is a participant and that there is genuinely no
   common group (server re-checks; the client decision is not trusted).

   Shape: extend the existing `POST /chatrooms` action set (where
   `ReferToSupport` already lives) with a `ReportNoGroup` action carrying
   `reason` + `comment`, OR add a focused endpoint. Decision: **add a
   `ReportNoGroup` action to `POST /chatrooms`** for consistency with the
   sibling `ReferToSupport` action.

### Backend — PHP `apiv1` (`iznik-server`)

Parity for both the `commongroups` read and the fallback action, matching the
existing `chatmessages.php` / `ChatRoom.php` `referToSupport` structure, so the
two APIs stay equivalent.

### Batch — `iznik-batch`

- New `BackgroundTask` constant `TASK_EMAIL_CHAT_SPAM_REPORT =
  'email_chat_spam_report'` (`app/Models/BackgroundTask.php`).
- Handler in `ProcessBackgroundTasksCommand` (modelled on `handleReferToSupport`):
  load chat + reporter + other user, build the mail, spool to the spam team.
- New Mailable `App\Mail\Chat\ChatSpamReportMail` (modelled on
  `ReferToSupportMail`): subject identifies reporter, other user, chat id and that
  there is no common group; body carries the reason, the optional comment, the
  auto-note, and a `{mod_site}/modtools/...` link to the chat so the team can view
  it, ban, or add the user to the spammers queue.

### Config

- Add `spam_addr` to `iznik-batch/config/freegle.php`:
  `'spam_addr' => env('FREEGLE_SPAM_ADDR', env('FREEGLE_SUPPORT_ADDR', 'support@ilovefreegle.org'))`.
  Defaults to the support address so production can point it at the real spam-team
  inbox via the `FREEGLE_SPAM_ADDR` env var without a code change. Document it
  alongside the other `_addr` entries.

## Data flow (no-common-group case)

1. User clicks **Report** on a User2User chat (ChatHeader).
2. Modal opens → `GET /chat/{id}/commongroups` → `[]`.
3. Modal hides the selector; user picks a reason, optionally types a comment,
   clicks Send.
4. `POST /chatrooms` `{ id, action: 'ReportNoGroup', reason, comment }`.
5. Go re-verifies participant + no-common-group, inserts
   `background_tasks(email_chat_spam_report, {chatid, userid, reason, comment})`.
6. Batch task runs → `ChatSpamReportMail` → spam team inbox, with chat link.

## Error handling

- `commongroups` for a non-participant → 403; for an unknown chat → 404.
- `ReportNoGroup` when a common group actually exists → reject (the client should
  have used the normal flow); the server re-checks rather than trusting the client.
- Missing reporter/chat in the batch handler → log a warning and skip (parity with
  `handleReferToSupport`).
- Empty `commongroups` response must not break the modal — it simply renders the
  fallback variant.

## Testing (TDD)

- **Go** (`iznik-server-go/test`): `commongroups` returns shared groups when they
  exist and `[]` when none; rejects non-participants. `ReportNoGroup` enqueues the
  background task for a genuinely group-less chat and rejects when a common group
  exists or the caller isn't a participant.
- **Batch** (`iznik-batch/tests`): the `email_chat_spam_report` handler builds
  `ChatSpamReportMail` and spools it to `spam_addr`; subject/body contain the
  reason, note, and chat link; missing-data cases are handled.
- **Vitest** (`iznik-nuxt3`): the modal shows the community selector when
  `commongroups` is non-empty and hides it (and submits via the fallback) when
  empty; the submit button is never a silent no-op.

## Decisions made

- **Fallback only.** Community selector stays whenever a group is in common; the
  spam-team route is purely the no-common-group fallback. (Per product owner.)
- **Reporter has no destination choice.** No spam-team option is ever shown; the
  server decides. (Per product owner: "they just need a simple click, don't care
  about destination".)
- **Reason kept, comment optional in fallback.** One-click reason is a cheap,
  useful triage signal; free text is optional to keep it simple.
- **Restrict the dropdown to genuinely common groups** (not all the reporter's
  groups), fixing the latent misrouting fault while we're here.
- **Email delivery to the spam team**, reusing the `ReferToSupport` pattern rather
  than inventing new infrastructure; destination is a config address.
