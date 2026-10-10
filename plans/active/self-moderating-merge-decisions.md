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
