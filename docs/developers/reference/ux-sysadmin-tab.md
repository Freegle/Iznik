---
last_reviewed: 2026-10-25
owner: Freegle dev team
covers:
  - iznik-nuxt3/modtools/pages/sysadmin/index.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUx.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxNav.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxFilters.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxOverview.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxFunnelTable.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxFunnelPicker.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxFunnels.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxFunnelChart.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxPages.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxPanel.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxHealth.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxFriction.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxAdoption.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxRegret.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxSearch.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxSecondThoughts.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxHelp.vue
  - iznik-nuxt3/modtools/components/ModSysAdminUxSamples.vue
  - iznik-nuxt3/modtools/components/ModEmailDateFilter.vue
  - iznik-nuxt3/modtools/composables/useUxView.js
  - iznik-nuxt3/modtools/composables/useUxSections.js
  - iznik-nuxt3/modtools/composables/useUxSlot.js
  - iznik-nuxt3/modtools/composables/useUxFlows.js
  - iznik-nuxt3/modtools/composables/useUxFunnel.js
  - iznik-nuxt3/modtools/composables/useUxThenFinished.js
  - iznik-nuxt3/modtools/composables/useUxFormat.js
  - iznik-nuxt3/modtools/stores/ux.js
  - iznik-server-go/ux/filters.go
  - iznik-nuxt3/tests/e2e/test-modtools-sysadmin-ux.spec.js
---

# The SysAdmin UX tab

The **UX** sub-tab of ModTools SysAdmin (Behaviour, then UX) is where support staff read the
telemetry described in [UX telemetry](ux-telemetry.md): where members go back, stop, give up or
never find a feature. That page is the contract for the events, the rollup tables and the read
API. This page is about the tab: how it is laid out, what the address holds, what the filters do,
and how to read it. The code is the source of truth; this page points into it.

UX is the first and default sub-tab of Behaviour (then Scrolling, then Reengagement), so opening
Behaviour shows it. `?tab=behaviour` and `?tab=ux` land on UX; `?tab=scrolling`, `digest` and
`recommendations` land on Scrolling; `?tab=reengagement` lands on Reengagement. The red number on
the Behaviour tab (experiments with a verdict nobody has closed) is repeated on the UX sub-tab, and
a line at the top of the sub-tab says in words what it is waiting for and links to the Experiments
section. The page is `iznik-nuxt3/modtools/pages/sysadmin/index.vue`.

## What it shows

The tab used to be eight panels in one column, 15,000 pixels tall. It is now a row of sections
under the tab's title, and each section is short. A section drills down: rows are closed until
opened, one funnel is open at a time, and a link goes to a funnel, a step or a panel.

## The sections

| Key | Label | What it shows | Filters |
|---|---|---|---|
| `overview` | Overview | A closed Data health row, then one row for each of the seven funnels: started, finished, finished before, and the biggest loss. The funnel name opens that funnel and the biggest loss opens it at that step. | Period, Device |
| `funnels` | Funnels | A picker of the seven funnels, always all seven. The open funnel has its headline, a line of outcome figures, one chart for each page family, the step detail, and closed rows for Second thoughts and Help for that funnel only. | Period, Device, Signed in |
| `experiments` | Experiments | The experiments in one list, running first, with a form to make one by hand and a plain read-out of how a running one is doing. One is open at a time. It has its own routes and store, and the tab says its filters do not apply. See [ux-experiments.md](ux-experiments.md). | None |
| `members` | What members say | The problems members raised, in groups that are closed until opened, with the biggest problem of each; what works for members; and each question that was asked. It has its own routes and store, so the tab says its period, device and sign-in filters do not apply. Its own funnel select and search box are on the Problems view. See [ux-member-surveys.md](ux-member-surveys.md). | None |
| `pages` | Pages and features | Friction, Adoption, Regret, and Search and browse, closed, one open at a time. They are not about one funnel. | Period, Device |

On a phone the row of sections is wider than the room and scrolls sideways. `ModSysAdminUxNav.vue` moves
the row, and not the page, by just enough to bring the open section into view when the tab opens and when
the section changes, and again when a link changes width (a badge such as the count of experiments with a verdict
loads after the row is drawn), so the open section's name is never cut off or out of sight. The browser test for
What members say checks it at 390 wide, for a long name and for the last section.

**Adding a section.** The list is one explicit file, `modtools/composables/useUxSections.js`, and
nothing scans for files. A section is one import and one entry there: `key`, `label`, `order`,
`component`, which of `period`, `device` and `signed` it uses, the reads (`slots`) the tab loads
for it, and the address keys it owns (`params`). The file's comments say what each field does. A
section may also have a `badge` drawn in the nav (the Experiments one is a red number for experiments with a
verdict nobody has closed, and the blue count of running ones otherwise; see
[ux-experiments.md](ux-experiments.md)), and a `funnelLink` that adds a link to the "Also
for this funnel" row of the Funnels section. A section that loads its own data from its own store
lists no slots and no filters, and the tab then hides the filter bar and says so. Add a row to
the table above in the same change, because this page covers that file.

A piece that belongs to one experiment is not a section: it goes in `HOSTED.experiment` in the same
file, the details of every experiment draw it, and it draws nothing unless the experiment is its own
(`ModSysAdminUxReplyConfirmDetail` for `confirm-reply`, `ModSysAdminUxInterestedDetail` for
`interested-button`).

The tab mounts only the open section. A section never imports another section's component. It
gets `view` (see below) and sends `navigate(patch)`, which changes the address. Two helpers are
provided to every section: `uxHrefFor`, which gives the address a change would give so a link can
be copied, and `uxFunnelLinks`, the sections that want a link from a funnel.

## The address

All the tab's state is in the address, so a copied link restores the section, the funnel, the
step, the period and the filters. The SysAdmin page mounts the tab afresh each time its UX pill is
clicked and reads `?tab=` only once, so state kept in a component would be lost.
`modtools/composables/useUxView.js` reads and writes it.

| Key | Meaning | Default (left out) |
|---|---|---|
| `tab` | `ux`, kept by the tab and always written | |
| `ux_section` | a registered section | `overview` |
| `ux_period` | `7days`, `30days`, `90days` or `custom` | `7days` |
| `ux_from`, `ux_to` | `YYYY-MM-DD`, only with `custom` | |
| `ux_device` | `web`, `app`, `mobile`, `tablet`, `desktop`, `app-ios` or `app-android` | all devices |
| `ux_signed` | `in` or `out` | everyone |
| `ux_flow` | a funnel. Another section may set a value that is no funnel, such as `none`, and the Funnels section ignores it. | the first funnel with starts, else `give` |
| `ux_step` | a step of the open funnel | |
| `ux_open` | which closed row is open | |
| `ux_<name>` | a key a section lists in its `params` | |

The rules, all tested in `useUxView.spec.js`:

- An unknown value falls back to its default and is removed the next time the tab writes. Two
  dates that are not both real days, or a range over 366 days, give 7 days. Reversed dates are
  swapped.
- The tab writes with `router.replace`, never `push`. For someone who arrived on a link, the first
  Back after a pushed query change would send them to the home page
  (`plugins/back-navigation.client.js`). With `replace` Back leaves the tab, which is the normal
  result.
- Leaving a section clears the keys it owns, and the step and the open row. Moving from the nav
  to any section but Funnels leaves the funnel behind. A link that names a funnel keeps it. Changing
  the funnel clears the step. A link that carries the section, the funnel and the step (the Overview
  "Biggest loss" cell) keeps its step.
- A value in `ux_signed` stays in the address when the person moves to a section that has no
  Signed in select. That section does not send it, count it or print it, and it takes effect again
  when the person returns to Funnels.

## The filters

The filter bar (`ModSysAdminUxFilters.vue`) wraps the shared `ModEmailDateFilter`, which has four
optional props for this tab: `presets`, `daily`, `defaultStart` and `defaultEnd`. In `daily` mode
a preset is whole days ending yesterday (UTC) and the custom inputs are dates, because the rollups
are one row a day and there is none for today. The tab never sends a time of day. In the bar each
label and its select are one group (`.filter-pair`), so on a phone a label is never left at the end
of a line, away from its select. The browser test measures that at 390 pixels, and that Update stays
on the line of the filters at 1024.

The server cuts the figures by `device` and `signed_in`. The code that does it is
`iznik-server-go/ux/filters.go`, in one place, so a handler cannot forget a filter or add one to a
table that lacks the column.

| Read | Table | Device | Signed in |
|---|---|---|---|
| `funnels` | `ux_flow_daily` | cut | cut |
| `outcomes` | `ux_flow_outcome_daily` | cut | ignored |
| `fields` | `ux_field_daily` | cut | ignored |
| the finish figures in `funnels` and `fields` | `ux_flow_signal_daily` | cut | cut for `funnels`, ignored for `fields` (it clears Signed in first) |
| `friction` | `ux_component_daily` | cut | ignored |
| `adoption`, `regret`, `search`, `health` | their own tables | ignored | ignored |
| `samples` | `ux_sample_sessions` | not filtered, not echoed | not filtered, not echoed |

**Signed in changes the step figures only.** In the rollup, `logged_in` is the state when each event
was sent, not when the visit began. Someone who starts signed out and signs in to post counts as
signed out at the start and signed in at the finish, so a signed-in rate of finished over started
could pass 100 percent. The outcomes (started and finished) therefore ignore it, and the step
figures are still rough for the funnels where a person can sign in part way. The Funnels section
says so whenever Signed in is set. A true cut at the start of the visit needs a rollup table keyed
on the state at the start, which does not exist.

**The server says what it used.** Every one of the eight responses has `filters` (what was
applied) and `ignored` (requested filters this read does not apply, always an array). The tab
compares them with what it asked (`filterNotes` in `useUxFormat.js`) and writes a plain sentence
under the panel. A response with no `filters` at all is an old server, and the tab says its
figures may not be filtered. The frontend can reach staff before the Go API does, so a filter must
never be passed off as applied. The cache key of every read carries both filters, including on a read
that ignores them (`serve()` in `ux/ux.go`), or the first caller's answer would reach everyone for
ten minutes.

## While it loads, and when a read fails

The store (`modtools/stores/ux.js`) keeps two records of what each read was asked with. `held` is
the newest request, from the moment it is sent: a section skips a read that already holds what it
wants, and the field figures take their dates from it. `answered` is the request whose answer is
in the slot now. The notes about the filters (`store.notes(name)`) describe the answer on screen, so
they read `answered`. While a changed filter loads, the slot still holds the answer to the one
before, and comparing its echo with the new request would call an up-to-date server an old one.

- A read that failed is asked again the next time a section asks with the same params, so a
  fault does not follow the person from section to section. A route the server does not have (404
  or 501) is not asked again, because asking cannot fix it.
- The Funnels section keeps the funnel it opened while its reads reload. With no funnel in the
  address it opens the first funnel with starts, and it does not flick to Offering and back each
  time a filter changes.
- An error in a section is shown as a notice and is also passed on to the app's error handler, which
  is where it is reported (`ModSysAdminUx.vue`). A fault in the nav or the filter bar is not pinned
  on the open section.

## The figures the funnel views keep

The funnel views keep every figure the old Funnels panel showed.

- The outcome line under the headline (`outcomeLine` in `useUxFunnel.js`) gives submitted, how many
  of those failed, finished, left without finishing and came back later, each as a percent with its
  count over its base.
- The chart's table view has, for every step, `Finished` (the visits that went on, over its visits)
  and `Previous period` (the same figure for the period before, or a dash).
- The step detail has the same two figures for the chosen step, and the dwell times and the three
  breakdowns.

## How to read Biggest loss

There is one definition, `worstStepOf` in `useUxFunnel.js`, and the headline, the table of all
funnels and the tag on the chart all take their answer from it. It is the step with the highest share
of its own visits that ended there, counted over both page families together, so a funnel has one
answer. It needs 20 visits, or 50 when a filter is set, and at least one that ended there. A way
out of a funnel (forgotten password) is never a loss. When no step has enough visits, or none lost
anybody, the tab says so.

On a chart the row is also flagged by its amber bar and a faint amber wash. The tag is plain
coloured words, not a pill, in a column of its own at the right-hand end of the row, so it sits in
the same place whatever the label says, and no row changes height for it. The browser test measures
that.

## What a step is

Hovering over or focusing a step name on a chart shows which page or screen it is, with its route,
and what the member does there. The text lives next to the step names, in `STEP_HELP` in
`useUxFunnel.js`, and `useUxFunnelHelp.spec.js` fails for a step without one. A "came in through"
row says how the member arrived, then what that step is. A thin low-contrast tree line joins those
rows to their stage.

## The words

Staff words are plain and short, and say "funnel", never "flow" (the code keeps `flow` for the
key). Where each sentence is made:

- The closed line of each panel is in that panel's component. Data health names three failing
  checks at most and counts the rest, and names them in words (`healthLabel` in
  `useUxFormat.js`), in the table, the notice and the banner. The batch stores `flow_start:give`
  and `event:interaction`. A name it does not know prints as it is.
- Second thoughts and Help say `Too few visits to say.` when no step has 20 visits, and `Nobody went
  back.` or `Nobody asked for help.` when the steps with enough visits had none
  (`stepWithMost` in `useUxFunnel.js`). Friction names the component whose trouble rose most, not
  the one with the most trouble: the server ranks by the rise since the period before.
- Second thoughts and Help say whether the people who showed a sign went on to finish
  (`useUxThenFinished.js`). A row gets `Then finished` (the share of those visits that finished the
  funnel, with the count), `Others finished` (the same for the other visits at that step) and `What it
  means`. `Many gave up` is shaded and sorted to the top: the people with the sign finished at least 10
  points less often than the others. `Most still finished` is the same share within 10 points and half
  or more finished. `No worse than the rest` is within 10 points and fewer than half finished, which
  says the step loses people anyway and not that the sign did it. `Too few to say` is under 20 visits
  on either side (50 when Device or Signed in is set, as for Biggest loss). The closed row adds one
  sentence: the worst place when some gave up ("At Details, 16% of those who went back finished,
  against 90% who did not."), or "Most who went back or changed a field still finished, so this is
  less of a worry." when none did. A field's others are the visits at its step without that field
  trouble. The columns are left out when the server sends no such figures (an older server, or days
  the rollup has not yet covered). These are separate visits, so a visit that went back three times is
  one; they are not the event counts beside them. The figures describe what happened to people with
  the sign. They do not show the sign caused the loss: the people who go back may be the ones more
  likely to leave anyway.
- A step is named by `stepName` in `useUxFunnel.js`: its plain label, with the page family added
  (`Options (mobile)`) where a desktop and a mobile page of one funnel share a label. The headline,
  the table of all funnels, the step detail, Second thoughts, Help and the sample sessions use it.
  The chart keeps the plain label, because it has a heading for each family.
- A device is named as the Device filter names it (`DEVICE_LABELS` in `useUxFormat.js`) in every
  sentence and table. The step detail uses the words of the outcome line, finished and left without
  finishing.
- The notes about the filters are `filterNotes`, and the line under the filter bar is
  `viewSummary`. It prints the dates the server gave (`start`, `end`, `previous.start`,
  `previous.end`) and only the filters the open section uses.
- A read the server does not have yet (HTTP 404 or 501) is not a fault. The panel says "This part
  is not switched on yet." (`useUxSlot.js`).

## Tests

`tests/unit` holds a spec for each component, composable and the store. The two boundary specs
(`uxFunnelsBoundary.spec.js` and `TestUxFunnelsBoundary` in `test/ux_filters_test.go`) read these
files as text and fail on a name that ties them to another part of the UX work. The browser test
`tests/e2e/test-modtools-sysadmin-ux.spec.js` loads every section from the real read API, checks a
linked view, a server that ignores a filter, a missing route, where the tag sits, and the height of
each section. It runs against the ModTools production container, which is built and not synced: after a
change to the tab, rebuild `modtools-prod-local` before a run.

## Deploying

Deploy the Go API first. Until it is live the old API ignores the filters and sends
no `filters`, and the tab says so rather than showing the figures as filtered.

The finish figures need one new table, `ux_flow_signal_daily`. Run
`2026_10_21_000001_create_ux_flow_signal_daily_table_migration.sql` before the batch and the Go API. Until
it exists the nightly rollup skips it with a warning and the read leaves the figures out, and the
Second thoughts and Help panels draw as they did. The first figures come with the first nightly
rollup after it is run, for the day before, and the tab shows them over the days that have rows, so
a seven day period is full after a week. The table cannot be filled back further than Loki's seven
days.
