# Conventions that are not obvious from the code

These are decisions, not preferences. Each was settled once and is easy to get wrong by
reasoning from first principles instead.

## Every table gets an `id`

Give a new table a surrogate `id` even when a natural key is unique and is the only way the
table is ever read, and even when that costs an index. Consistency across the schema is worth
more than the saving on one table.

## No Python for data work

For ad-hoc analysis, querying and modelling, use PHP or Node, not Python. The stack is PHP, Go
and JavaScript throughout; Python is foreign to it, and the host blocks installing packages
anyway. Something written in the house languages can be run and maintained by everyone else.

## Never put keys or secrets in a pull request

Not in code, not in test fixtures, not in the description, not in a commit message. This
includes partner API keys and mail credentials. A pull request is shared and cached, so a secret
committed once is leaked even after it is removed.

## Somebody's "home community" is geographic, not their membership

For any spatial question - proximity, coverage, boundary populations - work out the home
community from **which community's catchment area contains the member's location**, taking the
smallest when several overlap. Do not read it from the membership table. People belong to
communities they do not live in, and membership answers a different question.

## A nation comes from the country polygons, not from a code prefix

England, Scotland, Wales and Northern Ireland are already in `authorities` as polygons, with
`area_code = 'CUN'`. So "which nation is this post in" is one `ST_Contains` against the post's
own location, using the same machinery the authority reports use. There is a fifth `CUN` row,
**United Kingdom**, whose polygon matches everything; exclude it or every row comes back twice.

Do not try to read the nation off the code. `authorities.area_code` is an Ordnance Survey
Boundary-Line **area type** - DIW district ward, UTW unitary ward, MTW metropolitan ward, CED and
UTE electoral divisions, WMC Westminster constituency, SPC/SPE Scottish Parliament, WAC/WAE Welsh
Assembly, LBO/LBW London borough, CTY county, CUN country. It is not an ONS GSS code, so there is
no leading E/W/S/N to key off, and a guess that there is produces a plausible wrong answer rather
than an error. 17 rows have no code at all, including the 11 Northern Ireland councils.

Two further traps when a nation is wanted per post rather than per member:

- Take it from the post's location, never from a membership. That is the same rule as home
  community, below.
- Do not reach it through `messages_groups`. That has one row per group the post reached, so a
  rippled post multiplies and its nation count with it. One value per post.

The four outlines are rough, 20 to 45 points each, drawn to a few kilometres. That is enough to
say which nation a post is in and not enough for anything near a border; for that, use the
Scottish Parliament (SPC/SPE), Welsh Assembly (WAC/WAE) or Westminster (WMC) polygons, which are
detailed. Posts from before about 2021 often have no location of their own: the postcode district
in the title, then the centre of the first group the post went to, are the fallbacks, and saying
which was used keeps the difference visible.

## "Available initially" is the size of the pool

It means how many of a thing there were before any were given away. It is not a record of what
was showing at the moment the post went up, and it is not a running total.

## Community rippling opt-out is not a moderator setting

The per-community controls for whether posts ripple in or out are deliberately **not** exposed
as moderator settings in the interface. They are changed deliberately, centrally, through a
command. Do not add toggles for them.

## Moderators do not export member data

There is no member export in ModTools, and adding one back is not a small convenience. The
reason is data protection: a moderator needs to see members to moderate them, and that is not
the same as being able to take the list away. A button that writes the list to a file was
removed for exactly this reason (Discourse 10085/7), after a spell where it came back.

Disabling a button does not achieve this. The list is fetched from `/memberships`, so anything
that only hides the interface leaves the data one call away. If the capability is not wanted,
the code goes.

## TrashNothing accounts are never moderators, and are never merged

A TrashNothing account (a `tnuserid` stamp, or a preferred `@user.trashnothing.com` address)
is a partner's member, not a volunteer. It must not hold Owner or Moderator on any community;
`PATCH /memberships` refuses the promotion and `discourse:not-signed-up` lists any that do.

When a volunteer has both a Freegle account and a TrashNothing account, do not merge them.
They stay separate; remove the role from the TrashNothing one instead.

## Only a TN user id says who a TrashNothing member is

`users.tnuserid` is the only source of truth. A username or an address never decides who someone
is, who owns a post, or which account to merge or link. TN does not promise a username is unique,
a released one can be taken by someone else, and an address can sit on the wrong account. An
account with no TN id proves nothing either way, whatever it holds.

A username may only refuse something, as `MergeUsersTx` and `User::merge` refuse two different
ones. Do not add a rule that treats a matching username or address as the same member.

The email integration breaks this, deliberately. Posts and chat by email both match on the address,
and `IncomingMailService` is left alone because it is production's source of truth and is being
replaced: do not change it for this rule. Its replacement goes by TN user id. The one exception is
`handleSubscribe`, which gives an account it creates the id from the `x-trash-nothing-user-id`
header, because partner calls and API posts would otherwise split the member from it. It matches
nobody by the id. Anything reading that header needs TN's secret as well, because anyone can write it.

## A TrashNothing member is dealt with through their membership

Every TrashNothing member Freegle deals with has a membership of the community concerned, and
moderators handle them through it: ban, remove, set to moderated, chat review. Do not plan a
path that assumes they have none, such as reporting them to TrashNothing instead of banning
them. That idea has been planned and undone twice: posters with no membership (reversed
2026-10-13, which gave placed posters an implicit membership), then repliers with no
membership (reversed 2026-11-03).

- **A placed poster** gets an implicit membership when their post is placed on a community they
  did not choose.
- **A replier is already a member.** TrashNothing joins its members to a community before they can
  reply to its posts by email: of 28,051 TN replies to Freegle posts in the 30 days to
  2026-11-03, 28,027 came from someone who had joined a community the post was on before
  replying, 19 from someone who joined just after, and 5 from someone who never joined.
- **Messages between two TrashNothing members** stay on TrashNothing and never reach Freegle.

What a TrashNothing membership never does is become ordinary because of something the member did
on Freegle (replying, moving into the area, a moderator adding them): only choosing the
community through TrashNothing makes it ordinary, and only an ordinary one can be contacted.

## A TrashNothing post goes on the community the member chose on TrashNothing

The member picks the community for each post on TrashNothing, and the posts API sends that
choice as `freegle_group_ids`. A post that names a community goes on it, wherever the post is:
the one whose area contains the post when it names several, otherwise the nearest
(`PostSyncer::placement()`). Only a post that names none is placed by location, on the local
community, and it is unaddressed.

Do not decide either from memberships. A member of the local community who named none has still
not chosen it for this post, and a membership elsewhere may be nowhere near where the post is
(decided 2026-11-08, Discourse 10315).

## UX telemetry does not ask members why they gave up

The UX telemetry plan proposed a one-tap "why did you stop?" card shown after a member abandons
give, ask or reply. It was declined on 2026-10-12 and will never be built. The telemetry says
where members struggle; it does not interrupt them to ask. Do not add a prompt, survey or
`ux_feedback` table to the flows for this.

## An experiment counts a member once, on their first exposure

When an experiment is decided on the server and counted from `ux_experiment_exposures`, each member is one
count, on the first exposure of a type the source counts. The age test runs after that first one is
chosen: a first exposure that is not yet old enough is not counted and is not replaced by a later one. Do
not "fix" a low count by counting every exposure (a member mailed twice would count twice) or by taking
the latest (a member's count would depend on when you looked). A web view writes no row, so
mail-and-site experiments are measured from the first server exposure.

## No experiment code in the app build

An app release takes days to weeks, so a switch shipped in the app cannot be turned on or off quickly.
Behaviour in the app that the server decides is fine: a push title, a push's timing or an email chosen on
the server per member can be an experiment. `push-posts-title` was stopped on 2026-10-19 because the rule
was misread as "no experiments that reach app members"; that was wrong. The rule is about where the
switch lives, not who sees the result.

## Every shipped dependency has an AGPL-compatible licence

Freegle's code is AGPL-3.0-or-later (`NOTICE`). A package we ship must carry a licence
that can be combined with that: permissive licences, MPL-2.0, LGPL, GPL-2.0-or-later and
GPL-3.0 are fine; GPL-2.0-only, the Eclipse Public License and source-available licences
(Elastic, BUSL, SSPL, FSL, Commons Clause) are not. `scripts/dependency-licences.json`
holds the allowlist and the named exceptions, each with a reason. The CI job
`check-licences` fails a branch that adds anything else, and the commit hook refuses it.

`add-to-calendar-button` (Elastic License) is kept deliberately: it is tuned for the app,
the web and different handsets, and `NOTICE` grants an AGPL section 7 permission for it. Do
not replace it with a hand-built button to tidy the licence.

## Bulk items do not make a clearance

A post with `messages_bulk_items` rows is either a clearance or an ordinary member's post that the
auto-splitter turned into an item list (a `messages_autosplit` row in state `Split`). Only a
clearance gets the clearance extras: left out of freebie alerts, partner feeds and LoveJunk,
"Register interest" instead of a reply, the Clearances card in My Posts, per-person counts when
marked taken. For those, ask `ClearanceSql` (batch), `utils.IsClearanceSQL` (Go) or
`isClearance` (frontend), never "has bulk items". Keep asking about bulk items where the point is
the item list itself: display, per-item photos, the digest's count and the per-item stats. A check
written the old way gives a split post the clearance behaviour, with no error, and only once the
splitter is switched on. See `docs/developers/reference/auto-split.md`.

## See also

- `docs/developers/reference/coding-standards.md` - the rest of the coding rules.
- `docs/getting-started/decisions-and-rationale.md` - the bigger product decisions.
