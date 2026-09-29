# Standard messages: options for a simpler, less error-prone model

Status: options paper, no code changes. Written 2026-09-13 from a read-only look at
production and the current code. "Active" below means a Moderator/Owner membership whose
user has logged in within 90 days.

## 1. What production looks like today

| Measure | Value |
|---|---|
| Rows in `mod_configs` | 4,155 |
| ...of which automated-test leftovers (`testconfig`, `Test`, `TestConfig (Copy)`, no creator) | 3,242 |
| ...referenced by no membership at all | 3,821 |
| Configs used by at least one active mod | 224 |
| Rows in `mod_stdmsgs` | 25,373 |
| ...in configs nobody references | 15,230 |
| Stdmsgs in configs used by active mods | 6,788 |
| ...used at least once in the last 12 months | 1,248 |
| ...never used in 12 months | 5,540 (82%) |
| ...byte-identical to a message in one of the four base configs | 2,186 (2,020 of them sitting in some other config) |
| Distinct bodies among those 6,788 | 3,287 |
| Real stdmsg sends in 12 months (excluding the `stdmsgid=0` "no template" rows) | 24,504 across 1,629 distinct messages |
| Active configs where no message was used at all in 12 months | 48 of 224 |
| Groups with active mods | 501 |
| ...where all active mods use one config | 258 |
| ...where active mods use two or more configs | 243 (48%) |
| Active mod memberships with a config | 1,214: 283 own config, 645 someone else's (162 the Default), 286 whose creator has been deleted |
| Role split of those memberships | 966 Owner, 248 Moderator |
| Mods on 4-10 groups | 63, of whom 49 use two or more configs; 30 use four or more |
| Active messages mentioning Yahoo / "Freegle Direct" / `http://` | 277 / 444 / 385 |
| Mistyped substitution variables in active messages | `$James`, `$name`, `$Moderator`, `$Memberid`, `$groupnameCafe` and others |

Which messages actually carry the load (12-month sends, top of the list):

- "Approve and Unmod" variants in ten different configs: roughly 6,500 sends, over a quarter of all use. This is a workflow action (approve, set member unmoderated, send a welcome) that happens to be modelled as a template.
- "blank letter - Leave / Reject" variants: roughly 2,300 sends, about a tenth. This is "let me type".
- Genuine policy templates come next: "Duplicate message", "Fair Chance Policy advice" (one config, four groups, 843 sends), "Too vague wanted", "Personal details removed".

Two things that need no config at all account for over a third of all stdmsg use.

The four base configs today: `38771 Default Configuration Freegle Groups` (81 active mods, 162
memberships), `71356 Clone for caretakers` (32 mods), `72479 Jo Default Config 2024` (19 mods),
`72593 Freegle Standard Messages 2026 (WIP)` (5 mods, 59 memberships, already using `$editlink`
and `<optional>`). Ops Team is converging on 72593 (Discourse 9103, 9793).

Group rules are already structured data: 495 of 496 published groups have a non-empty
`groups.rules` JSON (`knives`, `animalsoffer`, `medicationsprescription`, `carboot`,
`restrictpersonalinfo`, ... about 35 booleans plus free text). Nothing in the standard-message
code reads it.

## 2. Where the complexity comes from

1. **One object, three concerns.** A config bundles (a) message templates, (b) per-mod
   preferences (from-name, BCC addresses, subject colouring, subject length), and (c) per-group
   policy (which messages exist and what they say). Changing any one of these means cloning all
   three.
2. **Assigned at the wrong grain.** `memberships.configid` makes the choice per (mod, group).
   Two mods on one group can send a member different policy text for the same situation; the
   data shows this on half of all groups. A mod on ten groups faces ten choices.
3. **Sharing by deep copy.** "Copy this whole config" is the only way to get an editable
   version, so improvements to the base never reach the 2,020 identical copies, and stale content
   fossilises. Cloning is also why 82% of active messages are never used: they came with the copy.
4. **A visibility union instead of ownership.** `canSee` = created-by-me ∪ default ∪ used by
   anyone on a group I moderate. The picker is a global list, so test clutter and other people's
   configs leak into everyone's dropdown, and "who may edit this?" needs the
   protected/createdby/null-creator special cases (`modconfig.go:65-103`, commit `2576bf16b`).
5. **Resolution logic in two places.** The own → other mod → own-created → default fallback
   chain lives in `iznik-batch/app/Models/ModConfig.php::getForGroup` and again in
   `ProcessBackgroundTasksCommand::resolveBccAddress`. The frontend has a third, simpler version
   in `ModMessage.vue:1276-1305`.
6. **Dead weight.** `iznik-server-go/modtools/modconfig.go` (unrouted duplicate handler with a
   wrong struct), `mod_configs.chatread` (settable, read nowhere), `mod_bulkops` +
   `mod_bulkops_run` (no API, no UI, no runner), `newdelstatus` (Yahoo delivery statuses, 42
   active rows), `network` (always "Freegle").
7. **No validation where mistakes are made.** Substitutions and `<editthis>`/`<optional>` are
   expanded only in the browser (`ModStdMessageModal.vue:828-970`,
   `utils/stdMessageDirectives.js`); the server accepts any body, so `$Memberid` and `$James`
   go out to members as literal text. Nothing warns on Yahoo-era content until send time.
8. **Ordering stored on the parent** as a JSON list of child ids (`messageorder`), which has to
   be kept consistent by hand and silently drops or appends.

## 3. What flexibility is actually exercised

- **Group identity**: `$groupname`, `$myname`, `$owneremail` appear in almost every message.
  Substitution already handles this; it is not a reason to have separate configs.
- **Local policy**: some groups reject animal offers, some do not; some restrict knives; Charles's
  four groups run a Fair Chance Policy; repost intervals differ. This is per-group, and it is
  exactly what `groups.rules` records.
- **Free text**: the blank template is one of the most used buttons.
- **Workflow flags**: `autosend`, `rarelyused`, and above all "approve and unmod"
  (`newmodstatus`). These are per-mod habits or per-action behaviour, not message content.
- **Personal tone**: a minority ("Friendly messages", 6 mods). Expressed today by cloning.
  The segmented editor plus `<editthis>` already lets a mod personalise at send time.

## 4. Options

### A. Hygiene only

Keep the model. Delete the 3,242 test leftovers and the 3,821 unreferenced configs (with their
15,230 messages); stop tests writing to prod. Add a server-side linter (unknown `$vars`, stale
strings) on save. Hide messages unused for six months behind the existing "+N" expander
automatically instead of the hand-set `rarelyused` flag. Show "used N times" per message in
settings. Remove the dead code and columns in item 6 above.

- Fixes: clutter, some stale content, the typo class of error.
- Leaves: per-(mod, group) assignment, copy-drift, mixed configs on half of all groups.
- Cost: small. Do this whatever else is chosen.

### B. One set per group, chosen by owners

Move the choice from `memberships.configid` to `groups.configid`. Every mod on a group uses the
group's set; the buttons a mod sees follow the group of the post they are looking at (which is
what `ModMessage.vue` already does with `currentGroupid`, so rippled-in copies come out right for
free). Per-mod preferences (default autosend, show rarely-used, subject colouring) move to the
mod's own settings and out of the config.

- Multiple owners: any owner may change the group's set or edit it; the change is logged
  (`Config/Edit` already is) and settings shows "last changed by X on date". No lock needed:
  the right to edit comes from being an owner of a group that uses the set. Note that 80% of
  active mod memberships are already Owner, so this is "one setting per group", not "a
  gatekeeper per group".
- A mod on many groups makes no choice at all. Their buttons vary by group only where the
  groups' owners chose different sets, which is the correct outcome when local policy differs.
- Clusters: a set is simply referenced by several groups. Fife's ten groups point at the Fife
  set; the caretaker groups point at the Ops set. The cluster is the shared reference, not
  owner overlap, so it needs no enforcement. Add "apply this change to my other groups using this
  set" as a convenience.
- Migration: for each group, pick the set used by the most active mods (tie-break: the owner
  with most recent activity), write it to `groups.configid`, mail the group's mods with what
  was chosen and why. Dry-run report first.
- Fixes: mixed configs on a group, the "which config am I on here?" question, the dropdown
  (it becomes "sets used by groups you own" plus the Freegle set), the two fallback chains.
- Leaves: copy-drift and the unused-message pile, unless combined with C.

### C. Inheritance instead of copy

A set may have a parent. The Freegle set (72593 today) is the root, maintained by Ops. A group set
inherits every message from its parent and can hide one, override one's title or body, or add a
local one. Resolution at read time: root → group set. Central improvements reach every group
the next time a mod opens a post. The 2,186 identical copies collapse into inherited references;
only real differences remain as rows.

- Schema sketch: `mod_configs.parentid`; `mod_stdmsgs.overridesid` (the base message this row
  replaces) and `mod_stdmsgs.hidden`; `mod_stdmsgs.position` replacing `messageorder`.
- One resolver in Go (`/modtools/messageset?groupid=`) returning the effective list with a
  badge per message: Standard / Customised / Local / Hidden. Laravel's BCC lookup reads
  `groups.configid` and the same resolver.
- UX: a customised message shows the standard text alongside and a "reset to standard" button.
  When Ops changes a base message that a group has overridden, the group's owners see
  "standard text updated on date" with a diff, and choose keep or adopt. That is the honest
  answer to "we will be lumbered with what others decide": local text stays local, and fixes are
  offered, not forced.
- Migration is a classification: for each active message, identical to a base body → drop
  (inherit); same title and action as a base message but different body → override; no
  counterpart → local extra. The linter flags stale content during conversion so Ops can see
  which overrides are worth keeping.
- Fixes: drift, the unused pile (most of it was inherited clutter), stale content at scale.
- Cost: the resolver and the badge/diff UI. Moderate. The concept must be shown clearly or
  "why did my message change?" becomes the new support thread.

### D. Rules-driven policy messages

Tag each base policy message with the `groups.rules` key it enforces (`animalsoffer`,
`knivesrestrict`, `medicationsprescription`, `restrictpersonalinfo`, ...). A group shows a policy
message only if its rule is on, and the message can pull the rule's wording (for "other" free
text) into an `<optional>` block. The Approved Members welcome can list the rules that are on.

- This removes the main reason groups needed their own copy of the reject/leave set. The
  Animals reject appears on groups that ban animal offers and nowhere else, without anyone
  curating it per group.
- Depends on B (a group-level notion of "the set") and works best on top of C (local extras for
  what rules do not cover, such as Fair Chance Policy).
- Cost: a `rule` column on base messages, the visibility filter, and Ops tagging 44 messages.
  Small once B exists.

### E. Single national set, free text only

Everyone uses the Freegle set; no configs, no overrides; local variation only through
`<editthis>`, `<optional>` and the blank template.

- Simplest code by far. Rejected: it deletes working local policy (Fair Chance Policy, 843
  sends), it does not fit the fact that group rules genuinely differ, and the mod reaction on
  Discourse 9103 shows it would be resisted and worked around by keeping messages in documents.

## 5. Recommendation

Do A now, then B, then C with D. Reject E.

Independent of options, and worth doing first because they shrink the problem:

- Make "Approve and set unmoderated" a first-class action with an optional note, not a template.
  Over a quarter of all stdmsg use disappears from the config problem.
- Make "Write your own" a built-in button per action. Another tenth.
- Delete the test leftovers and unreferenced configs, and stop the leak (the e2e suite has
  clearly run against production at some point; the names match
  `tests/e2e/test-modtools-settings-modconfig.spec.js`).
- Validate on save in Go: unknown `$variable` is an error; stale-string warnings are shown in
  settings, not only at send time; a shared substitution catalogue (one JSON used by the
  editor, the PDF export and the validator) so the list cannot drift.
- Remove `modtools/modconfig.go`, `chatread`, `mod_bulkops*`, `newdelstatus`, `network`.
- Replace `messageorder` with a position column.
- Auto-derive "rarely used" from `logs.stdmsgid` instead of a hand-set flag, and show usage
  counts in settings so Ops can prune the base set on evidence.

Staging:

1. **Stage 0 (A + the cleanups)**: no behaviour change for mods; smaller tables; validation.
2. **Stage 1 (B)**: `groups.configid`, owner-chosen, mod preferences moved to user settings,
   one resolver, migration with a per-group dry-run report and a mod mail. Mods lose the
   per-group picker in their own settings and gain "this group uses: X, chosen by Y".
3. **Stage 2 (C + D)**: parent link, overrides, badges, diff-on-base-change, rule tags. Convert
   the 224 active sets by classification; publish the per-set report to Ops before switching.

## 6. Answers to the specific questions

- **Mods on the same group with different configs**: B removes the possibility. The set is a
  property of the group.
- **Owner sets it, but there are several owners**: any owner can; changes are logged and shown;
  the set is shared by reference so all owners edit the same thing. No lock semantics beyond
  role. Ops keeps the root set protected as today.
- **A mod on several groups**: nothing to choose; buttons follow the post's group. Personal
  habits (autosend, show rare, colouring) follow the mod via user settings.
- **Clustering groups onto one config**: by shared reference (many groups → one set), not by
  owner overlap. Owners of any group using the set can edit it; "apply to my other groups" makes
  the common case one click. Enforcing clusters through owner membership would be brittle and
  is not needed.

## 7. Risks

- Migration choices for the 243 mixed groups will upset someone; the dry-run report and a
  short "this is what we picked and why" mail are the mitigation, plus a one-click switch for
  owners for the first month.
- Inheritance needs clear language. Suggested: "Freegle standard", "Customised for this group",
  "Local to this group", "Hidden on this group".
- Support/Ops accounts moderate 40-80 groups each and currently switch configs to see what mods
  see; with B they see the group's set automatically, which is simpler, but the `?all=true`
  listing should stay for Support.
