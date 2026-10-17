---
last_reviewed: 2026-10-16
owner: Freegle dev team
covers:
  - iznik-nuxt3/modtools/components/ModSysAdminUx.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxOverview.vue
  - iznik-batch/app/Services/Ux/UxRollupService.php
---

# Improving Freegle from what people do and what they care about

The overview of the idea farming and experiments work, written for someone who has not seen the code. The detailed pages are listed at the end.

## The problem

Everybody wants Freegle to work better, and everybody has opinions about how: move this button up, change that wording, drop that step. The opinions are not founded in data, so the discussions go round in circles and the loudest view wins. We want decisions that rest on evidence, reached quickly, without a meeting.

## Where the evidence comes from

There are two sources, and we need both.

1. **What people do.** We already record how members move through seven flows: offering an item, asking for one, replying to a post, arranging a collection in chat, marking a post taken or received, signing in or joining, and changing settings. We can see where they stop, go back, or leave. We can record more of this, and analyse it automatically rather than by hand.
2. **What people care about.** We ask members directly, one short question at a time. If a member says something is a problem, that is a fact: they care about it. Their proposed fix may be wrong, and often is, but they cannot be wrong about caring. The job is to collect those concerns, group the ones that say the same thing, filter out the noise, and turn them into something coherent.

This is about the whole experience of using Freegle, not only the steps of a form. Ask members what they care about and they will say no-shows: people who promise to collect and never turn up. That is not a drop-off on any page.

## Three kinds of problem

Most of what members raise falls into one of three kinds, and they need different handling.

1. **Friction.** A step that is confusing, a button that is hard to find, an email that does not say what happens next. These are soluble, and the system below handles them end to end: find, try, measure, keep or drop.
2. **Human behaviour.** People are unreliable. They promise and do not turn up, they reply and go quiet. That is not because a button is in the wrong place or an email could be snappier; it is because they are people. We can nudge: a reminder, a confirm step, showing a poster how reliable a replier has been. We can measure whether a nudge helps, and keep the ones that do. We cannot fix it, and the system has to know the ceiling and say "not soluble by the site" rather than keep trying.
3. **The model itself.** The biggest problems we have seen lately were not about screens at all. They were fundamental assumptions in members' heads about how Freegle works: where a community's boundary is, how far people will travel. That was the motivation behind Rippling Out. Finding these concerns, and deciding what to do about them, is beyond what AI can do today. It is the work that needs people's judgement, and it matters more than everything in the first kind put together.

The system's job is to deal with the first kind on its own, to nudge and measure the second, and to surface the evidence that points at the third, so that the people's time goes where only people can help.

## What the system does with it

**Find the points to improve, automatically.** The behaviour data is rolled up every day into a picture of each flow: how many start, where they drop out, how that compares with before. The biggest losses are marked without anyone looking for them.

**Collect concerns from members, and refine them.** Members who have agreed to help see one question on one screen: whether they had a problem the last time they used Freegle, what has worked well for them, what one thing they would change, and which part of Freegle works best for them. Later, once enough answers are in, they are asked which of three problems other members named has been their biggest in the last three months. Each question has two ways out: "I don't understand" and one that fits, such as "I had no problems", "I can't think of one" or "None of these". Free-text answers are grouped into named problems by an AI, each with a quote checked against the answer it came from, and a problem is shown to staff only once at least two members have raised it. Nothing a member wrote leaves our systems until the privacy wording covers it.

**Turn a concern into options.** A concern is not a solution; it is the start of several. Each option becomes a candidate experiment with a measure at the level of the concern, not of a page: for no-shows, the share of promised items that are actually collected. This part is being designed now.

**Test options on a fraction of members, and reach a verdict fast.** An experiment is one small change with two or more versions, usually two: what we have now, and the change. A share of members see the change, the rest do not, and a member always gets the same version. Today the change is on a page; emails, notifications and what the site does behind the scenes come next. We measure the thing that matters, watch the other flows so a fix in one place does not push the problem somewhere else, and a statistical rule decides: keep, revert, or inconclusive (which means revert). An experiment runs for a day at most, a handful run at once, and every change sits behind a switch so it comes off without a deploy.

**Let an AI drive the loop.** An AI reads the flow figures, the problems members raised and recent bug reports, proposes options with a score, turns the promising ones into small changes, runs them as experiments and acts on the verdicts. It is checked against Freegle's own history: given an old snapshot of one flow, it has to rediscover at least a quarter of the changes the team later made to what members see, and that check runs again before the loop is switched on. The loop is off until then, and a person can start an experiment by hand or stop a running one at any time.

## What changes for us

The font and button-height conversations stop. Anyone with a view asks for it as an experiment, a fraction of members see it, and the result settles it. If it helps, it stays; if not, it goes, with no argument needed. That is the point of clearing the small discussions away: the bigger ones, about human behaviour and about the model itself, are the ones that change what Freegle is for its members, and they need the attention the small ones were taking.

## The components

Three parts, kept separate so each can be used or switched off on its own, and one place to see them.

- **The behaviour picture.** The site records the steps members take through each flow. Once a day this is rolled up into a picture of every flow: how many started, where they left, how that compares with before. Nothing about a named member is kept in it.
- **The experiments engine.** Holds each experiment: its two versions, the share of members who see the change, what is measured, and the verdict. Splits members between versions, records who saw what, works out the result, and switches the change on or off. Every change it controls sits behind a switch in the database, so it comes off without a deploy. The same engine is to run experiments in emails, notifications and behind-the-scenes behaviour, measured by what members then do.
- **The member card.** One question on one screen, shown now and then to members who have agreed to help, with a way out on every question. The answers are grouped into named problems, each with a checked quote, and counted.
- **The idea generator.** An AI that reads the behaviour picture, the member problems and recent bug reports, and proposes options with a score (expected effect and how sure it is), each tied to the problem it answers. It is checked against Freegle's own history, and it is to be able to say that a problem has no good option.
- **The hourly loop.** Turns the best options into small changes, runs them as experiments, and acts on the verdicts. It is off until it is switched on, and a person can stop or override it at any time.
- **One tab for staff.** The SysAdmin UX tab in ModTools, in six sections: Overview, Funnels (one flow at a time, the biggest loss marked, filters by device and date, every view linkable), Experiments (running and finished, with versions and verdicts), What members say (grouped problems, quotes and counts), Ideas (ranked, with what happened when each was tried), and Pages and features.

Rules the system works within: at most 24 hours per experiment and five at once (whether email and behaviour changes may run longer is a decision still to make); switches live in the database, so a change comes off without a deploy; no database change when an experiment or survey starts; staff see counts and checked quotes, not who said what; members are never interrupted to ask why they gave up; nothing a member wrote goes to the AI until the privacy wording covers it.

## Words used here

- Flow: the steps a member goes through to do one thing, such as offering an item. The SysAdmin UX tab calls a flow a funnel.
- Arm: one version of a change in an experiment.
- Guardrail: a measure that must not get worse while an experiment runs.
- Microvolunteer: a member who has agreed to help with small tasks, such as answering a question about Freegle.
- Privacy gate: the switch that must be on before any member's words are sent to the AI.
- Backtest: checking the idea generator against changes the team made in the past.

## Detailed pages

- [UX telemetry](./reference/ux-telemetry.md): what is recorded about each flow and how it is rolled up daily.
- [The SysAdmin UX tab](./reference/ux-sysadmin-tab.md): the sections, filters and linkable views.
- UX experiments (`docs/developers/reference/ux-experiments.md`, arrives with #1787): the engine, arms, the verdict rule, the switches.
- UX member surveys (`docs/developers/reference/ux-member-surveys.md`, arrives with #1790): the member card, the questions, grouping and the privacy gate.
- UX ideas (`docs/developers/reference/ux-ideas.md`, arrives with #1794): the generator, its history check, the hourly loop.
