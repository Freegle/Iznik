# Candidate: ModTools organised by role, most urgent first, nearest first

Status: draft
Last reviewed: 2026-10-28

## Goal

A moderator who signs in lands on one list of things worth doing now, ordered by how urgent
each is and then by how near it is to them, with no left-hand menu to learn. People whose role
is not moderating (Support, SysAdmin, Finance, Partnerships) land in a view built for that role
and never see the moderation furniture. The whole tool looks like something made this decade.
Brief from Edward, 2026-10-28: the left-hand menu "is all wrong"; most people who sign in are
ordinary moderators, so the primary view is theirs; other roles go to their own views; the
moderator view is organised by most urgent first (chat review at the top), biased towards local
activity but not limited to it, using travel-time distance to place the activity.

## Why it qualifies

- Token-heavy because: ModTools is 269 Vue components across 25 page trees, plus the Go endpoints that feed them; a role model, a work-item feed with urgency and travel-time ordering, a new shell, a restyle, screenshots, e2e and unit tests at each layer.
- Unattended because: once the roles, the urgency classes and the locality rule are settled, every task reads data that already exists and nothing waits on a person or an outside system.
- Worth it because: hundreds of volunteers use ModTools weekly, and today each one has to find the work behind a menu designed around the database rather than around them; the people who answer support, run the books or manage partners wade through moderation screens to reach their own.

## What exists today

- `iznik-nuxt3/modtools/layouts/default.vue` and `iznik-nuxt3/modtools/components/ModMenuItemLeft.vue` - the left-hand menu, one item per page tree, with badge counts gated by team and role (commit `3ae41b7c40`).
- `iznik-nuxt3/modtools/pages/` - 25 trees: messages, chats, members, spammers, logs, support, sysadmin, giftaid, partnerships, teams, admins, alert, communityevents, volunteering, map, images, rippling, discourse, helper-escalated, simulation, applog, settings, modtools, index, login.
- `iznik-nuxt3/modtools/components/ModSysAdminUxNav.vue` and the SysAdmin page - the one place that already has a role-specific sub-navigation; a pattern to generalise, not copy.
- `iznik-server-go/isochrone/isochrone.go` - `EnsureIsochroneExists(locationid, transport, minutes)` and the `users_isochrones` table: travel-time polygons already computed for members' browsing. The same machinery gives a moderator's "near me" area from their own location.
- Posts, chats, members and events all carry a location or resolve to one (post location, the post behind a chat, the member's location). Unknown: how many moderators have a usable own location set; this decides the fallback.
- Work counts: the badge totals (pending, spam, chat review, edits, members) are computed per page today; there is no single ordered feed. Unknown: whether the existing count queries are cheap enough to reuse per item or need a rollup.
- `plans/active/2026-07-30-nuxt-ui-scoping.md` - the only prior design work on the look: a scoping report for moving from bootstrap-vue-next 0.24 to Nuxt UI, not started. It counts 2,678 `<b-*>` tags, 171 ModTools components among them.
- A deleted December 2025 design review covered the member site, not ModTools. No ModTools redesign has been designed before this.
- PR #1576 (experiment, do not merge) carries a one-page summary of a ModTools rework for a site with no communities: a home page that lists what the system already did and offers one action on top, with queues, badge counts and "approve" gone. That is a thought experiment, and this candidate does not depend on it, but the home-list idea is the same shape as the moderator view here.

## The sketch

**Roles.** A person has one or more of: Moderator, Support, SysAdmin, Finance, Partnerships.
Moderator is anyone with a moderator or owner membership. Support and SysAdmin are the existing
system roles. Finance is the Gift Aid team and the treasurer's helpers (the Gift Aid page, donation
recording, the income dashboard candidate). Partnerships is whoever maintains partner deals. Roles
are national; none of them adds a per-community setting. Someone with several roles gets a switcher
in the header and lands in the one they used last.

**Moderator home: one list.** No menu. Each row is a piece of work with what it is, where it is,
how long it has waited, and one or two actions. Rows are grouped by urgency class, most urgent
first, and within a class the nearest come first. Classes, first draft for Edward to reorder:

1. Chat review: a member is waiting on a held message.
2. Messages to volunteers and member questions.
3. Reports and spam: something may be live that should not be.
4. Pending posts, oldest first within the local band.
5. Edits and post reviews.
6. Members: applications, flagged members, merges.
7. Events and volunteering awaiting a look.
8. Quiet rows: just published nearby, completed freegles, new members. Information, no action.

**Local first, never local only.** The moderator's own location gives a travel-time area through
the isochrone machinery (default 30 minutes driving, their choice of mode and minutes, remembered).
Inside each class, items whose location falls in that area come first, then the rest by age. A
"Near me / Everywhere" control at the top narrows the list; the default shows everything, ordered.
A moderator with no location is asked to set one the first time, with the centre of the areas they
moderate offered as a one-tap default.

**Other roles.** Support lands on member search and the support queue. SysAdmin lands on the
present SysAdmin tab set. Finance lands on Gift Aid, donation recording and, when built, the income
dashboard. Partnerships lands on the deals pages. Each view has its own short navigation and none
of the moderation rows.

**Look.** One set of design tokens (type, spacing, colour, radius) shared by all views, a plain
card list on phone and desktop, no sidebar. Whether this is a restyle of the current Bootstrap
components or the first step of the Nuxt UI move in the scoping report is an open question below.

```
+--------------------------------------------------------------+
| Freegle ModTools        [Near me 30 min v]  [Moderator v] (me)|
+--------------------------------------------------------------+
| CHAT REVIEW                                                   |
|  Held message, 2h, 1.2 mi   "is this still available..."      |
|      [Release] [Read]                                         |
|  Held message, 5h, 18 mi    ...                               |
| MESSAGES TO VOLUNTEERS                                        |
|  Alex B, 40 min, 3 mi       "I can't find my post"  [Reply]   |
| REPORTS AND SPAM                                              |
|  OFFER: Sofa, reported x2, 1h, 2 mi   [Take down] [Keep]      |
| PENDING POSTS (4 near you, 31 elsewhere)                      |
|  ...                                                          |
| NEARBY TODAY                                                  |
|  12 posts published, 3 completed, 2 new members               |
+--------------------------------------------------------------+
```

## Decisions settled

- **Views are by role, not by community.** A role is national. No new per-community settings and no change to who may moderate what; permissions stay as they are, the order and the shell change.
- **Locality comes from the isochrone machinery that already exists.** No new distance model; the member one, keyed on the moderator's own location.
- **Local is an ordering, not a filter, by default.** Far work is never hidden unless the moderator narrows the list themselves, because a quiet area's volunteer must still see the national backlog.
- **The urgency order is fixed centrally, not per moderator or per community.** Otherwise two volunteers looking at the same work see different lists and nobody can describe the tool.
- **Support tools stay as they are inside the Support view.** This candidate does not redesign member search or the support queue.

## Open questions for Edward

- Is the role list right (Moderator, Support, SysAdmin, Finance, Partnerships), and does Finance exist as a role today or does it need creating from the Gift Aid team plus named people? Recommended: those five, with Finance created, because the income dashboard candidate needs a home that is not SysAdmin.
- Is the urgency order above right, and what age makes each class "urgent"? Recommended: the order as listed, with age shown but not used to jump classes.
- Default travel-time band? Recommended: 30 minutes driving, changeable, remembered per moderator.
- Where a moderator has no location: ask them, or silently use the centre of what they moderate? Recommended: ask once, offer the centre as the default.
- Restyle the Bootstrap components, or make this the first ModTools step of the Nuxt UI move? Recommended: restyle with shared tokens now, because the Nuxt UI move is a 2,678-tag job of its own and should not gate this.
- Does the badge-count menu survive anywhere, for people who want the old layout? Recommended: no; one shell, with the old pages still reachable by URL and from the list rows, so nothing is lost while the list is proven.
- Phone first? Recommended: yes; the list is a phone layout that widens, and the current tool is poor on phones.

## External prerequisites

- [ ] Three or four moderators willing to look at a prototype for an hour and say what is missing. Owner: Edward, via the volunteer channels. Not needed to build, needed before it replaces the menu.

## Tasks

| # | Task | Depends on | Parallel track | Touches |
|---|------|-----------|----------------|---------|
| 1 | Role resolution: one Go call that says which roles a signed-in person has, and the landing view | - | A | iznik-server-go |
| 2 | Work-item feed: Go endpoint returning rows across the classes with location, age and actions; isochrone ordering; cursor paging; tests with fixtures in every class | 1 | A | iznik-server-go |
| 3 | Moderator home list and the "Near me" control; row actions reuse existing endpoints | 2 | B | iznik-nuxt3/modtools |
| 4 | Role views: Support, SysAdmin, Finance, Partnerships shells with their own short navigation; role switcher | 1 | C | iznik-nuxt3/modtools |
| 5 | Design tokens and restyle of the shared ModTools components | - | D | iznik-nuxt3/modtools |
| 6 | Remove the left-hand menu; old pages reachable by URL and from rows; redirects | 3, 4 | B | iznik-nuxt3/modtools |
| 7 | Moderator guide pages and generated screenshots; e2e for each role's landing | 6 | B | docs, iznik-nuxt3/tests |

Tracks A, C and D can run at once in separate worktrees from the start; B follows A.

## Acceptance

- A moderator with one role signs in and sees the list, not a menu; the first rows are held chat messages if any exist.
- Two items in the same class, one inside the moderator's travel-time area and one outside, appear in that order; with "Everywhere" selected both are present.
- A Support-only or SysAdmin-only person sees no moderation rows and no moderation navigation.
- A person with two roles can switch and the choice is remembered.
- Every action available from the old pages is reachable from the list or a linked page; nothing becomes URL-only without a link.
- On a phone the list is usable one-handed and nothing scrolls sideways.
- A moderator with no location set is asked once and the list still works before they answer.

## Review burden

- PRs: 7
- Each UI PR carries: screenshots or a walkthrough video, on phone and desktop
- Each migration PR carries: raw production SQL in the description (expected: none, unless the saved travel-time preference needs a column)
- Docs page(s) to add or update, with `covers:`: `docs/moderators/` landing and navigation pages; `docs/developers/reference/` page for the feed endpoint and role resolution

## Out of scope

- Changing moderation permissions or what any role may do.
- The Nuxt UI migration itself (`plans/active/2026-07-30-nuxt-ui-scoping.md`).
- Member-site changes.
- Any per-community setting.

## Related plans

- `plans/active/2026-07-30-nuxt-ui-scoping.md` - linked; the look question above decides whether it starts here.
- PR #1576 - linked as a thought experiment with a similar home-list idea; not a dependency.
- `plans/token-burn/finance-dashboard.md` - the Finance view is where that dashboard would live.
