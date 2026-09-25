# A self-moderating Freegle: thought experiment

**Status: experimental thought experiment. Nothing here is agreed or planned.** The point of
the branch is to find every place where the group model or a human in the loop adds friction
for members, and to test how much of it a working prototype can remove.

## The one rule

**Humans should be able to add value. The system must work without them.**

Today about 25 committed volunteers do roughly half the moderation work. Every flow below is
judged on two questions:

1. What happens if nobody is there? The flow has to reach an outcome on its own and tell the
   member what that outcome was.
2. What can a person add on top? A welcome, a better answer, an override. Never a gate.

A flow that waits for a person is a flow that fails when the person is not there.

## The target model in one page

| Today | Thought experiment |
|---|---|
| ~500 communities, each with a name, description, tagline, header, rules, settings and a mod team | No communities. One site, one set of rules, one location per member, posts ripple out by travel time |
| Posting picks a group from a postcode | Posting picks a location. Nothing else |
| "On Cambridge Freegle" on every card | "Near Cambridge" (place name from the location) |
| Per-group settings change what members see | One site-wide configuration |
| Member reports go to a mod queue | Reports feed a quorum. At quorum the post is withdrawn and the poster and reporters are told. A person can overturn |
| Chat held for review until a mod looks | Chat delivered behind a "this may contain X, tap to view" warning. A person can still reject it later |
| Pending queue, auto-approve after 20 minutes if clean | Everything publishes on the content check. The 20-minute window becomes a "recently published" queue that people may check if they like |
| Micro-volunteering is opt-in, results feed a quorum | Micro-volunteering is how frequent repliers prove they are people. Wrong answers on graded tasks cost you the reply |
| Mod chat, welcome mails, standard messages per group | System notices with fixed wording. People can add a welcome or a congratulation on top |
| TrashNothing posts to a named group via mail | TrashNothing still posts to a named group. Groups survive as invisible routing labels only TrashNothing sees |

## Groups survive as a hidden routing label, for TrashNothing only

The TrashNothing contract is mail to `<nameshort>-subscribe@` and `<nameshort>@` addresses,
and a per-group post feed. None of that needs a group to be visible to a member. The
`groups` table keeps `id`, `nameshort`, the polygon and the TN flags. Everything a member
could see (namedisplay, tagline, description, profile, cover, welcomemail, rules, settings)
is either dropped or ignored by the member-facing site. The member-facing site never shows a
group name, never lists groups, and never asks which group anything is about.


## Inventory: where the group model reaches a member

Counted by hand across the member-facing site (`iznik-nuxt3`, not `modtools/`):

| Kind | Touch points |
|---|---|
| Display of group identity (name, logo, tagline, description, founded, sponsors) | 66 |
| Per-group configuration that changes what a member sees or can do | 26 |
| Membership mechanics (join, leave, role, ban) | 22 |
| Routing and data fetching keyed by a group id | 44 |
| "Contact the volunteers", reports, mod badges, SEO pages per group | 26 |
| **Total** | **184** |

The store `stores/group.js` is imported in 40+ files (107 references). `GroupHeader.vue` alone
carries logo, name, tagline, member count, founding date, free-HTML description, sponsors,
join and leave, and "Questions? Contact our volunteers".

### The ones that cost members something today

- **Every post card fetches its group** to decide whether to print OFFER or the group's own
  keyword (`MessageTag.vue`, `useMessageDisplay.js`). One setting, thousands of fetches.
- **A closed group blocks posting** with "no communities near there, get in touch" copy in six
  places. The member has a location; that should be enough.
- **The email frequency settings page lists every community** a member was ever joined to,
  including ones rippling joined them to. Members ask why they are in nineteen communities.
- **Reporting asks "which community is this about?"** (`ChatReportModal.vue`,
  `MessageReportModal.vue`). The member does not know and should not need to.
- **A member of no communities sees an empty "my communities" feed**
  (`message/membership.go` returns FALSE). The nearby feed already works without groups.
- **Per-group rules are never shown to members.** The only rule the site reads is
  `restrictpersonalinfo`. The rest lives in free HTML descriptions and in moderators' heads.
- **"Message from Cambridge Freegle Volunteers"** in chat, and a mod badge on profiles, tell
  members there are people to appeal to. When those people are not there, the appeal goes
  nowhere.

### Backend: what the group is actually for

- **Browse is already group-free.** The nearby feed (`isochrone/message.go`) joins
  `messages_spatial`, `messages` and `rippling_reach` and never touches `memberships`. Only
  the "my communities" view and search still need a membership.
- **Mail is not.** `UnifiedDigestService::mailNewlyReachedForPost` joins `messages_groups` to
  `memberships`. A reach that covers a non-member mails nobody. That is why the ripple engine
  writes `messages_groups(rippled_in=1)` and `memberships(rippled=1)` rows: **the group is the
  join table between a reach polygon and an inbox.** Removing groups means mail needs a
  different join, which is a member's location and email frequency.
- **Chat to volunteers requires a group** (`chat_rooms.groupid` is mandatory for User2Mod).
- **Posting resolves a group from the location** and refuses if none contains it.
- **Per-group settings that change behaviour**: `moderated`, `closed`, `keywords.*`,
  `reposts.*`, `maxagetoshow`, `spammers.worrywords`, `widerchatreview`, `rippling.in/out`,
  `communityevents`, `volunteering`, `newsfeed`, `newsletter`, plus 33 rule keys of which
  the backend reads one.
- ~45 tables carry a `groupid`. Most are logs and counters. The load-bearing ones are
  `memberships`, `messages_groups`, `chat_rooms`, `rippling_proximity`.

## Inventory: where a person is in the loop

| Flow | What the member does | Where it waits | What happens with nobody there |
|---|---|---|---|
| Report a post | Report button, picks a reason | Group's User2Mod chat; at two reports the post goes to Pending | Post sits in Pending until the 48-hour auto-approve. Reporter never hears back |
| Report a chat or member | Report button, picks a community | Group's User2Mod chat, or an email to support | Nothing. Reporter is told "our volunteers will get back to you" |
| Report a ChitChat post | Report button | Post hidden, email to support | Post stays hidden forever |
| Rate a member down with a reason | Thumbs down | Feedback queue | Nothing |
| Send a chat message with a link, money words, a concern word | Send | Held for review, recipient sees nothing, no email, no push | Auto-rejected after 7 days. Sender never told |
| Post something the content check flags | Post | Pending queue | Auto-approved after 48 hours (20 minutes on the auto-approve branch) |
| Join many communities, change location often, get a flagged note | Nothing | Member review queue | Stays flagged |
| Ask a question | "Contact the volunteers" | Group's User2Mod chat | Chased at 6.5 days. No answer |
| Micro-volunteering verdicts | Answer a task | Feed a quorum, advisory to mods | Two rejections pull a post to Pending, where it waits |

Existing automation worth keeping: the content check, the auto-approve services, the
7-day chat auto-reject, the >5-rejects chat spam rule, the micro-volunteering quorum and
scoring, the trust-level promotion, the reach engine.

There is no AI in any moderation path. A fine-tuned 3B model experiment scored 63.5% on
approve/reject against a 63.0% baseline and was dropped. The design below does not rely on a
classifier being good; it relies on the outcome being reversible and the member being told.

## TrashNothing contract (must keep working)

| Contract point | Where | Needs a visible group? |
|---|---|---|
| Inbound posts and subscribe/unsubscribe mail addressed by `nameshort` | `IncomingMailService` | No. `nameshort` stays as a routing label |
| `PUT /memberships?partner=&groupid=` | `membership.go` | No. Group id stays as a routing label |
| TN mod settings link keyed by `nameshort` | `group.go` | No |
| `messages.tnpostid` fan-out over `messages_groups` | `message.go`, `ExpandService` | No. Rows stay, members never see them |
| `GET /api/changes?partner=` | `changes.go` | No. Already group-free |
| `{username}-g{groupid}@user.trashnothing.com` | `TNSyncCommand` | No. Parsed, never shown |
| `groups.ontn`, `groups.onlovejunk` | `group.go`, `location.go` | No |
| Message payloads TN reads (`GET /api/changes?partner=`, `GET /api/messages?partner=`, single fetch with `partner=`) carry `groups: [{groupid, nameshort}]` | `partner` package | No. Derived at response time from `partner_areas` (smallest containing area, else nearest within 20 miles, else empty). Only when a partner key is present |

Nothing TN relies on needs a member to see a group. The internal tables survive; the member
site stops mentioning them.

## Prototypes on this branch

Four vertical slices, each built test-first. In phase 1 each sat behind a switch that was off
unless set; phase 2 (below) made three of them the behaviour and left only the reply gate's
number as a switch. Each one is judged by the same question: what happens with nobody there?

| Switch | Where | What it changes |
|---|---|---|
| `CHAT_WARN_NOT_HOLD=1` | Go API and batch (same variable) | A held chat message is delivered behind a warning |
| `REPLY_GATE_AFTER=N` | Go API | The (N+1)th reply in a day needs a correct graded task first |
| `GROUPLESS=1` | Member site build | No community identity anywhere a member looks |
| `REPORTS_RESOLVE=1` | Go API | Two reports take a post down and tell everyone |

### A. Chat: warn, do not hold

Today `ChatProcessService` sets `reviewrequired=1` on a message the content check flags, and
the recipient sees nothing: not in the room, not in the chat list, no email, no push. With
nobody there the message is auto-rejected after seven days and the sender is never told.

With the switch on:

- `chat.FetchChatMessages` returns the message to the recipient with `sensitive` set to a
  short reason (`money`, `link`, `contact`, `language`, `concern`, `scam`, `checked`). The
  reason comes from the stored `reportreason` via `chat.SensitiveReason`. The sender and
  moderators see what they see today.
- The chat list counts it as unread and shows "New message - tap to view" as the preview,
  never the text. Five list queries share one predicate, `chat.deliverableSQL`.
- `ChatMessageSensitive.vue` shows the warning; `ChatMessage.vue` renders it first and the
  message only after the member taps "Show message".
- Batch: the push goes out with the warning as its body, the room surfaces in the list, and
  the email carries the warning in place of the text (`App\Support\ChatWarnNotHold`).
- A moderator can still reject the message later, which hides it again. Nothing waits for
  them.

Files: `iznik-server-go/chat/warnnothold.go`, `chatmessage.go`, `chatroom.go`;
`iznik-batch/app/Support/ChatWarnNotHold.php`, `ChatProcessService.php`,
`PushNotificationService.php`, `ChatNotificationService.php`, `Mail/Chat/ChatNotification.php`;
`iznik-nuxt3/components/ChatMessageSensitive.vue`, `ChatMessage.vue`.
Tests: `test/chat_warn_not_hold_test.go` (5), `tests/Unit/Support/ChatWarnNotHoldTest.php` and
three service tests (7), `ChatMessageSensitive.spec.js` and `ChatMessage.spec.js` (7).

### B. Micro-volunteering as the reply gate

Today a member can reply to any number of posts. The people who reply to everything are the
ones most likely to be hoarding or reselling, and the only defence is a moderator noticing.
Micro-volunteering is opt-in and a wrong answer costs nothing.

With `REPLY_GATE_AFTER=N`:

- `chat.CreateChatMessage` refuses the (N+1)th Interested reply of the day with HTTP 428
  `reply_gate`, before anything is written, unless the member has a recent graded pass.
- A graded task is a CheckMessage task on a post other members have already settled (two
  Approve or two Reject verdicts, `microvolunteering.SettledVerdict`). `GET
  /microvolunteering?graded=1` serves one; `POST /microvolunteering` answers with
  `graded: true|false`. A wrong answer does not open the gate. Three wrong answers and the
  member is told to try again tomorrow.
- The reply flow (`useReplyStateMachine`) has a `REPLY_GATE` state that shows
  `MicroVolunteering` in gate mode and sends the kept reply again on a pass. The chat
  footer does the same for a reply typed in an open chat.
- The answer key is the quorum that already exists. Nobody has to grade anything.

Files: `iznik-server-go/chat/replygate.go`, `microvolunteering/graded.go`,
`microvolunteering.go`, `chat/chatmessage.go`; `iznik-nuxt3/components/MicroVolunteering.vue`,
`MicroVolunteeringCheckMessage.vue`, `ChatFooter.vue`, `ChatReplyPane.vue`,
`composables/useReplyStateMachine.js`, `stores/microvolunteering.js`.
Tests: `test/reply_gate_test.go` (4), and ten client tests across the five specs.

### C. Groupless member site

`GROUPLESS=1` at build time sets `runtimeConfig.public.GROUPLESS`; `useGroupless()` reads it.
Where it is on:

- The expanded post drops "On: Cambridge Freegle, ...". The location in the title row was
  already there.
- The posting history says "2 days ago", not "2 days ago on Cambridge Freegle".
- A message from moderators is "Message from Freegle", with no community logo.
- Reporting a post promises an outcome ("we'll let you know what happens") and never asks
  which communities to notify. Reporting a chat never asks "Which community is this about?".
- Email settings have one level and no per-community list.
- The terms link to one set of rules at `/rules` (`SiteRules.vue`), the union of the 33
  per-community rule switches members were never shown.

Groups remain in the database as routing labels for TrashNothing and for the internal
tables. Not done on this branch, and listed for size: the explore pages (11 routes and the
sitemap), the community picker on the browse map, the group header, the donation asks that
name a community, birthday pages, per-group events and volunteering, and the 44 data-fetching
sites keyed by group id in the inventory above.

Tests: nine client specs (`useGroupless`, `SiteRules`, and one arm in each touched component).

### D. Reports resolved by the system

Today a report is a message to a community's moderators carrying the post id. It is recorded
as a Reject verdict and at two verdicts the post goes back to Pending. With nobody there the
48-hour auto-approve puts it back up, and nobody tells the poster or the reporters anything.

With `REPORTS_RESOLVE=1`, at the quorum `microvolunteering.ResolveReports`:

- takes the post down (`messages.deleted`, every `messages_groups` row, the reach held) and
  writes a `Message/Deleted` log so a person can find and restore it;
- tells the poster, as Freegle, which post, why, where the rules are, and that they can fix it
  and post again;
- tells each reporter the outcome;
- does nothing twice: a third report after the takedown sends nothing.

The notices go through the existing volunteers chat, so the app, the email and the push
already know how to show them. Under `GROUPLESS` that chat is "Message from Freegle".

Files: `iznik-server-go/microvolunteering/resolve.go`, `microvolunteering.go`.
Tests: `test/report_resolve_test.go` (2).

## What an adversarial review found

An independent review of the branch, with the fixes made and the gaps kept:

| Finding | Done |
|---|---|
| A shadow-banned sender's messages (held with the generic `Spam` reason, or `Fully`, or the `Last` chain) were delivered behind the mildest warning | Fixed. Those holds are never delivered, in the API, the push and the email. A hold with no recorded reason stays a hold |
| A moderator opening a member's chat got the masked preview | Fixed. The list runs as the member; the moderator's copy is unmasked afterwards |
| Two reports landing at the same instant could run the takedown twice | Fixed. The delete is the guard: `WHERE deleted IS NULL`, and nothing happens on zero rows |
| A moderator's own report still went to Pending under the experiment | Fixed. It is final, like two members' |
| The send button spun for twenty seconds after a refused reply | Fixed. The spinner is released on the gate path |
| "Try again tomorrow" promised a lock that does not exist | Fixed. The wording says the reply is kept and a later try can pass |
| The batch container was not given the chat switch | Fixed in compose |
| Two accounts can take down any post | Kept, stated. The quorum is two, with no reporter standing, account age or rate limit |
| The takedown removes every community's copy, against the per-community delete convention | Kept, stated. In a world with no communities the post is one post |
| The reply gate covers web replies only | Kept, stated. Email and TrashNothing replies bypass it |
| The gate opens when there is nothing settled to ask about, or when an answer cannot be marked | Kept, stated. A graded task is only ever served from a settled post, so the second case is a race |
| `GROUPLESS` hides community identity in the client only; the API still returns it | Kept, stated |
| No test drives two reports concurrently | Kept, stated |

## Where humans add value (touch points kept, none load-bearing)

- Welcome a new member with a personal note on top of the system welcome.
- Congratulate a completed freegle.
- Improve a post (fix a title, add a category) after it is live.
- Overturn a system decision: reinstate a withdrawn post, reject a warned chat message.
- Answer a question the system could not answer.
- Check the "recently published" and "recently withdrawn" queues when they feel like it.

None of these is a queue anyone has to clear.

---

# Phase 2: removing the group model for real (2026-09-20)

Edward's direction after the prototypes: the schema was untouched and the stated gaps are
problems to fix. This phase removes every trace of the group model from the code and the
schema, on the same branch, and closes the gaps. TrashNothing keeps working through a
hidden partner-area table. The four switches from phase 1 stop being switches: with no
per-community moderation to fall back on, warn-not-hold and reports-resolve are simply how
the site works, the reply gate has a default, and there is nothing for a groupless switch to
hide.

## The one rule, again

Humans should be able to add value. The system must reach an outcome with nobody there.
"Humans" now means members with `users.systemrole` of Moderator, Support or Admin: a
national pool, at most a handful, with no community scoping anywhere.

## Schema contract

One Laravel migration, `2026_09_20_000001_remove_group_model.php`, with a matching
production `*_migration.sql`. Applied in this order.

### Added

| Table | Column | Replaces |
|---|---|---|
| `users` | `emailfrequency INT NOT NULL DEFAULT 24` (-1 immediate, 0 never, 24 daily) | `memberships.emailfrequency` |
| `users` | `eventsallowed TINYINT(1) DEFAULT 1`, `volunteeringallowed TINYINT(1) DEFAULT 1` | `memberships.*` |
| `users` | `postingstatus ENUM('MODERATED','DEFAULT','PROHIBITED','UNMODERATED') NULL` | `memberships.ourPostingStatus` |
| `users` | `banned TIMESTAMP NULL`, `bannedby BIGINT NULL` | `users_banned` (now site-wide) |
| `users` | `welcomed TIMESTAMP NULL` | `memberships_history.processingrequired` driving the welcome mail |
| `users` | `modconfigid BIGINT NULL` | `memberships.configid` |
| `messages` | `collection ENUM('Incoming','Pending','Approved','Spam','Rejected') NOT NULL DEFAULT 'Pending'` | `messages_groups.collection` |
| `messages` | `approvedby BIGINT NULL`, `approvedat TIMESTAMP NULL`, `rejectedat TIMESTAMP NULL` | `messages_groups.*` |
| `messages` | `autoreposts INT NOT NULL DEFAULT 0`, `lastautopostwarning TIMESTAMP NULL`, `lastchaseup TIMESTAMP NULL` | `messages_groups.*` |
| `messages` | `contentcheck_checked_at TIMESTAMP NULL`, `contentcheck_reasons JSON NULL` | `messages_groups.*` |
| `partner_areas` (new) | `id, nameshort, namefull, lat, lng, polyindex GEOGRAPHY` copied from `groups WHERE ontn = 1` | the only surviving use of a community: TrashNothing addresses mail and members by it |
| `community_news_areas` | `authorityid BIGINT NULL` | `anchorgroupid`, `groupids` |

`messages.heldby`, `spamtype`, `spamreason`, `arrival` already exist and take the per-post role.

### Data moved before anything is dropped

- `messages.collection/approvedby/approvedat/rejectedat/autoreposts/lastautopostwarning/lastchaseup/contentcheck_*` from the post's origin `messages_groups` row (`rippled_in = 0`, else the earliest row).
- `users.emailfrequency` = -1 if any membership was immediate, else 24 if any was daily, else 0. `eventsallowed`/`volunteeringallowed` = any membership had them on. `postingstatus` = PROHIBITED if any, else MODERATED if any, else NULL. `banned` = earliest `users_banned` row. `modconfigid` = any membership's `configid`. `welcomed` = `users.added` (everyone existing counts as welcomed).

### Dropped

Tables: `memberships`, `memberships_history`, `memberships_yahoo`, `memberships_yahoo_dump`, `messages_groups`, `groups`, `groups_digests`, `groups_facebook`, `groups_facebook_shares`, `groups_facebook_toshare`, `groups_images`, `groups_mods_welfare`, `groups_sponsorship`, `groups_twitter`, `users_banned`, `communityevents_groups`, `volunteering_groups`, `partnerships_groups`, `rippling_proximity`, `rippling_proximity_checked`, `mod_bulkops_run`, `plugin`.

Columns (`groupid` unless stated): `chat_rooms`, `messages_spatial`, `messages_drafts`, `messages_postings`, `messages_history`, `messages_index`, `messages_popular`, `newsfeed`, `logs`, `users_comments`, `users_modmails`, `users_dashboard`, `users_postnotifications_tracking`, `alerts`, `alerts_tracking`, `admins`, `polls`, `shortlinks`, `vouchers`, `locations_excluded`, `newsletters`, `changes`, `email_tracking`, `stats`, `stats_outcomes`, `stats_summaries`, `reengage.volunteer_groupid`, `rippling_reach.rejected_groups`, `rippling_reach.reachable_group_ids`, `community_news_areas.anchorgroupid` and `.groupids`, `concern_keywords.scope` and `.group_id`, `simulation_message_isochrones_messages.groupid`.

Per-community statistics (`stats`, `stats_outcomes`, `stats_summaries`, `users_dashboard`) are truncated: they were per community and the batch regenerates national figures.

## Behaviour contract

### Posting and moderation
- A post has one location and one moderation state, on `messages`. Nothing is copied per community.
- A new post is `Pending` until the content check runs (every minute). Clean and the poster not `MODERATED`/`PROHIBITED` means `Approved` at once; flagged means `Pending` with reasons; a block reason means `Spam`. `Pending` posts auto-approve after the existing delay unless a danger signal is present. The 48-hour fallback stays as the last resort.
- Two member reports, or one from a national moderator, take a post down and tell the poster and the reporters. No Pending queue for reports.
- Reposts, chase-ups and expiry use site-wide defaults from `config/freegle.php` (offer 3 days, wanted 7, max 5 reposts, 90 days shown).
- Rippling: the reach polygon is the only spread mechanism. No community rows, no auto-join, no opt-out.

### Members
- One email frequency, one events and one volunteering switch, on the member.
- A ban is site-wide (`users.banned`). A banned member cannot post, reply or chat.
- The reply gate is on by default: after `REPLY_GATE_AFTER` replies in a day (default 5, 0 disables), a graded micro-volunteering task must be answered correctly.
- A held chat message is delivered behind a warning. A hold about the sender (quiet ban) is never delivered.
- Welcome mail once, nationally, when a member is created.

### Chat
- `chat_rooms.groupid` is gone. A `User2Mod` room is a member's room with Freegle; the recipients are the national moderators. Two members can always chat.

### Rules
- One rule set, site-wide, at `/rules`, and the same rules are the judge's questions. Personal details are always kept out of posts.

### TrashNothing
- Inbound mail to `<nameshort>@groups...` resolves the short name through `partner_areas`; the post's location is the mail's postcode, else the area centre.
- `<nameshort>-subscribe@` sets the TN member's email frequency to daily and `-unsubscribe@` to never. `PUT /memberships?partner=...&groupid=...` keeps its path and shape, creates or finds the TN member, and sets their location from the area when they have none. `GET /api/changes` is unchanged. The `{username}-g{id}@user.trashnothing.com` parsing is unchanged.

### Moderators
- ModTools is national, and it is never a gate. Edward's direction on 2026-09-20: "a tool for
  national volunteers to add value where possible but not block activity if none active."
  The test for every screen and every backend state: if no volunteer opens ModTools for a
  month, nothing on the site waits, stalls or stays hidden. Nothing sits in a queue for a
  person; the states are live, taken down (with the poster told), and a short automatic wait.
  A moderator can overturn any of them. The full rework is in the ModTools rework brief and
  is summarised here:
  - Posts: clean and unrestricted means live at once; flagged means a short automatic wait,
    then live unless a danger signal is present, in which case it is taken down and the
    poster told. A content-check block is a takedown with the poster told, not a queue.
  - Events and volunteering are live on creation and content-checked like posts.
  - Automated member flags are advisory and stop nothing.
  - A member's message to Freegle gets an automatic reply at once; a volunteer may add one.
  - No mail chases a moderator about work.
  - ModTools' home page lists things a volunteer could do today (just published, taken down,
    held chat messages, messages to Freegle, new members, completed freegles, flagged
    members, events and volunteering, spammers), each describing what the system already
    did and offering one action on top. Pending, spam and edit-review queues, badge counts,
    hold and release, and "approve" as a verb are gone. Support tools stay.

### Rules as questions (AI judgement)
Edward's direction on 2026-09-20: "much bigger use of ai models to check/review. for example
rework rules from keyword approach to ai judgement, e.g. 'Does this post refer to animals (not
just accessories)?'" The brief is `.claude-agent-status/briefs/ai-judgement.md`; in short:
- The site rules are questions a model answers about a post, a chat message, an event, a
  volunteering opportunity, a newsfeed post or a report: money or loans, legality, medicines
  (prescription, over the counter, animal, contact lenses), tobacco and vapes and adult material,
  live animals, weapons and things unsafe by design, money and vouchers, not an item, scam,
  decency, unsafe, vague. Alcohol and event tickets are allowed. Each question is the majority
  position of the 496 live communities' own rules (`.claude-agent-status/briefs/frozen-settings.md`);
  every other per-community setting is likewise frozen at its majority value, as a constant, with
  the other branch deleted.
  Each answer carries a confidence and a one-line reason a member can read.
- Every word list goes: concern keywords, worry words, vague words, greeting spam, the
  not-an-item heuristics. Exact checks stay as code: phone numbers, emails, addresses, link
  domains, Spamhaus, language, subject repeat, known spammers, bulk mail, image spam.
- A takedown question answered yes with confidence at or above the threshold takes the post
  down and tells the poster why in the judge's words. Below the threshold, or a wait question,
  is the short automatic wait. The judge being down never holds or removes anything.
- One `TakedownService` in the batch does every takedown and restore and tells the poster; the
  content check, the judge, report resolution and ModTools all use it.
- Reports resolve in the batch: quorum two, one if the judge agrees with the reporter, three if
  it disagrees. Go only records the report.
- The judge runs on `JUDGEMENT_MODEL` (default `claude-opus-5`); chat on `JUDGEMENT_CHAT_MODEL`.
  Prompt caching on the questions. A `judgement:eval` command measures accuracy on a fixture
  set; it is not a test, so the suite never needs a key.

### How much simpler, and where the fixes went
`plans/active/2026-09-25-simplicity-and-maintainability.md` holds the measurements and the scripts that produce them.

### What used to be human
`docs/developers/reference/retired-human-loops.md` lists every task a person used to do, what
replaced it, how, the risk and the mitigation. It is written from the code and updated with it.

## Ownership and waves

| Wave | Owner | Scope |
|---|---|---|
| 0 | this session | migration, production SQL, fixture regeneration, `run-suite.sh` lock |
| 1 | Go agent | `iznik-server-go` |
| 1 | Laravel agent | `iznik-batch` |
| 1 | Member-site agent | `iznik-nuxt3` except `modtools/` |
| 1 | ModTools agent | `iznik-nuxt3/modtools/` (reads, does not edit, the member-site agent's files) |
| 2 | this session | integration, full suites, docs, screenshots, PR |

The four run against the same worktree and the same database. The Go and Laravel suites
share `scripts/../run-suite.sh`, which takes a lock, because starting both at once kills one
of them during setup.
