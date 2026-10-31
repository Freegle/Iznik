# Candidate: automated review inside the no-communities experiment (thought experiment)

Status: draft
Last reviewed: 2026-10-28

> This is a thought experiment, like the two branches it builds on. Nothing here is agreed or
> planned, and nothing from it merges. It asks what the two experiments look like together.

## Goal

One experiment branch where PR #639's automated review (post-moderation with a flowchart review,
shadow and approve-only modes) runs inside PR #1576's world (a Freegle with no community model,
one site-wide rule set, humans able to add value but never a gate). Today they are two branches
that answer two halves of one question: #639 shows automated review deciding what may publish
itself while communities and queues remain; #1576 removes the communities and the queues but
reviews posts with rules-as-questions and a short automatic wait. Combined, the question becomes
answerable end to end: with no communities and automated review, what reaches members, what is
taken down, how long anything waits, and what a volunteer still does. Edward asked for this on
2026-10-28 as a candidate for the token window, and for the work from the 2026-10-27 sessions to be
reviewed as part of it.

## Why it qualifies

- Token-heavy because: #1576 is a whole-schema branch that must be re-merged with master (725 commits last time) and has lost coverage (Go about 5 points, Laravel about 2); porting #639 onto it touches Go, Laravel, ModTools and the e2e suite; and the measurement is a backtest of automated review over months of real moderation decisions.
- Unattended because: both branches already exist, pass their suites, and are public; the open choices below are few, and the backtest reads data that is already in the database.
- Worth it because: the two experiments are the evidence base for a decision other people will take, and separately they cannot say what members would actually experience; together they can, with numbers.

## What exists today

- PR #639 `feature/autoapprove-delay` - automated review for pending posts, off by default, two central lists (`FREEGLE_AUTOMOD_SHADOW_GROUPS`, `FREEGLE_AUTOAPPROVE_TRIAL_GROUPS`), a 20-minute wait before self-publish, never rejects; carries `plans/active/automod-flowchart.md` and generated moderator-guide screenshots.
- PR #1576 `experiment/self-moderating-community` - the community model removed from schema, spatial, routing and API; settings frozen at the live majority; rules as questions a model answers; TrashNothing still served through partner areas; `plans/active/2026-09-19-self-moderating-community.md` (inventory and design) and `plans/active/self-moderating-merge-decisions.md` on the branch. Up to date with master as of 2026-10-18.
- The two overlap on posts: #639 decides approve or hold with a reason; #1576 has live, short wait, and taken-down-with-the-poster-told. The mapping is hold to short wait, and a danger signal to take down. Unknown: whether #639's flowchart and #1576's rule questions agree on the boundary cases, which is what the backtest measures.
- Issue #1934 (2026-10-27) - a thought experiment for a private, working copy of one community hosted elsewhere; `plans/active/2026-10-27-self-hosted-group-instance.md` points at it. Related in spirit (what a community is, outside Freegle's hosting), not a dependency.
- `plans/active/2026-10-27-tech-group-meeting-build-brief.md` - items 1a and 1b (a numbers pack and a measurement of how much code exists only because communities are the admin unit) are measurement work that this candidate would consume, not produce.
- Unknown: which of the 2026-10-27 session outputs Edward means by "the function we built last night"; listed as an open question.

## Decisions settled

- **It stays a thought experiment, in public, with the same header as both PRs.** Do-not-merge, no release path, no switch in production. The point is the measurement and the walkthrough.
- **It builds on #1576's branch, not a third fresh one.** The group removal is the expensive part and it is done; #639 is the smaller port.
- **Automated review is measured by backtest before anyone looks at screens.** Run it in shadow over the last N months of posts and compare with what moderators did, by rule question, with the disagreements listed. Nobody is ground truth, moderators included, so the output is agreement and disagreement, not accuracy.
- **No per-community anything.** There are no communities on the branch; #639's two central lists become one site-wide mode switch for the experiment.
- **It ships with a preview, like Yesterday.** A self-contained, isolated stack that loads the last production backup, runs the branch's removal migration against that copy, and shows the member site and ModTools as they would look the morning after (Edward, 2026-10-28). Built the way Yesterday is: the normal compose files plus one override, only dev containers, nothing connected outside, and a gate in front. It answers "what would my community's posts look like" with real data rather than fixtures, and it is what the walkthrough is recorded from.
- **The preview comes loaded with real moderation examples.** Yesterday's posts, chats and reports in the backup are reset to their pre-decision state on the copy (approved posts put back to unreviewed, rejected ones likewise, held chat messages re-held, resolved reports reopened), so automated review and the one-page ModTools home have a day of genuine work to show. That is how a volunteer gets a feel for moderating in the new world: watching real posts from their own area flow through it, not fixtures (Edward, 2026-10-28). The reset is a script on the copy only, keyed by the decision timestamps in the backup, and it never touches production.

## Open questions for Edward

- Which of yesterday's decisions get reset on the preview: all of them, or a sample per community? Recommended: all of one day, because the point is the real mix, with a per-community filter in the ModTools home.
- Which outputs from the 2026-10-27 sessions should be reviewed here? Recommended: issue #1934 and the meeting brief's items 1a and 1b, read as inputs; say if there is something else.
- Does #639's 20-minute wait survive, or does #1576's short automatic wait replace it? Recommended: one wait, #1576's, with #639's reason shown to the poster.
- How far back does the backtest go? Recommended: six months, every post that had a moderator decision, sampled down only if the model cost is prohibitive.
- Does the ModTools home from #1576's rework summary get built on the combined branch, or left as described? Recommended: build the one-page home list only, because it is what the walkthrough needs; the fuller redesign is `plans/token-burn/modtools-by-role.md`.
- Coverage: recover the lost Go and Laravel coverage on the branch, or accept it for a thought experiment? Recommended: trace which files lost it and recover what is cheap, so the suites stay a usable signal.

## External prerequisites

- [ ] Model budget for the backtest (the rules-as-questions judge over months of posts). Owner: Edward. Needed before task 4.
- [ ] Nothing else; both branches are public and self-contained.

## Tasks

| # | Task | Depends on | Parallel track | Touches |
|---|------|-----------|----------------|---------|
| 1 | Re-merge master into #1576's branch; suites green; merge decisions file extended | - | A | all four trees |
| 2 | Trace and recover the lost coverage (Go, Laravel) on the branch | 1 | A | iznik-server-go, iznik-batch |
| 3 | Port #639's automated review onto the branch: one site-wide mode, hold maps to short wait, danger maps to take-down with the poster told; ModTools shows the reason | 1 | B | iznik-server-go, iznik-batch, iznik-nuxt3/modtools |
| 4 | Backtest harness: shadow-run the combined review over past posts, compare with moderator decisions, report by rule question with disagreements listed | 3 | B | iznik-batch |
| 5 | The one-page ModTools home list from the rework summary, enough for a walkthrough | 3 | C | iznik-nuxt3/modtools |
| 6 | Preview stack: a Yesterday-style override that restores the last backup, runs the three-pass removal SQL on the copy, brings up dev containers on the branch behind a gate; timed and repeatable, because each backup is a new morning | 1 | D | docker-compose override, scripts, docs/ops |
| 6a | Example reset on the copy: find yesterday's moderation decisions in the backup (approvals, rejections, chat holds, report resolutions) and put each item back to the state before the decision, with a list of what was reset; run as part of the preview bring-up | 6 | D | scripts, iznik-batch command |
| 7 | Walkthrough: generated screenshots and a short video of a post's life on the branch, recorded from the preview with yesterday's reset examples, and the PR description rewritten as the combined thought experiment | 4, 5, 6a | B | docs, PR text |

## Acceptance

- The branch builds and all four suites pass; coverage is within two points of master or the shortfall is listed by file.
- A clean post is live at once; a flagged post waits the short wait and goes live with no person involved; a post with a danger signal is taken down and the poster is told why, in the judge's words.
- The backtest report lists, per rule question, how often automated review agreed with the moderator decision, and shows every disagreement with the post and both reasons.
- A volunteer opening ModTools sees what the system did and one action per row; nothing on the site is waiting for them.
- The preview loads the latest backup and comes up on the branch in under an hour from a cold start, isolated the way Yesterday is, and a named community's recent posts can be browsed on it as a member would see them.
- On the preview, yesterday's moderated items are back in their pre-decision state, automated review has run over them, and the ModTools home shows what it did to each, with the real outcome from the backup available beside it for comparison.
- The PR header says thought experiment, do not merge, and nothing in the branch changes production when deployed with the mode off.

## Review burden

- PRs: 1 experiment PR (the existing #1576, updated) plus the backtest report as a file in it; task 1 may be its own PR to keep the merge reviewable, and the preview stack (task 6) is its own PR because it is infrastructure the main checkout can carry
- Each UI PR carries: screenshots or a walkthrough video
- Each migration PR carries: raw production SQL in the description (the branch already carries the three-pass removal SQL; this adds none unless the mode switch needs a column)
- Docs page(s) to add or update, with `covers:`: none in `docs/` for a thought experiment; the PR description and the plan files on the branch are the record

## Out of scope

- Any release, trial community, or production switch.
- The full ModTools redesign (`plans/token-burn/modtools-by-role.md`).
- The numbers pack and the code-share measurement from the meeting brief; they are inputs if they exist.

## Related plans

- PR #639, PR #1576 and the plan files on their branches - built on.
- `plans/active/2026-10-27-self-hosted-group-instance.md` and issue #1934 - linked.
- `docker-compose.override.yesterday.yml` and `docs/ops` on Yesterday - the preview copies that shape.
- `plans/active/2026-10-27-tech-group-meeting-build-brief.md` - linked; items 1a and 1b are inputs.
