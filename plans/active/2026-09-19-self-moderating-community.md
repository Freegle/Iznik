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

Nothing TN relies on needs a member to see a group. The internal tables survive; the member
site stops mentioning them.

## Prototypes on this branch

Four vertical slices, each built test-first and each behind a switch that is off unless set,
so the branch runs as today until you turn one on. Each one is judged by the same question:
what happens with nobody there?

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
