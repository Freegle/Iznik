# Automated UX experiments: framework, funnel display, microvolunteer feedback

**Date:** 2026-10-14
**Status:** in progress. Master progress tracker for this work; the session log in
`.claude-session.md` points here.
**Builds on:** `plans/2026-10-11-ux-pain-point-instrumentation.md` (shipped as PR #1760 and #1777;
telemetry live since 2026-10-13), `docs/developers/reference/ux-telemetry.md`.

## 1. Edward's instructions (2026-10-14)

- UX measurement is live. Another session fixed measurement bugs on master (PR #1777, merged
  06:25 UTC); fix any further measurement bugs on master.
- Revamp the SysAdmin UX page so a human can see **where** in a funnel the problem is.
- Loop: monitor hourly. Rank ideas for UX improvements and experiments. Fixing one drop-off can
  move the drop-off later, so measure end to end.
- Implement the best ideas, merge PRs once green, monitor live, revert if not effective.
- **No more than 5 concurrent experiments. No experiment runs longer than 24 hours.**
- Build a framework for this automated UX work. **No slow schema changes on live.** No schema
  change when a new experiment goes live.
- **The framework itself goes in a PR for Edward to review; it is too big to go live unreviewed.**
  So the framework PR is not merged by this session.
- Plain-English experiment history on the SysAdmin tab, **per funnel**: what ran, what succeeded
  or failed, what ideas are left.
- Review how others do AI-driven automated optimisation in 2026.
- Automated work on live uses the **geeks token** (the `CLAUDE_CODE_OAUTH_TOKEN` subscription
  token the AI support helper already has on live), not metered API spend.
- **Microvolunteers**: ask them, in plain English with images, what they think of experiments.
  They have status-quo bias, so this measures how much existing members might like or hate a
  change; it never rules a change out. Also use them as a survey resource: "which of these is
  best / worst about Freegle", to focus idea generation on problem areas.
- No human intervention in the loop.

## 2. What is live today (measured 2026-10-14 06:50 UTC)

- Frontend deploys itself: green master auto-merges to `production`, Netlify builds both sites.
- Backend (Go API, Laravel batch) is deployed by hand from the Docker host; live batch was at
  `2fe8bba78` (this morning's merge).
- `ux_*` tables exist on live, the rollup ran for 2026-10-13; health rows since 10-08.
- Daily volume (flow_start, 24h): give 822, ask 389, login 1,420, reply 393, settings 429,
  chat_outcome 385, chat_promise 163. Completion: give 48%, ask 21%, login 46%, reply 72%.
- Client experiment assignment exists (`useExperiment.js`) but reads `public.UX_EXPERIMENTS` from
  the environment at build time: a change needs a deploy. `variant` is stamped on every flow event.
- `GET /config/:key` is public for allow-listed keys and reads the `config` table, with a 600s
  client cache. That is the no-deploy path for live assignment.
- Microvolunteering: `microactions` (6M rows, ENUM actiontype) is **not** to be altered. ~1,800
  distinct members did an Invite task and 500 to 650 did a review task in the last 30 days.

Funnel facts worth acting on (10-13, one day, so provisional):

| Funnel | Where people leave | Figures |
|---|---|---|
| give, mobile | first step (photos) | 311 entered, 188 completed, 97 abandoned (77 navigated away, 25 hid the tab) |
| give, desktop | first step | 290 entered, 203 completed, 80 abandoned |
| ask, mobile | first step (photos) | 202 entered, 119 completed, 107 abandoned; details step: 49 of 128 went back |
| ask, desktop | first step | 98 entered, 31 completed, 65 abandoned |
| login | signup pane | 927 entered, 427 completed, 307 abandoned (102 idle on desktop, 63 closed on mobile) |
| login | forgot pane | 43 entered, 0 completed, 43 abandoned (`closed`): the pane never records completion |
| give | photos revisited at the last step | 211 desktop, 151 mobile `field_revisit` on `photos` at whoami/whereami: looks like an instrumentation artefact, to verify |
| give/ask logged out | 246 mobile give starts logged out, 14 submitted | logged-out posters are most of the loss |

## 3. Design

### 3.1 Principles

1. **One experiment = one PR** that adds the variant behind `useExperiment('<name>') === 'b'`,
   frontend only, so Netlify ships it with no human. Nothing in the backend changes per experiment.
2. **Assignment is data, served live.** The `config` table key `ux_experiments` holds the JSON the
   client already understands (`{"name": {"control": [0,49], "b": [50,99]}}`). Starting, stopping,
   adopting and reverting an experiment is a write to that one row. The client reads it through
   the existing public `/config/ux_experiments` endpoint at boot, caches it in `localStorage` so a
   deep link into a flow has an arm before the fetch returns, and falls back to the env value.
3. **Measure end to end, by arm, from Loki, hourly.** Primary metric is the flow's completion rate
   (`flow_complete` over `flow_start`) by `variant`, read from live Loki with the same LogQL shape
   the rollup uses. Guardrails read the same way: submit failures, validation failures, help
   sought, and the **other** flows' completion by the same arm (a give change that lowers reply
   completion is caught because `variant` is stamped on every flow).
4. **Decision at 24h, automatic.** Bayesian beta-binomial on the primary metric: adopt when
   P(variant better) >= 0.90 and no guardrail has P(worse) >= 0.90; revert when P(variant better)
   <= 0.10 or any guardrail has P(worse) >= 0.95 (checked hourly for an early revert); otherwise
   **inconclusive, and revert**: the default is the status quo. Thresholds are config, not code.
   With 400 starts per arm per day, only a change of about 8 points on a 48% base is detectable
   in a day, so the ranked ideas favour changes big enough to show.
5. **Plain English everywhere.** Every experiment row carries a title, a hypothesis, what the
   variant does, the expected effect, and the result as a sentence with the counts behind it.
6. **No schema change per experiment or per survey.** Generic tables, created once.
7. **Microvolunteers are a reaction gauge, not a gate.** Their votes are shown next to the
   measured result as "existing members' reaction". A strong dislike is flagged in the history;
   it never changes the decision rule.

### 3.2 Tables (new, small, created once; no ALTER on any existing table)

| Table | Purpose |
|---|---|
| `ux_experiments` | one row per experiment: `flow`, `name` (the switch key), `title`, `hypothesis`, `change` (what B does), `expected`, `arms` JSON, `status` (`draft`, `running`, `adopted`, `reverted`, `inconclusive`, `stopped`), `started_at`, `ends_at`, `decided_at`, `decision` (plain English), `result` JSON (latest read), `pr_url`, `cleanup_pr_url`, `image_control`, `image_variant`, `idea_id`, timestamps |
| `ux_experiment_reads` | hourly snapshots per experiment: `hours`, `metrics` JSON (per arm counts for primary and guardrails), `summary` (plain English) |
| `ux_ideas` | the backlog per funnel: `flow`, `title`, `rationale`, `expected`, `evidence` JSON, `score`, `status` (`open`, `scheduled`, `running`, `done`, `rejected`), `experiment_id`, `source` (`ai`, `survey`, `human`), timestamps |
| `ux_prompts` | what microvolunteers are asked: `kind` (`experiment`, `survey`), `flow` nullable, `experiment_id` nullable, `question`, `body` (plain English), `options` JSON, `images` JSON, `status` (`open`, `closed`), `max_responses`, timestamps |
| `ux_prompt_responses` | `prompt_id`, `userid`, `answer` JSON, `comment`, `timestamp`; unique (`prompt_id`, `userid`) |

Production SQL in the PR description: five `CREATE TABLE IF NOT EXISTS`, no lock on anything.

### 3.3 Live-side framework (Laravel, hourly)

`php artisan ux:experiments-tick` (scheduled hourly, `withoutOverlapping`):

1. For each `running` experiment: read Loki for the window since `started_at` (per arm:
   flow_start, flow_complete, flow_submit_result ok/failed, validation_failed, help_sought,
   flow_abandon by last_step; and the other flows' start/complete), compute the posterior
   probabilities, write a `ux_experiment_reads` row with a one-paragraph summary.
2. Early revert if a guardrail is clearly harmed.
3. At `ends_at` (24h): decide, write `decision`, set `status`, and rewrite the `config` row:
   adopted => the variant arm covers 0 to 99; reverted or inconclusive => removed from the JSON
   (control for everyone). Close its prompt. Mark the idea `done`.
4. Rebuild `config.ux_experiments` from the `running` and `adopted` rows every tick, so the row
   can never drift from the table.
5. Refuse to start a sixth: starting checks the cap and sets the 24h end.

`php artisan ux:experiment {create|start|stop|list}` is the operator interface.

`php artisan ux:ideas-refresh` asks the AI support helper container (geeks subscription token)
for ranked ideas per funnel from the latest rollup figures, survey results and the experiment
history, and merges them into `ux_ideas` without duplicating open ones. The prompt follows the
superpowers brainstorming shape (Edward, 2026-10-14): frame the problem from the figures, walk
the member's journey through the funnel step by step, diverge across categories (copy, defaults,
order, removal, progressive disclosure, trust, error recovery, device-specific), then converge by
expected effect size times confidence times how locally the variant can be built and deleted.
It carries every idea already tried, running, adopted, reverted or rejected for that funnel with
its outcome, and is told not to repeat any of them unless it says what is different this time.
The prompt also carries the **git history of the area** (Edward, 2026-10-14: "test your idea
generation by looking at prior git activity"): the last six months of commit subjects touching
the flow's files, so a change already made is not proposed again, and the generator is checked
once by hand on the give flow against that history before the PR. It may propose **retiring a
function** (Edward, 2026-10-14): the feature adoption table and the
components with many impressions and few clicks are its evidence, and a retirement runs as an
experiment whose variant hides the function, judged by the same completion guardrails and the
members' reaction, so a removal is measured rather than argued.

### 3.4 Read and write API (Go)

- `GET /modtools/ux/experiments?flow=`: per funnel: running (with latest read), history, open
  ideas, prompt tallies. Support/Admin.
- `POST /modtools/ux/experiments` and `PATCH /modtools/ux/experiments/:id`: create, start, stop.
  Support/Admin. Writes the row and the `config` key the same way the tick does.
- `GET /microvolunteering` gains challenge type `UxPrompt` (lowest priority, at most one per
  member per day, never the same prompt twice); `POST /microvolunteering` accepts
  `{ux_prompt_id, answer, comments}` and writes `ux_prompt_responses`.
- `GET /config/ux_experiments` is already public; add the key to the allow list.

### 3.5 SysAdmin UX tab revamp

Per funnel, in this order:

1. **Headline sentence**: "Give: 822 started, 393 finished (48%, last week 46%). Biggest loss:
   36% of people who reached Photos left there; worst on mobile web (41%)."
2. **Funnel chart**: inline SVG, one bar per step in flow order (desktop and mobile routes merged
   by rank into named steps), width = people reaching the step as a share of starts, the loss
   between bars written as a number, the worst step highlighted, previous period as a thin grey
   outline. Click a bar for the existing device, logged-in and variant breakdown and the samples.
3. **Experiments on this funnel**: running (hours elapsed, latest plain-English read), past
   (title, dates, result sentence, decision, members' reaction), ideas left (ranked).
4. **What members say**: survey tallies for this area (best / worst counts, latest comments).

Data health stays first. Friction, second thoughts, help, adoption, regret and search stay below.

### 3.6 Microvolunteering

`MicroVolunteeringUxPrompt.vue`: the plain-English body, up to two images side by side (before
and after), the options as buttons, an optional comment. Experiment prompts ask "Would this be
better or worse for you?" (better / no different / worse). Survey prompts ask "Which of these
works best for you, and which worst?" over the funnel list.

### 3.6b Idea farming from members, with a problem/solution split (Edward, 2026-10-14)

Members are asked about **problems** and about **what they value**, never for solutions
(people describe pain well and design poorly; a solution asked for is a leading question).
Solutions are generated by the loop and tested by experiment. Three question kinds feed it:

1. **Open questions** (`kind=open`, free text only): "Think about the last time you used
   Freegle. What got in your way, or took longer than it should?" and "What do you value most
   about Freegle?" (what must not be broken: it feeds the guardrails and the "do no harm" test).
   Up to about 300 answers each, then the prompt closes itself.
2. **Theme analysis** (`ux:prompt analyse <id>`): the AI support helper (geeks token) codes the
   answers with a codebook: one theme per code, problem statements apart from solution
   suggestions apart from value statements, one answer may carry several themes, counts kept
   honest, two example quotes per theme (shown only in ModTools). Problems are written to
   `ux_problems` (flow, title, description, source, evidence, weight); solution suggestions
   become `ux_ideas` rows with source `member` linked to their problem; values go on the tab.
3. **Follow-up ranking** (`ux:prompt followup <id>`): a closed "which of these gets in your way
   most, and least" survey over the top problem themes (at most 7 options, randomised order on
   the phone, with "none of these" and "don't understand"), so the keen few who wrote do not
   decide the weights alone.

The ideas backlog is then a list of **solutions attached to problems**: the generator is given
the ranked problems first and asked to propose solutions to them before anything else, and the
tab shows, per funnel, the problems with their weight and source, the ideas and experiments
under each, and the values members named. Question wording follows survey practice (one topic
per question, neutral, concrete and recent, open before closed, a don't-know option, no
agree/disagree statements). The survey research (scratchpad survey-research.md, sources Pew,
AAPOR, Nielsen Norman Group, Google's in-product survey paper, Sawtooth MaxDiff) settles the
wording and method, applied in wave 3:

- Problem question: "Think about the last time you used Freegle. What, if anything, got in your
  way? Tell us the main thing in a sentence or two." Buttons: "Nothing got in my way", "Skip".
- Value question: "Think of a time Freegle worked really well for you. What made it good?"
  Button: "Can't think of one". (A real occasion gives specifics; "what do you value" gives
  polite praise.)
- Improvement question, if kept: "If you could change one thing about Freegle, what would it be?
  What would that help you do?" Only the second half counts as problem evidence; the change
  itself goes to the ideas list and is never counted as a problem.
- Follow-up ranking: four themes at a time, drawn so each is shown equally often, random order,
  "Which has got in your way most in the last few months?" plus "None of these has got in my
  way"; "least" is an optional second tap. Score = (most - least) / times shown.
- Awareness: "On Freegle, is it possible to [action]? Yes / No / Not sure", then reveal the answer
  and a link. Ask members who have not used the feature. About one in five questions is about a
  feature that does not exist, to measure yes-saying.
- Reaction: "You may have noticed [the change]. For what you were doing, is it: Better / Worse /
  Makes no difference to me / I'm not sure what it's for". Better and worse swap places for half
  of members. These keep Edward's "don't care" and "don't understand" options in kinder words.
- Coding: split each answer into statements tagged problem, value, suggestion or other; one
  theme per code; count respondents, not statements; each label carries a quoted span that a
  script checks against the answer; below about 100 answers list themes without ranking them.
- Cadence: at most one question per member per week; back off after two skips.
- Presentation: "of the N who answered", never "members think"; counts, not percentages, below
  about 100 answers; no margins of error on an opt-in sample.

### 3.7 Bandit allocation, not a fixed split (Edward: "statistical purity leads to extremely slow results")

Each hourly read re-weights the arms by Thompson sampling over their completion posteriors,
inside a band: control keeps at least 35% and no variant takes more than 65% (the adversarial
review showed a 10% floor starves the comparison: at 400 starts a day, control would get about
80 and nothing could be read). The read stops 45 minutes short of now (starts that have not had
time to finish would make the arm with more traffic look worse), and compares arms within
strata of six-hour block times logged in, weighted back together, so the moving split cannot
pass a change in who was arriving off as an effect (Simpson's paradox). Early stop on any tick
only once both arms have 300 starts: adopt at P(better) >= 0.99 with expected loss under 0.5
points, revert at P <= 0.02, and revert at once when a guardrail is worse by more than 2 points
with probability >= 0.97. At 24h: adopt at P(better) >= 0.80 AND expected loss under 0.5 points
with no guardrail at P(worse by more than 2 points) >= 0.90; revert at P <= 0.20; else
inconclusive and off. Every threshold is a setting. Each experiment records at start what a day
of its flow can show (the minimum detectable effect), so a reader sees when an idea expects
less than the day can tell.

### 3.8 Corpse control: no decay from failed experiments (Edward, 2026-10-14)

1. **Every switch is registered.** `iznik-nuxt3/ux-experiments.manifest.json` lists each live
   `useExperiment('<name>')` with the date it was opened and its PR. A vitest contract test
   greps the source for `useExperiment(` calls and fails CI when a name is not in the manifest,
   when a manifest entry has no call left in the code, or when an entry is more than 14 days
   old. A switch cannot be forgotten: it is removed, or the build is red.
2. **Cleanup is a loop step, not a chore.** When an experiment is decided, the next loop
   iteration opens the cleanup PR: adopted means the variant becomes the only code and the
   switch goes; reverted or inconclusive means the variant code and its tests go. The row gets
   `cleanup_pr_url` and status `closed`, and leaves the config key.
3. **Variants are local and deletable.** An experiment PR adds its variant as one `v-if` branch
   or one component used only by that branch, touching as few files as it can. It must be
   removable by deleting lines, never by rewriting what is around it. The cleanup PR is the
   inverse diff.
4. **History lives in the database, not the code.** What was tried, what it did and why it was
   taken off is in `ux_experiments` and `ux_ideas`; the idea generator reads that history, so a
   failed idea is not proposed again without a reason, and nothing in the code has to remember.
5. **Overdue cleanup is visible.** The tab lists adopted experiments not yet closed with their
   age, and the tick logs any older than 7 days, so a missed cleanup is seen within a day.

### 3.9 The loop (this session, hourly, `/loop`)

1. Health: rollup ran, health ratios ok, tick ran on live, any experiment stuck.
2. Measurement bugs: anything that looks like instrumentation becomes a fix PR on master.
3. Experiments (once the framework is deployed): cleanup PRs for decided experiments; for each
   free slot, take the top open idea, implement it behind the switch, push, wait for green, merge,
   wait for Netlify, start it.
4. Ideas: refresh the ranking from the latest figures and survey results.
5. Write the session log.

## 4. Ranked ideas (initial, from the 10-13 figures; re-ranked by the loop)

| # | Funnel | Idea | Why | Expected |
|---|---|---|---|---|
| 1 | give (mobile) | Photos step: make "skip photos, add later" visible above the fold, and say photos get more replies | 97 of 311 leave at photos on mobile; 77 navigate away | +5 to +8 points photos completion |
| 2 | ask (mobile) | Details step: title and description on one screen with the example filled in | 49 of 128 go back from details | fewer backs, +5 points |
| 3 | login | Signup pane: fewer fields first, say what happens next | 307 of 927 abandon the signup pane | +5 points signup completion |
| 4 | give/ask logged out | Ask to sign in before the flow starts on mobile when logged out, keeping the draft | 246 mobile give starts logged out, 14 submitted | fewer dead starts |
| 5 | login | Forgot pane: confirm the mail went and keep the modal open | 43 of 43 abandon (also an instrumentation gap) | measurable completion |
| 6 | give (desktop) | First step: shorter first screen (80 of 290 leave at step 1, p50 dwell 150s) | long dwell then leave | +4 points |
| 7 | reply | COMPOSING: show the "what happens next" line before the box | 56 navigate away while composing | +3 points |

## 5. Status

| # | Task | Status | Notes |
|---|---|---|---|
| 0 | Plan, session log, memory, research agent | ✅ | research in scratchpad/survey-research.md and the research-ai-ux-opt report |
| 1 | Worktree `ux-experiments` from origin/master, stack up, DB seeded | ✅ | stack up, guard switched, dev DB seeded with seed-v3.sh |
| 2 | Migration + raw SQL for the five tables | ✅ | |
| 3 | Laravel: `UxExperimentService` (Loki reads, stats, decision, config rebuild), `ux:experiments-tick`, `ux:experiment`, tests with `FakesUxLoki` | ✅ | plus `ux:prompt`, `UxPromptService`, `BetaBinomial`; 69 Ux tests green |
| 4 | Go: `/modtools/ux/experiments` read + write, `/config/ux_experiments` allow-list, `UxPrompt` challenge + response, tests | ✅ | Go suite 4824 green |
| 5 | Client: `useExperiment` reads live config (fetch + localStorage + env fallback), vitest | ✅ | 27 tests green |
| 6 | Client: `MicroVolunteeringUxPrompt.vue`, dispatcher wiring, vitest | ✅ | wave 3: four at a time with shown, optional least, reaction rotation, awareness reveal; 51 specs |
| 7 | ModTools: funnel revamp, experiments panel per funnel, survey panel, vitest + Playwright | ✅ | written; specs run in the build workflow |
| 8 | `ux:ideas-refresh` via the AI support helper (geeks token), helper endpoint + node:test | ✅ | end-to-end run on dev found error_max_turns: generator now reads the checkout as the backtest measured it, maxTurns 150 |
| 8b | Backtest of the idea generator: snapshots at 2026-04-14 and 2026-07-14, recall against later commits, improver loop, bar 25% | ✅ | v1 (16 truncated files, no reports): 3% to 5% over 3 prompt rewrites. v2 (code checkout + 446 reports/issues at the date, problems before solutions, 40 typed ideas): strict 32/208 = 15%, lenient 75/208 = 36%; 29 of 320 ideas matched a later change. Best on resilience (13/38) and flow (7/28); none on copy (0/23) or removals (0/10, e.g. voice post removed after an experiment the generator could not see). Held-out 2026-09-01 (never used for tuning, 4 flows, 24 later changes): strict 4/24 = 17%, lenient 11/24 = 46%, consistent with the tuning set, so the generator generalises. The 25% bar is met on the lenient standard (same member problem, same place) and not on the strict one (essentially the same fix). |
| 9 | Docs: `ux-telemetry.md` sections for experiments and prompts | ✅ | ux-telemetry.md and ai-support-helper.md follow wave 3 |
| 10 | PR for Edward's review (NOT merged by this session), CI green. Body carries screenshots of the revamped tab and the microvolunteer prompt, plus seeded example experiments and prompts (ideas 1 to 5 as draft rows, one survey prompt) so the examples are real rows in the screenshots | 🔄 | #1779 CLOSED 2026-10-15, replaced by: F = #1786 (tab, green); E1 = #1787 (experiments engine, green, base F); S1 = #1790 (surveys engine + card, green, base F); E2 = #1791 (experiments section, green, base E1); S2 = #1792 (What members say, base S1, green); I = #1794 (ideas, base E1, ready 16 Oct 11:30Z, head 0d1fd9f4ef, screenshots on pr-assets/ux-ideas); E3 (email, notification and behind-the-scenes experiments) being designed; R later. All carry Blocked; merge order in every body |
| 11 | First experiments (ideas 1 to 5), one PR each behind a switch, ready to start once the framework is live | 🔄 | #1780 #1781 #1782 rebased onto E1 (feat/ux-experiments-engine), bases retargeted 2026-10-15; CI green. 16 Oct (Edward): all experiment PRs carry the `Blocked` label; MERGE ORDER written into each body: #1786 and #1787 live first, then #1780, #1781, #1782, then #1767 (reworked); each is rebased after the one before it (shared manifest) and Edward removes `Blocked` when it is next |
| 12 | Hourly loop live | ✅ | FSM 2H RUN DONE 16 Oct 15:38-17:38Z: 6 laps, PRs #1804 (rippling duplicate fix, green) and #1805 (Go coverage), 3 Discourse answers, 1 reporter asked for detail; two FSM defects found (router re-offers a retest post on a needs-detail topic; a [skip ci] docs commit with cancelled statuses reads as master FAILING), fix PRs to follow; #1802's guards held. Earlier: hourly cron STOPPED by Edward 2026-10-15 07:35Z. QUEUED (Edward 08:30Z): once PRs F, E, S, I are green, run monitor-fsm for up to 2 hours (Node 22 on PATH, judge from /tmp/freegle-monitor/debug.log). PRE-FLIGHT DONE 16 Oct 13:55Z: the coverage booster would have pushed coverage commits to Blocked PRs red only on Coveralls (#1791 #1794 #1781) -> PR #1802 (fix/monitor-blocked-pr-coverage, for Edward; the main checkout working tree carries the 4 files for the run, restore after); monitor-fsm deps were stale (ai-flower June vs lock 2 Oct) -> npm ci, suite 635 green; main stack dev-local and playwright started; wrapper scratchpad/fsm-2h.sh (own process group, TERM then KILL at 2 h). STARTED 16 Oct 15:40Z (task bxfuqogz6, ends by 17:40Z): F E1 S1 E2 S2 I green, master green, E3 workflow done (its CI pending), ux stack stopped |
| 13 | Measurement bug fixes found on the way (photos revisit artefact, forgot pane completion) | ✅ | other session fixed the photo-count artefact and final-step completion (522aeb6c6); generator-found offer defects PR #1778 MERGED 12:52Z (a0e36982d); health check route anchoring PR #1783 MERGED 13:28Z (9aae696ad); 13 Oct live rollup rerun by hand |
| 15 | Idea farming: open questions, theme analysis via the helper, follow-up ranking, `ux_problems`, problem-linked ideas, tab sections, survey-wording review | ✅ | analyse ran end to end on dev with the real model: 105 answers, 38 themes, 27 filed with checked quotes |
| 17 | Wave 3: survey method from the research (wording, 4-of-N ranking with optional least, reaction labels and rotation, awareness reveal and decoy, coding with checked quote spans, weekly cadence, counts below 100) | ✅ | wave 3 wf_427e2816-eba, 4 review findings fixed |
| 16 | Decision rule rework from the adversarial review and research: 35/65 band, settle lag, stratified posterior, expected loss, margins, MDE at start, per-session frozen arms in the client | ✅ | service rewritten; tests and client next |
| 14 | Corpse control: switch manifest + vitest contract test, cleanup-overdue flag in the tick and on the tab, docs rule for local deletable variants | ✅ | docs rule in the build workflow |
| 18 | #1767 (confirm a reply to a damaged or bulky offer, Edward's draft) reworked as an experiment under E1 (Edward 16 Oct: "rework to be an experiment framework too") | 🔄 | 16 Oct 13:30Z: #1767 now carries `Blocked` and its body names its place (last of the UX PRs, becomes a server-decided experiment on #1801). SPEC DONE 16 Oct: scratchpad/spec/confirm-reply-experiment.md (248 lines, critic-checked). Finding: E1 is browser-only end to end (crc32 bucket in the browser, `variant` on flow events, Loki flow_complete/flow_start, 24 h); a server experiment needs PR-A (server-side twin of the bucket in Go and PHP, a `ux_experiment_exposures` table, a database metric source consumed by the decision step) then PR-B (#1767 on E2 with the arm check at replyconfirm.go:166-168, panel folded into E2's detail, five conflict files with F rebased). Seven decisions for Edward (section 8): run length 14 days vs the 24 h rule; what counts as a win (handovers vs "no wasted reply"); the never-delivered margin; one PR or two; it takes the `reply` funnel for 14 days; old app builds cannot answer; arm per replier vs per post. PR-A is the same mechanism E3 needs: reconcile with the E3 spec, then build E3/PR-A first, PR-B after. Manifest note: the three experiment entries dated 2026-10-14 fail vitest from 2026-11-14 if still unmerged. 16 Oct 20:50Z: PR-A is E3 (#1801, built); PR-B spec being reconciled with E3/X1/X2 as built (wf_5f4d4c68-8f3 -> spec/CR-confirm-reply-build.md); PR-B builds after X2 on X2's tip, keeps #1767's number and branch, last in the merge order 16 Oct 21:35Z: SPEC DONE (wf_5f4d4c68-8f3, 4 agents, 2.2M tokens, 120 min): scratchpad/spec/CR-confirm-reply-build.md, 1,849 lines, 26 critic findings all applied (one high: the hourly tick rebuilds the ux_experiments config row, so the fixture seeds a table row too; a re-sent reply answering its own question is now split out as sent_twice; both foreign keys plus a userid key in the ALTER; the old head is pushed as tag ux-old-CR before the lease-pinned force push). BUILD LAUNCHED 21:45Z (wf_9e6732cb-973, task wibf3kat1) in the ux worktree: #1767 keeps its number and branch, rebased onto X2's tip 26073fda80 (tag ux-base-CR), PR base ux-exp/push-posts-title, draft, Blocked, 52 files, title "Ask before passing on a reply to a damaged or bulky offer, as an experiment". Defaults chosen for Edward (in Choices made for you): primary `no_wasted_reply` (his own PR note says the win is fewer wasted replies) with `handover` as the one-word alternative; its own slot server:confirm-reply; never_delivered 50 points; arm per replier; old app builds and the held-reply counters accepted; 14 days; SQL written for a small chat_prompts with the row-count question as the first line under Production SQL |
| 19 | WhatJobs: stop sending mail scanners to the partner without a click for real people (Edward 16 Oct: "that is a better fix. do that if your analysis is ok") | ✅ | MERGED 16 Oct 13:45Z (d8d874614e, every check green). Frontend auto-deploys and is inert until the Go API returns autoopen; Edward sets `jobs_email_gate` to shadow BEFORE the Go deploy (absent = on), reads a day of job_gate lines, then on. spec scratchpad/spec/jobgate.md: GET /apiv2/job/:id returns autoopen=false for non-GB or hosting-ASN callers unless signed in (GeoLite2-ASN embedded; config switch jobs_email_gate on/off/force); page shows a card with an Open button. Analysis held (two refuters + critic). BUILT: PR #1800 (fix/job-email-page-scanner-gate, 36 files; GET /apiv2/job/:id returns autoopen per caller from X-Real-IP, GeoLite2 country and ASN, login and the browser's time zone; Microsoft-only on UK addresses; first tap remembered; switch jobs_email_gate on/off/shadow/force; decisions logged to Loki source=job_gate; GeoLite2-Country refreshed). Deploy: frontend first, set the row to shadow before the apiv2 deploy, read a day, then on. Measurement fixes MERGED 16 Oct: #1793 keepalive click log, #1798 mail job links tagged, #1799 HEAD probes not counted |
| 21 | Concern-led, not only flow-led (Edward 16 Oct: "not just funnels and drop-offs ... if we ask members what they care about, they will say no shows ... ripple that through to different options ... Not all problems are soluble"): the idea generator must start from member concerns as well as flow figures, propose server-side interventions (who a poster is shown first, confirm steps, reminders) with measures at the level of the concern (promised items collected), and be able to record a concern as "not soluble here" | ⬜ | E3 design (wf_728ba8eb-335, resumed with this framing) covers the engine side; the generator prompt change is a small follow-up PR after #1794 (I) 16 Oct 21:25Z: SPEC IN PROGRESS (wf_0f7fde83-0c0, task wi537m2s2): I2 `ux-exp/concern-led-ideas`, spec scratchpad/spec/I2-concern-led-ideas-build.md, written beside E3b and NR; merge position 14, base feat/ux-ideas if the concerns read can be tolerant of a missing table 17 Oct 00:15Z: I2 SPEC DONE (wf_0f7fde83-0c0): scratchpad/spec/I2-concern-led-ideas-build.md, 2,011 lines, 36 findings applied, 0 refuted. Base feat/ux-ideas (#1794), branch ux-exp/concern-led-ideas, no SQL (six JSON keys in ux_ideas.evidence), the prompt names the two evidence sources and asks for server options with a concern-level measure, the batch enforces the rules whatever the model says, backtest gains a real server-side case (commit 1fd001d16, the first reply work), 56 new tests, 36 files; 11 questions with defaults (the generator files nothing; 3 options per concern; noshow-order options carry a needs_check note). BUILD after NR in the ux worktree |
| 22 | E3: experiments beyond the web (Edward 16 Oct: "ux experiments should include email and notification experiments not just web") | 🔄 | design done 16 Oct: scratchpad/spec/E3-experiments-beyond-web.md (critic-checked); merged with the #1767 spec's engine part into spec/E3-build.md (198 lines); BUILT 16 Oct (wf_d4bfb55a-b9d): #1801 READY FOR REVIEW at 15:40Z, head 43834eeb25, base #1791 (E2, which it edits), Blocked, merge position 7; CI green on both the pre-fix head and the fixed head (pipeline 13071); review fixes: tableExists() on the write node, salt "0", Counted not Sent, one-decimal rates, channel + funnel per row; screenshots on pr-assets/ux-experiments-server; builder result + decisions in scratchpad/e3-build-result.json and e3-fix-result.json. Follow-ons: three small email/push experiment PRs, E3b (concerns table + no-shows options), PR-B (#1767). Nine decisions for Edward in spec/E3-decisions.md; defaults built 16 Oct 21:25Z: E3b SPEC IN PROGRESS (same workflow): scratchpad/spec/E3b-concerns-build.md (ux_concerns, UxConcernService::settle, ux:concern, PromiseKeptSource, Go concerns field, Concerns block; branch ux-exp/concerns on CR's tip, merge position 12) and NR-noshow-remind-build.md (ux-exp/noshow-remind on E3b, position 13); noshow-order not built (product and privacy check first) 17 Oct 00:15Z: E3b AND NR SPECS DONE (same workflow, 13 agents, 8.66M tokens, 157 min, aligned): E3b-concerns-build.md 3,038 lines (20 findings, 3 part-refuted; 35 files; branch ux-exp/concerns on CR's tip; engine holds every slot of an option; reopen of an improved concern adds options only; 142 PHP, 4 Go, 28 Vitest, 1 Playwright) and NR-noshow-remind-build.md 1,268 lines (33 findings; 12 batch-only files; chats:remind-promised hourly at :37, promises 20 to 34 h old, 08:00-21:00 London, one locked transaction per promise, 104 tests). Unsettled for the E3b builder: CR's ux-experiments.md sentence "start checks the batch list only for mail and push" must gain "and the options of a concern". BUILD ORDER after CR: E3b, NR, I2, each in the ux worktree on the previous tip |
| 23 | X1 `digest-subject`: the first email experiment (daily digest subject names the items), decided on the server, counted by `email_click` | ✅ | BUILT 16 Oct 19:30Z (wf_a02816d6-e65): draft PR #1807 "UX experiment: lead the daily digest subject with the items", branch ux-exp/digest-subject from E3's cfe33650d, base feat/ux-experiments-server, Blocked, head dc71cebfc6 (6 files, +1,274 -4; 56 new PHP tests, 37 red on the old code, 18 mutations all caught); screenshots on pr-assets/ux-exp-digest-subject; reviewer table comment 6223157178; CI build-and-test queued behind the cascade. Choices made for Edward in the body: footer-link unsubscribe guardrail; items arm only for digests of 2+ posts (a one-post digest is out of the experiment, no row); Yahoo dilution accepted; expected_points 0.5; sizing SQL in the body, not run; `opened` 2026-10-20 (change if the PR waits). Builder found the spec wrong on one point: mail:test rolls back, so a preview writes no exposure row. SPEC: scratchpad/spec/X1-digest-subject-build.md |
| 24 | X2 `push-posts-title`: the first push experiment (daily new-posts push title leads with an item), counted by `active_after` | ✅ | BUILT 16 Oct 20:55Z (wf_c44f0889-126, 6 agents, 2.75M tokens, 82 min): draft PR #1808 "UX experiment: the daily posts push names its first item", branch ux-exp/push-posts-title cut from X1's tip dc71cebfc6 (tag ux-base-X2), PR base ux-exp/digest-subject (retargets when #1807 merges), Blocked, head 26073fda80 (4 commits; exactly five files, +1,101 -7: PushNotificationService.php +85 -2, config/ux_switches.php +11, new tests/Feature/Ux/PushPostsTitleTest.php with 61 cases, ux-experiments.md, laravel-batch-traps.md). Tests: 38 of 61 red on X1's tip, 61/61 green, wide PHP filter 584/584, 19 deliberate mistakes all caught, 25/25 added statements covered; dev-stack dry run gave "Sofa and 1 more near you" for a lead member and "2 new things near you" for control. Review: security 0, independence 0, words 4 confirmed and fixed (body cut from 2,843 to 1,975 words; docs/rule/comment said only the lead arm builds the title, now both arms build it once; cleanup bullet split by outcome). Body: Summary, How to try it, Sizing (queries A-C in a details block, adoptPower table, rule: no start under 0.30, 0.30-0.50 Edward's call), Test plan, Production SQL None, To run it (operator checks FREEGLE_POSTS_PUSH_ALLOWLIST=* and sets ux_server_arms=1 first, start window 13:00-06:30 London), To remove it, For review (Wanted may lead; 20 column cap unchecked on a device; HTML entity bug in the single-post title named, not fixed), Choices made for you (`opened` 2026-10-20), Merge order verbatim. Reviewer table is comment 1. Not done: live sizing SQL, EXPLAIN on prod, a real push. CI: pipeline 13098 build-and-test GREEN 21:35Z (Coveralls playwright gap only; X2 touches no site code). SPEC: scratchpad/spec/X2-push-posts-title-build.md |
| 20 | CI red everywhere from 16 Oct morning: the Rust zip crate yanked 5.0 to 9.0.2, the batch image's MJML extension could not resolve | ✅ | PR #1795 keeps the upstream Cargo.lock; MERGED 16 Oct 08:32Z; the open branches were updated through the cascade that evening |

## Wave 4: plain simple English (2026-10-14, after Edward's review)

Edward: "'to hand' not plain simple English", "Your feature example asking about promise is a tip, not a question", "Shorter. 'No photo? Carry on'".

- Every word members see is plain and short, with no idioms. Problems question: "Think about the last time you used Freegle. Did anything cause you a problem?" ("No problems"). Follow-up: "Which of these has been the biggest problem for you in the last few months?" ("Biggest problem for me", "Smallest problem for me (optional)", "None of these has been a problem for me"). Reaction: "Is this change better or worse for you?".
- Awareness questions ask "Do you know how to <action>?" and show nothing afterwards. The reveal is optional and only a decoy must have one (it corrects the member). A link needs a reveal. The ideas brief finds awareness answers by the decoy flag.
- Experiments: "No photo? Carry on" (#1780), "Your message goes to <name> as a chat on Freegle. They usually get an email too." (#1781), "Joining is free. You'll be signed in straight away and stay on this page." (#1782).
- Commits 36a32482c and 49d6306d6 on feat/ux-experiments; all suites green locally (see the PR test plan).

---

## The five experiments proposed on 2026-10-24 (merged here 2026-10-28)

Edward: "review pages and filters, come up with 5 more experiments". Design only; no code yet.
Each is one PR, one switch, one small change, off by a config row. All are web (`useExperiment` /
`flowArm`); nothing is switched in the app build (app members are control anyway). Each PR also adds its
`ux-experiments.manifest.json` entry, and carries the `Blocked` label.

### Where the numbers come from

- Rollup tables `ux_flow_daily`, `ux_flow_outcome_daily`, `ux_field_daily`, `ux_component_daily`, live DB, read-only.
  The rollup only starts on 15 Oct, so the window is **15 to 23 Oct (9 days)**, control arm only (`variant` has no `:b`).
- Filters used: device (`mobile`, `tablet`, `desktop`, `app-android`), `logged_in`. `logged_in` is the state at send time, so
  it is only used for step visits, never for outcomes.
- The experiments table: what already ran, so none of it is repeated. Read at 24 h, volumes counted by the engine
  (after the 45-minute settle cut): login about 1,190 starts, give about 630, reply about 435 a day.
- Prod Loki tunnel (:3102) was down, so `error_code` splits of failed submits were not available; where an experiment
  needs one, the first task of the builder is to log it (no new column; `flow_submit_result.error_code` already exists).

### Power, said plainly

At 24 h the engine can see roughly: login 5-6 points, give/reply/ask/outcome 7-8 points (at 300-600 per arm). 
the wording changes of the last week read -1.1, +2.1 and -5.1 points (all inconclusive or reverted). So these five are chosen
because each removes a **requirement or a screen** rather than changing words, and the largest leaks first. Expect
`inconclusive` for the smaller ones at 24 h; Edward can allow 48 h (`max_hours`) for those.
One experiment per funnel: **reply is held by `confirm-reply` (running, 0 counted) and ask by `ask-photos-carry-on`**, so
experiments 2 and 5 start only when those are stopped. 1, 3 and 4 can start now (login, give, chat_outcome), with
`max_concurrent` 5.

### What the data says (the slices that differ)

| Slice | Finding |
|---|---|
| Give, finish rate by device | phone web 40.8% of starts, desktop 61.0%, Android app 60.0%, tablet 49.4%. The whole gap is the first phone step. |
| Give, photo step (phone web) | 3,321 visits, 57.9% go on, 990 quit. Android app 68.5%, 425 of 1,575 quit. Already tried twice in words (inconclusive, -1.1). |
| Give, later phone steps | details 73% on and 378 of 2,359 go back; options 81% on, 233 back; where 71% on, 260 back. Desktop's same three questions are one page: 92 to 96% on at each. |
| Give options step | 637 deadline toggles and 329 delivery toggles on 2,146 phone visits: the screen is used, but only one in three touches it. |
| Ask, step 2 (phone web) | `ask-mobile-details` 1,235 visits, 48.7% go on, **450 (36%) go back**, against give's 16% on the same screen type. Signed in: 63% on, 33% back. Ask finishes 22% of starts (phone web), 25% over all devices. |
| Ask details rule | with no real photo (AI picture does not count) the description box is **required**; skipping the photo, now the main way in, lands on that wall. |
| Login | 17% of starts had a failed submit (2,161 failed in 9 days: phone web 31% of submits, app 31%, desktop 20%). Signup pane: desktop 3,774 visits, **43% quit** (1,614), phone web 21%, app 22%. Join form "incomplete" 277 times, sign-in 309. The previous join line lost 5.1 points, so this funnel moves a lot. |
| Reply | Sending bounces off validation 20.1% of the time on phone web (639 of 3,175), 23.5% on the app, 15% on desktop. `ChatReplyPane` has 2,685 repeat taps with no request after, 18% of its clicks. Two boxes are required for an offer: message and "When could you collect?". |
| Chat outcome (mark as taken) | 4,444 starts, 81% finish; "who took it" missing is the validation failure 609 times (14% of starts: phone 261, desktop 237, app 111). 16% quit. The list starts on "-- Please choose --" even when the member has promised the item to one person. |
| Dead-click leaders | `ChatMessageText` 3,831 of 5,331 clicks; `PinchMe` (map hint) by design; these are not funnel leaks and were left out. |

Done before and so not repeated: give-photos-carry-on, ask-photos-carry-on, reply-what-happens-next, join-what-happens-next,
push-posts-title, digest-subject, reply-job-interstitial, confirm-reply, interested-button, mobile-web-app-interstitial.

---

### Ranking (expected effect on the flow's finish rate)

| # | Name | Flow | Expected points |
|---|---|---|---|
| 1 | `join-name-optional` | login | +5 |
| 2 | `ask-description-optional` | ask | +4 (flow level; starts after `ask-photos-carry-on`) |
| 3 | `give-skip-options-screen` | give | +3 |
| 4 | `outcome-taker-preselect` | chat_outcome | +3 |
| 5 | `reply-collect-chips` | reply | +3 (starts after `confirm-reply`) |

---

### 1. `join-name-optional` (login)

- **Page / step:** `components/LoginModal.vue`, the `signup` pane (step `signup`), field "Your name".
- **Problem:** the signup pane is where the login funnel leaks. Desktop 3,774 visits, 43% quit; phone web 4,383 visits, 21%
  quit; app 22%. "Form incomplete" fired 277 times in the signup pane. The pane asks four things (name, email, password,
  marketing tick). Name is the only one the member does not need to join.
- **Hypothesis:** dropping the name box removes one demand and one place to stall, so more people finish joining.
- **Change:** arm `b` hides the "Your name" `b-form-group` when `signUp` is true. Signup sends no `displayname`. The server
  already accepts an empty name (`PutUser` in `iznik-server-go/user/user.go` builds `fullname` from the parts, empty is
  allowed) and an empty name is shown as "A freegler". Control is unchanged. No new copy.
  Trap: `loginNative` treats "no name, email and password" as `confused` (a sign-in typed on the join pane). Arm `b` must
  not take that route: when the box is hidden, `confused` is false on the signup pane.
  The signup then shows the "Your name" prompt later at first need (existing profile prompts); do not add one here.
- **Primary event:** `flow_complete` per `flow_start` for flow `login` (default). Base rate about 45% (44% to 49% in the
  engine's own reads). Guardrails: `flow_submit_result` failure share (the engine already reads it).
- **Expected points:** +5. Basis: signup-pane quits (2,725 over the three device groups) are 22% of all 12,280 login starts, though many are people only looking; capturing
  half of the quits that name causes is about 5.
- **Measured:** the hourly read, login funnel, strata by 6 h block times signed-in-at-start. About 600 starts an arm in 24 h.
  Extra check by hand: the share of new accounts with an empty name (`users.fullname` empty) in each arm, and
  `users_emails` for the same new members, so moderators are not handed a flood of nameless members.
- **Files:** `iznik-nuxt3/components/LoginModal.vue` (arm read via `flowArm(FlowName.LOGIN, 'join-name-optional')` after mount,
  hide field, skip `confused`), `iznik-nuxt3/ux-experiments.manifest.json`, a vitest in
  `iznik-nuxt3/tests/unit/components/LoginModal*.spec.js`, a Playwright check beside `tests/e2e/test-ux-telemetry.spec.js`.
  Docs: `docs/developers/reference/ux-experiments.md` only if the live-experiments list is kept there.
- **Screenshot:** the login modal open on the Join pane, control beside arm `b`. Phone 390x844: control shows name, email,
  password, tick, "Join Freegle!"; arm `b` shows email, password, tick, button, with the button higher on the screen.
  Desktop: same two panes side by side.

### 2. `ask-description-optional` (ask)

- **Page / step:** `pages/ask/mobile/details.vue`, step `ask-mobile-details`; `validateAndNext()`.
- **Problem:** the phone ask funnel finishes 22% of starts and step 2 is the worst screen in the site after the photo steps:
  1,235 visits, 48.7% go on, 36% go back (450). Give's equivalent screen has 16% back. Signed-in members: 63% on, 33% back;
  signed out: 32% on, 43% back. The rule in `validateAndNext`: no real photo (an AI picture does not count) means a
  description is required. Skipping the photo is the main way into this page, so the member skips one box and meets another.
- **Hypothesis:** a wanted post is already described by its title ("Kids bike for 8 year old"), so asking again is a wall.
  Letting the title stand as the description removes the wall and more members get through step 2.
- **Change:** arm `b`: when there is no description and no real photo, `validateAndNext` does not stop; it sets the body to
  the item name and goes on. The description box stays visible and optional. Control is unchanged. No new copy.
  Quality guardrail for Edward: the share of Wanted posts where body equals title in each arm; if moderators dislike thin
  posts it is revertible in an hour.
- **Primary event:** `flow_complete` per `flow_start`, flow `ask`. Base rate about 25% (all devices), 22% phone web.
  Guardrails: `touches`: `ask`, `login`.
- **Expected points:** +4 at flow level. Basis: step 2 would move from 49% to about 60% on (+136 members of 1,235), of whom
  63% finish: +86 on 2,017 phone starts = +4 points; diluted by desktop where the rule does not apply (a one-page form) so
  set `expected_points` 3 for the power check.
- **Measured:** hourly read of flow `ask`; after the run, count Wanted posts whose body equals the title per arm
  (Loki `give_posted` has a sibling only for offers; use `messages.textbody = subject-item` from the DB, per arm window).
- **Files:** `iznik-nuxt3/pages/ask/mobile/details.vue` (`flowArm(FlowName.ASK, 'ask-description-optional')` read after
  mount like `pages/ask/mobile/photos.vue` does for carry-on), the Wanted compose-store validation if it also checks the body
  at submit (`iznik-nuxt3/stores/compose.js`, check before building), manifest, vitest `tests/unit/pages/ask/mobile/`.
  **Starts only when `ask-photos-carry-on` is stopped** (one per funnel, and both change the no-photo path).
- **Screenshot:** `/ask/mobile/details` with an item typed, no photo, description empty, Next just pressed. Phone 390x844:
  control shows the red "describe" error under the box; arm `b` shows the next page (`ask-mobile-whereami`). Desktop: the
  desktop ask page is a single form and the rule is unchanged, so only a phone shot is needed; add a 1280x800 shot of the
  desktop page proving no change.

### 3. `give-skip-options-screen` (give)

- **Page / step:** `pages/give/mobile/details.vue` line ~362 (`router.push('/give/mobile/options')`), step
  `give-mobile-options` (rank 3).
- **Problem:** a phone offer is four screens and desktop is one page. Desktop's last three questions each keep 92 to 96%;
  phone's keep 73%, 81%, 71%, with 871 "back" taps across them in 9 days against 168 on desktop. The options screen asks two
  optional questions (deliver? deadline?): 2,146 visits, 233 back, 79 quit, and only 637 + 329 toggles on those visits.
- **Hypothesis:** a screen for two optional questions costs more than it earns; with it gone, fewer phone members stall
  between "what is it" and "where are you".
- **Change:** arm `b`: details "Next" goes straight to `/give/mobile/whereami`. Defaults stay as the compose store has them
  (collection only, no deadline). On the whereami page a one-line link "Delivery or a deadline?" opens the existing options
  page, whose `goNext` already goes to whereami. Control unchanged. Copy: "Delivery or a deadline?" (4 words).
  Flow telemetry needs nothing: the router hook sees rank 2 to rank 4 as a normal step complete.
- **Primary event:** `flow_complete` per `flow_start`, flow `give`. Base rate: about 51% rollup, 60% in the engine's read
  (counts web only, settled starts). Secondary, read by hand: share of offers with a deadline or delivery set per arm.
- **Expected points:** +3 (flow level). Phone web is 60% of web give starts; a phone gain of 5 is 3 overall.
- **Measured:** hourly read of flow `give`; guardrail `login`; `give_posted` `with_photo` share per arm (the engine does not
  read it), plus deadline/delivery use per arm from `messages` after the run. A deadline-use drop of more than a third is a
  reason not to adopt.
- **Files:** `iznik-nuxt3/pages/give/mobile/details.vue` (`flowArm(FlowName.GIVE, 'give-skip-options-screen')`),
  `iznik-nuxt3/pages/give/mobile/whereami.vue` (the link, arm `b` only), manifest, vitest for both pages,
  `tests/e2e` give phone path.
- **Screenshot:** phone 390x844 only (the arm is phone-only): details page "Next" tapped, then the whereami page in arm `b`
  showing the "Delivery or a deadline?" link under the postcode box; beside it control's options screen. Desktop 1280x800:
  the give page unchanged (proves it is phone only).

### 4. `outcome-taker-preselect` (chat_outcome)

- **Page / step:** `components/OutcomeBy.vue` (inside `components/OutcomeModal.vue`), step `choose`.
- **Problem:** "Mark as TAKEN" fails validation on "who took it" 609 times in 9 days (phone web 261, desktop 237, app 111),
  14% of starts; 16% of starts quit; finish rate 81% (phone 79.8%, desktop 84.5%). The select starts on
  "-- Please choose --" even when the member has already promised the item to one person (`message.promises`) or only one
  person replied.
- **Hypothesis:** if the member promised it to one person, that person took it, so choosing for them removes the failure.
- **Change:** arm `b`: when the type is `Taken`, the post is not a bulk post and `message.promises` has exactly one user
  (else exactly one replier), that user starts in `currentlySelectedUsers` (same shape as `takenBy`). The member can remove
  them with the existing x button (`removeTaker`) or add another. Control unchanged. No new copy.
- **Primary event:** `flow_complete` per `flow_start`, flow `chat_outcome`. Base rate 81%. Secondary, from the same events:
  `validation_failed` field `taker` per start (14% now; the point of the change).
- **Expected points:** +3 on the finish rate. Basis: 14% hit the failure; about 1 in 3 of those has a single promise and
  half of those would have quit.
- **Measured:** hourly read; the engine's "help sought / validation per start" watch line carries the taker failures.
  Guardrail by hand: the share of marked-taken posts where the taker was changed from the preselected one, from
  `messages_by`. A high change rate means the guess is wrong and the arm should be reverted.
- **Files:** `iznik-nuxt3/components/OutcomeBy.vue` (`initiallySelectedUsers`, read arm with `flowArm(FlowName.CHAT_OUTCOME,
  'outcome-taker-preselect')`), manifest, vitest `tests/unit/components/OutcomeBy*.spec.js`.
  Trap: `OutcomeBy` is mounted with top-level `await`; read the arm after mount, not in setup, so SSR and hydration match
  (see `.claude/rules/frontend-traps.md`, same as the carry-on experiments).
- **Screenshot:** chat page with an offer promised to one member, open "Mark as TAKEN". Phone 390x844: control shows the
  select on "-- Please choose --" in red after a press; arm `b` shows that member's name already in the list with the x.
  Desktop 1280x800: same two states in the modal.

### 5. `reply-collect-chips` (reply)

- **Page / step:** `components/ChatReplyPane.vue`, "When could you collect?" field (Offers only), state `VALIDATING` /
  `COMPOSING` in `composables/useReplyStateMachine.js`.
- **Problem:** Send bounces on validation 20.1% of the time on phone web (639 of 3,175 passes through `VALIDATING`), 23.5% on the
  app, 15% on desktop; field-level count 1,231 in 9 days. `ChatReplyPane` shows 2,685 repeat taps with no request after
  (18% of its 14,840 clicks), i.e. members pressing Send again. For an offer there are two required boxes, and the second
  (collection time) is a free-text box on a phone.
- **Hypothesis:** tapping an answer is easier than typing one, so fewer members hit the empty-box error.
- **Change:** arm `b`: under the collect box, three tap buttons that fill the box: "This weekend", "Weekday evenings", "Any
  time". The box stays editable. Control unchanged. Copy as quoted (2 to 3 words each).
- **Primary event:** `flow_complete` per `flow_start`, flow `reply`. Base rate about 75% (74% to 76% in the engine's reads).
  Secondary: `validation_failed` per start.
- **Expected points:** +3. Basis: bounce rate 20%, perhaps a third caused by the collect box, a third of those never return.
- **Measured:** hourly read of flow `reply`; touches `reply`, `login`. By hand: the share of replies whose collect text
  equals a chip, to see whether members use them.
- **Files:** `iznik-nuxt3/components/ChatReplyPane.vue` (arm via `flowArm(FlowName.REPLY, 'reply-collect-chips')`),
  manifest, vitest `tests/unit/components/ChatReplyPane*.spec.js`.
  **Starts only when `confirm-reply` is stopped** (one per funnel). It also cannot run with `reply-job-interstitial`'s cleanup
  still in the code; check that is gone first.
- **Screenshot:** an offer's reply pane, collect box empty. Phone 390x844: control box alone; arm `b` box with the three
  buttons under it, one tapped and the box filled. Desktop 1280x800: same.

### Order to start

1. `join-name-optional` (login), `give-skip-options-screen` (give) and `outcome-taker-preselect` (chat_outcome) can all run
   together now (three funnels, under the cap of five).
2. `ask-description-optional` when `ask-photos-carry-on` stops; `reply-collect-chips` when `confirm-reply` stops.
3. Not proposed, because the data does not support a small change: forgot-password (99% quit is by design, it sends a mail),
   chat promise (the "maybe" warning tells members to press Cancel, so quits are intended), settings (the funnel only starts
   at the first save).

