# Decisions made while merging master into the self-moderating experiment

Each line is a choice made where the plan did not say. The brief: no communities, one site-wide rule set, humans out of every loop, about ten national volunteers, micro-volunteering as the reply gate, TrashNothing through partner_areas.

## Go API

- Logs no longer carry a community: `logs.groupid` is gone from the log entry type.
- Anyone with a moderator, support or admin system role is a moderator, everywhere. There is no membership check.
- Merging two users skips memberships (there are none). The survivor keeps its own site-wide settings. If only the loser was banned, the survivor inherits the ban.
- A ban is one site-wide column on the user. The ban list for a user returns at most one row, with no community name.
- A banned member cannot reply to any post.
- Leaving or deleting an account writes no per-community "Left" log.
- Unsubscribe switches (digest, events, volunteering) update the user, not memberships.
- Moderator configs: any moderator can see any config. "In use" means a user has it as their `modconfigid`.
- Any moderator may see any member story, newsfeed moderation action, or post action.
- Search has no community filter and no "hide rippled-in" option. A post is one row.
- "My posts" reads the post's own collection and arrival; there are no per-community copies.
- Reply attribution: nobody is an established member of an origin group, so the home, ripple-group and ripple-join evidence bits are always 0 and in-origin-catchment is always 0. The reach evidence decides.
- Rippling analytics treat every post with a reach row as rippled, and every reply as rippled (no home-member exemption).
- The dashboard and public stats are national only. New members are users added in the range. Moderators active come from the last post each moderator approved.
- Partnerships keep the deal and money records only. Linking a deal to communities, and the sponsorship rows that came with it, are removed.
- The retired micro-volunteering search-term challenge is removed, as on master.
- The raw reply-attribution "moved into an area" membership clearing is removed with the memberships.
- Nuxt unit tests: each master spec missing from the branch was re-run against the branch code; tests that still pass and test something real were restored (as *.restored.spec.js next to the original where the original was rewritten), tests that only passed because the code now ignores group data were not.
- Nuxt unit tests: message-coverage and member-full store specs restored minus the moderator actions the branch removed (approve, reject, move, spam, hold, ban); the micro-volunteering page spec restored minus "choose a community"; the standard-message button spec restored as a useStdMsgs spec, since the button is gone but the helpers remain.
- Nuxt unit tests: held-by-another-mod, explore, group, birthday, partnership, rippling-map and per-group settings specs stay deleted: the code they test is gone.
- vitest: 132 ported, about 3,000 deleted (112 whole files and tests inside kept files, counted by test title against master).
- Only the Go review queue and "open a member's chat" changed for non-moderators: the review queue returns empty, and naming someone else's userid on a User2Mod chat is ignored (they get their own chat).
- Every post fetch now selects `collection` and the moderation columns; without them no post was visible to anyone.
- Partnership logos are set by URL only; the upload-by-image-id path went with the group images table. The `group` flag on legacy image URLs is removed.
- Moderator actions Approve, Reject, Delete, Spam, Hold, Release, ApproveEdits, RevertEdits, Move, BackToPending and Report on `POST /message` are gone; their tests are deleted.
- Micro-volunteering has no eligibility gate by community: any signed-in member other than the poster may answer a check, and moderators see every micro-action.
- Go test fixtures that counted "everything a moderator can see" assert on the change they cause, because moderators are one national pool and the test database is shared.
- Routing service swagger spec and doc comments drop the community routes (group-proximity, group-extent, group-actives, reachable-groups, reach-union, groups/nearby, groups/list).
- Playwright: specs for pages the branch removed are deleted (explore, contact volunteers from the group header, ModTools pending/approved/edits/move/hold/settings/spammers/member review, repost with a group); the explore and signed-out browse variants of the reply flows go, because browse needs a signed-in member with a location and a visitor reaches a post from its message page.
- Playwright: Browse tests give the member a postcode (LS1 4AP, where the seeded posts are) instead of joining a community; member-log tests open the member by id instead of choosing a community; the email-level test drops the per-community "advanced settings"; the homepage test drops the place search that led to explore.
- ModTools dashboard: the national listing returns message rows, and the message store now takes the id from each row (it used to fetch /message/[object Object]).

## Laravel batch

- Bulk posting has no `--group`, cross-post or skip-primary options. A bulk post goes in once, at its postcode, and the automatic content check holds it.
- A moderator's blind-copy address comes from their own config, then any config they created, then the site default config.
- There is no "rippled-in copy" any more, so the `notifyposter` flag on a moderator action is gone: the poster is always told.
- Freebie Alerts gets the post's own arrival time (it used to read the arrival from the community copy).
- The reach recompute reports shrunk posts only; the "crossposts across groups" figure went with the groups.
- The routing server's `reachable_group_ids` is no longer read or tested.
- "Joined" for the reach backstop means the account was created in the last day.
- Match mail and digest mail carry no `[Community]` subject prefix.
- The getting-started tips are signed by the Freegle team, not a local volunteer.
- Counting tests (moderator badge, micro-volunteering, digests, chat to moderators) set aside the fixture's posts and moderators first, because everything is now sitewide and the test database is shared.
- Batch tests deleted for behaviour that no longer exists: per-community membership, opt-out, welcome, closed-group and customisation reminders, the concern-keyword and Safeguarding lists, the TrashNothing posts API ingestion and parity checks, partnership community sync and sponsorship import, the reply-attribution backfill, community news per community, and per-community lastaccess and approximate location.

Batch test tally, against master's batch tests (5,116):
- batch: 910 ported (kept, changed to the group-free model; 31 of them restored in this pass), 3,993 kept unchanged.
- batch: 1,213 deleted, every one for behaviour listed above as gone.
- go tests: dropped TestRecordRippleEvent and the HealPointIsochrones tests (functions removed with group ripple/isochrone healing in the groupless model)

## 2026-10-18: second merge of master (723 commits behind)

- 105 files the experiment had deleted and master changed stay deleted: the community model is gone.
- 5 files master deleted and the experiment changed take master's deletion: the legacy image migration and the "similar posts" feature were retired on master.
- 166 files changed on both sides, 395 hunks. Master's lockdown work, the Expression-library query builders, the promise count fix, the account merge rewrite, the admin MJML and paging features, the chat image claim and the Playwright coverage flush are all taken. Master's changes to groups, memberships, per-community settings, word lists, several-communities moderation, the TN posts monitor and proximity notes are dropped.
- `PUT /message/submit` is kept and rewritten without a group: banned and prohibited members are refused from the site-wide columns, the collection comes from the site-wide posting status, and the lockdown posts hold forces Pending.
- Tests for group-only features are deleted; tests master added for kept features are adapted to the group-free helpers in `test/testUtils.go`.
- `swagger/swagger.json` is master's copy and needs regenerating inside apiv2. `browsecount/cache.go` still carries a `Group` filter field that is always zero.
- Raw SQL the experiment kept carries `keep-raw` comments for the check-raw-sql gate; where the conversion to the Expression library was mechanical it was done.
- Tests master added that build communities (createTestGroup, memberships, messages_groups) are deleted when the class is about a removed concept (ListModsService, resumed reach, engaged memberships) and adapted to posts on `messages` with the member's own posting status otherwise. `RippleEngagementService` and `ripple:clear-engaged` are gone with their test. `LockdownHoldsService` and `LockdownFilterSpoolService` read `messages`, not `messages_groups`.
- Vitest: the reply flow after a forced login goes straight to COMPLETED (no join step); per-group moderation filter tests and the moderator guidance test are gone; fetchCount is (maxDistance, log, browseType).

## 2026-11-11: third merge of master (PR #2026 role constants and the 19 October to 10 November work)

- 150 files the experiment had deleted and master changed stay deleted. `iznik-server-go/test/noticeboard_test.go` takes master's deletion (noticeboards were retired on master).
- Role strings: every branch role string is a constant (`utils.SYSTEMROLE_*`, `User::SYSTEMROLE_*`, `~/constants`). The branch has no Membership model, so `Membership::ROLE_*` and `User::roleMax` are not brought in.
- Conflicts where master only converted a role string to a constant keep the branch's version.
- Dropped from master, because they only mean something with communities: membership `implicit` and `added_origin` (columns, migrations, the Go `addedorigin` package, the backfill commands and all their tests), TrashNothing per-community contact rules (`modmessaging` gates, `modsMayContact`, closed TrashNothing chats by email), TrashNothing post ingestion repair commands (`tn:backfill-post-senders`, `tn:rewrite-subjects`, `tn:reconcile-memberships`, `tn:split-shared-account`, `TnUserProvisioner`, `ClosedPostOutcome`, `DeliberateSkips`), the `tn:sync` posts and memberships monitoring checks, the TrashNothing verify-coverage and reconcile-memberships schedules, the ADMIN mail "not chosen by member" filter, the worry-word and concern-keyword changes, the story review leaving out rippled and TrashNothing-placed members, spam-member held/reviewed badge split, per-community volunteering badge.
- Dropped from master, community frontend: the member order switch (`ModMemberOrderSelect`), the "Hide held posts" switch, the Spam concern-keywords tab in Support Tools, the community anniversary appeal on Browse, the join reason on signup and reply, the Trash Nothing "can't contact from this community" notice, the multi-community report selector, the auto-split banner in `ModMessage`.
- Required-reply alerts (quoted email, attachments, reply, snooze, cancel) are dropped across Go, batch and the frontend: they were built on one community's volunteers. The migration that added their columns is deleted too.
- Kept and adapted to `messages` and the national moderator pool: auto-split, familiar posts, message items, support cases (referral Reply-To is the no-reply address, there is no community volunteers address), UX experiments and surveys, interested chase-up, daily digest lag (members with `emailfrequency > 0`), support first pass (no community in the request), Edit and Message standard messages, promise notices, the donation and Gift Aid asks, the unified reply-question hold, Popular Posts (national).
- Migrations: the two membership column migrations and `make_ontn_mandatory` (alters `groups`) are deleted; the dead-tables migration no longer lists tables the community-removal migration already drops.
- `swagger/swagger.json` was regenerated with go-swagger so it matches the routes: noticeboard gone, master's new routes in.
- Restored in the group-free form: the embedding `Store.Refresh` tests and the "active moderators, one row each, most recent first" dashboard test (the code they cover still exists).
- Tests dropped with their features: Group parts of the logs test, concern keyword tests, two Edit-and-Message cases that needed a community, the unrelated-moderator support referral test, the membership-based dashboard and chat-name tests.
- Docs: community-model sections master edited stay as the branch left them; pages for master's new features were rewritten to the branch's behaviour. `.claude/rules/conventions.md` loses the two TrashNothing membership sections (a member is dealt with through their membership; a post goes on the community the member chose).
- Left open, already broken before this merge: the Go `GroupsForAuthority` query reads the dropped `groups` table, the housekeeper cron list still names group commands, `birthday:send-emails` and `chats:chaseup-mods` are scheduled but their services are gone, the ModTools Support Community tab uses deleted components, and `scripts/maintenance/lib` health checks hit `/api/group`.
- Open questions for Edward: (1) should required-reply alerts exist for the national moderator pool; (2) should a site-wide ban withdraw the member's open posts; (3) should an admin be stopped from deleting a moderator (master stops it for community moderators); (4) `tn:sync` still runs every ten seconds as master set it, the branch's old once a minute may be enough without post ingestion.
- Also dropped with the community model: the "When Support needs you to reply" page section (required-reply alerts), master's "Hide held posts" switch.
- Browse page root is one stable `div` around the member-only content: a root that flipped between a comment and a fragment threw insertBefore errors in the bulk-offer Playwright flow when sign-in landed during the redirect to Browse.

### Edward's answers, 2026-11-11 (the questions above)

- Required-reply alerts: dropped, as done.
- A site-wide ban withdraws the member's open posts, as master's community ban does: an approved post gets a Withdrawn outcome ("Marked as withdrawn by ban"), a pending post is deleted, posts that already have an outcome stay, nobody who replied is told. Built on the site-wide ban in `member.BanMember` (`message.WithdrawPostsOnBan`), test in `test/ban_withdraw_test.go`.
- An admin cannot delete an account whose system role is Moderator, Support or Admin (master's guard on the branch's roles). `LimboUser` already refused it through `auth.IsModerator`; the test `TestLimboUserCannotDeleteSystemRoleHolders` now pins all three roles.
- `tn:sync` stays at every ten seconds as merged.
- The two TrashNothing membership sections stay removed from `.claude/rules/conventions.md`; "TrashNothing accounts are never moderators, and are never merged" stays.
- The support referral mail's Reply-To is the no-reply address on this branch (there is no community volunteers address).

### Things that failed when run, repaired or removed with their feature

- Go `GroupsForAuthority` and `SignificantGroupsForAuthority` read the dropped `groups` table and swallowed the error: removed; `GET /authority/:id` returns an empty `groups` list.
- Housekeeper cron list: the 17 entries for batch commands that no longer exist are removed (birthday emails, chase up mods, groups:*, memberships:process, mod notifications, newsfeed mod notifications, admin chase and copy, locations:fix-skewed, ripple:proximity-notes).
- Batch: `birthday:send-emails`, `chats:chaseup-mods` and `mail:mod-notifs` called services the branch deleted (groups' birthdays, per-community chase-up, per-community mod work digests): commands and schedules removed.
- Batch: `concierge:run` named `LlmExtractor`, `RuleBasedExtractor` and `TemplateDrafter`, which lived inside other classes' files and so could not autoload: each class is now in its own file.
- Maintenance and deploy scripts and runbooks checked the API with `GET /api/group`, a route the branch lacks: they use `GET /api/authority?search=a`.
- ModTools Support Tools: the Community tab (find, list, contact, add community) used deleted components: replaced by a Volunteers tab holding Check Volunteers; `?tab=community` is now `?tab=volunteers`.
- ModTools members list: rows from `GET /modtools/members` carry the user's id as `id`, and the member card reads `userid`: the store now carries both.

### Master changes deliberately not carried (checked with the dropped-changes script on the merge)

- Spam fuzzy worry-word match fix (one-letter substitution is not a typo): the branch has no worry-word or concern-keyword matching at all (the judgement service replaced the word lists), so there is no `matchesFuzzy` or `matchesFuzzyToken` to fix and nothing to carry. The master tests for it (`isSingleSubstitution`) stay out with the code.
- Browse donation opt-out in the birthday modal: the birthday appeal is a community anniversary appeal and is gone, so the check has nothing to guard. `askForDonations` in `useMe` and every other ask keep it.
- Required-reply alerts and alert attachments (alert Go, AlertService, AlertMail, EmailSpoolerService attachments, the alert template, AlertAPI, the alert store, ModAlertHistory and their tests), as Edward decided.
- Rippling between communities: duplicate-repost check in ExpandService, `implicit = 0` in rippling attribution, analytics and metrics, ReengageContentService and story queries, and the Membership role constants.
- Membership origin and implicit: `added_origin` on message submit, TrashNothing placement flags.
- Approved-members sort order (`useModMembers`, member store, approved page spec), the multi-community report modal (`MessageReportModal`), and the "Hide held posts" switch (`useModMessages`).
- Carried across, not dropped: the auto-split banner now shows in the branch's `ModMessage`.

## 2026-11-11: adversarial review fixes, and what stays open

What the code does now, where the review found it did not:

- Warn-not-hold is off unless `CHAT_WARN_NOT_HOLD` is set, in the Go API as in the batch. With it on, only a hold for something in the text is delivered behind a warning (money, link, URL on a blocked list, email, language, worry word, known spammer or keyword, greetings spam, abuse). Every other reason stays hidden: shadow ban, blocked source, reused image, a chain from a hidden message. A message that follows one delivered with a warning is not held with it. A warned email carries no image, address map or item link.
- Only Admin and Support can merge a Support or Admin account. A moderator can still merge two ordinary accounts. A merge keeps the stricter posting status.
- A ban needs an existing target who is not the caller. Only an Admin can ban a Moderator, Support or Admin account. The ban and the withdrawal of the member's posts are one transaction. Unban clears the ban and restores nothing: an approved post stays Withdrawn and a pending post stays deleted. Reopening them needs a record of which posts the ban took down, and that is a product decision, not built.
- A graded answer on a settled post is final. The reply gate does not apply to a member the graded endpoint has nothing for (no settled post nearby, no known location, a declined trust level, a lockdown), so it cannot lock anyone out. The graded task skips posts that already have an outcome.
- Reports count from after the post's last take-down, from accounts that are not banned, deleted or linked to the poster. A restore therefore sticks. There is no judge on the report path and no minimum account age.
- Auto-approve skips banned and PROHIBITED posters. MODERATED posters are what the fallback is for.
- Inactive account removal only forgets accounts that never posted or chatted and are not banned. Spam cleanup does not ban a moderator, support or admin account.
- A moderator can read a taken-down post by id (`deleted` is returned to them), so the Taken down section and Restore work.
- `GET /modtools/members` has a `related` filter. Member search reads the address from `users_emails`.
- Digest, events and volunteering mail are off for a member with no membership when the group model is removed, and any one community that turned events or volunteering off turns them off.

Open questions for Edward:

1. Should a plain moderator be able to read any two members' private chat? The branch does, because moderators are one national pool (`canSeeChatRoom`). Master limited it to Admin and Support, or a moderator of a community both were in. The choices are Support and Admin only, or only chats in the review queue or tied to a report or support case.
2. Should unban reopen the posts the ban took down?
3. Should the reply gate also count the first message to a poster that carries no post reference? Today a script that sends a plain message to a poster is not counted.
4. Should reports need a minimum account age, or a judge as a third voice, before a post is taken down?
