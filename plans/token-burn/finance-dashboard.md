# Candidate: Income dashboard in ModTools SysAdmin

Status: draft
Last reviewed: 2026-10-28

## Goal

A SysAdmin tab in ModTools showing Freegle's non-grant income as time series: donations by
channel (Stripe, PayPal, PayPal Giving Fund, bank transfer and the rest), job ad clicks with
an estimated value, and Playwire ad clicks. It shows trends, a comparison against a trailing
baseline, and the effect of dated campaigns (a mailing, an appeal, a change to where an ad
sits) on each series. It is for the board, the finance volunteers and the CTO. Xero stays the
book of record. This dashboard holds the series Xero cannot, and is read-only.

## Why it qualifies

- Token-heavy because: it spans a batch job that builds daily rollups, a Go API for the series, and a Vue tab with charts, and the trend and campaign-effect maths needs many fixtures and edge cases at each layer.
- Unattended because: every source it needs is already in the Freegle database once the questions below are answered, so no build task waits on a person or outside system.
- Worth it because: today nobody can see donations, job clicks and ad clicks side by side, so the effect of an appeal or a layout change on income is guessed rather than measured.

## What exists today

- `iznik-batch/database/migrations/2025_12_10_094529_create_users_donations_table.php` - donations: type (PayPal, External, Other, Stripe), source (DonateWithPayPal, PayPalGivingFund, Facebook, eBay, BankTransfer, Stripe), GrossAmount, timestamp, Gift Aid fields. This already covers all three donation channels in one table.
- `iznik-server-go/donations/donations.go` - the donations API (list, add, bulk upload). Also `paypalipn.go`, `stripeipn.go`, `stripe.go` for the inbound feeds. No aggregate or time-series endpoint was seen.
- `iznik-batch/database/migrations/2025_12_10_094529_create_logs_jobs_table.php` - job ad clicks: userid, link, timestamp, jobid. No amount is stored per click.
- `iznik-batch/database/migrations/2025_12_10_094529_create_jobs_table.php` - jobs carry `cpc` (cost per click, decimal 4,4). Whether job rows are kept after expiry, which decides if a click can be valued later, is unknown.
- `iznik-batch/database/migrations/2026_06_29_000002_add_page_to_logs_jobs.php` - adds which page a click came from, so clicks can be split by placement.
- `iznik-nuxt3/components/OurPlaywireDa.vue` - fires the `playwire_ad_click` telemetry action. Clicks are detected by window blur, so the count is an estimate, and carry ad unit path and type. No revenue figure is available from this.
- `iznik-batch/database/migrations/2026_10_12_000001_create_ux_telemetry_tables.php` - where those telemetry actions land. The exact table and columns for querying `playwire_ad_click` by day were not opened.
- `iznik-server-go/ux/experiments.go` - treats `job_ad_click` as a primary event for experiments. Linked, not changed.
- `iznik-nuxt3/modtools/pages/sysadmin/index.vue` - the tabbed SysAdmin page, with sub-tabs and one `ModSysAdmin*.vue` component per view. A new tab follows this pattern.
- `iznik-nuxt3/modtools/components/ModIncomingEmailCharts.vue` - an existing charting component in ModTools; whether it is reusable for this is unknown.
- Campaigns: no table of dated campaigns was found in the migrations. Reuse Day mail goes through Mailchimp and is invisible to our tracking, so campaign dates would have to be entered by hand.

From the finance discussions (treasurer, donor-thanks volunteer and CTO, March to July 2026; `plans/future/finance-donations-automation.md` holds the agreed actions):

- The May 2026 finance meeting set "more frequent, near real-time financial information" as a goal in its own right, because the treasurer's figures are reliable but lag the manual process. This candidate is the direct answer to that goal.
- Bank donations will reach us through a Unity to Xero feed and a pull from the Xero API, with standard Xero tags for unidentified and CAF donations. The Xero pull is not built. It is the agreed route for bank actuals, so this candidate's Xero tie-out reuses it rather than adding a second import.
- PayPal Giving Fund has no API; it is a manual download that does not always happen, donors are often anonymous and Gift Aid is already claimed. Its series will have gaps and must be labelled as such.
- The consolidated "donations needing thanks" digest was built in June 2026 (`iznik-batch/app/Services/DonationThankPrepService.php`). The volunteer asked for something others could take over, and the CTO suggested a web page instead of the mail. A ModTools page is the natural home, and it is a thanks workflow rather than a dashboard, so it is listed as an open question below.
- The donor-thanks volunteer's stated ideal is one place holding a member's full donation record: every donation by method, Gift Aid status in three states (consent given, declined, no response), whether national mailboxes have contacted them, notes, alternate emails and memberships, searchable by email, membership id or location, with thanked and not-thanked visible. That is donor-level and sensitive, so it is out of scope here and belongs to the finance plan, but the dashboard must not block it.
- A recurring member query is "why do I still see adverts after donating", which comes down to matching a donation email to a member. The dashboard does not solve that; it should not pretend to.

## Decisions settled

- **The dashboard lives in ModTools SysAdmin, read-only.** Xero cannot hold non-financial series such as clicks, and Xero stays the book of record for money.
- **Linked, not rewritten: the donation alerts and donor lookup stay in the finance plan.** `plans/future/finance-donations-automation.md` already covers the donations-plummet alert (0b) and donor lookup (0e). This candidate reads the same table and must not duplicate them.
- **A new table gets a surrogate `id`.** Convention in `.claude/rules/conventions.md`, even for a daily rollup keyed by date and series.
- **No member-level data on the dashboard.** It shows totals by day and channel only. A moderator-visible page must not become a way to list donors, in line with the rule that moderators do not export member data.
- **Xero actuals come through the finance plan's Xero pull, not a second integration.** The May 2026 meeting agreed the Unity to Xero feed and an API pull as the route for bank donations. The tie-out panel reads what that pull stores; if the pull is not built first, the tie-out starts as a CSV import and says so.
- **Donation thanks are tracked, not just prompted** (Edward, 2026-10-28). The Finance area gets a Thanks section: every donation that warrants a personal thank-you (the thank-prep rules) with whether it was thanked, by whom, when and how, and what was said; the volunteer marks it from the page or it is detected from the sent mail. Beside each donor sits the history of prior mail with them from the support@ and info@ mailboxes, fetched by code (read-only IMAP, matched on the donor's known addresses), so the person writing the thank-you sees what has been said before without opening four mailboxes. This is the one-stop record the donor-thanks volunteer asked for in March 2026, scoped to thanks.
- **Mailbox history is read by code, cached, and shown as headers and a short excerpt.** Full mail bodies open in the mailbox, not in ModTools; the cache is per donor, expires, and is access-logged like the rest of the Finance area.
- **Estimated and actual are always shown apart.** Job clicks valued at cost per click, Playwire clicks and PayPal Giving Fund before download are estimates or partial; bank and Stripe rows after the Xero pull are actuals. The finance volunteers already distrust figures that lag, so a mixed total would lose them.

## Open questions for Edward

Left here because this was written by a subagent. The parent session asks them as one batch.

- Who sees the dashboard? Recommended: SysAdmin role only, because donation totals by channel are commercially sensitive and SysAdmin is the existing gate.
- How is job click value measured? Recommended: estimate from `jobs.cpc` at click time, labelled as an estimate, because no revenue is stored per click. This needs a check that job rows survive long enough, or a snapshot of cpc into a new column going forward. Real revenue from WhatJobs reports would replace it if we can get them.
- Does Playwire provide revenue data we can import? Recommended: show click counts only, labelled as estimated, until Playwire's reports or API are confirmed. Someone with the Playwire account must check.
- How are campaigns recorded? Recommended: a small table of campaign name, start, end and channel, filled in from a form on the dashboard, because Mailchimp sends are invisible to us. The alternative is to detect effects from the series alone, with no campaign labels.
- How far back must history go? Recommended: from the start of the donations table, with job clicks and Playwire clicks from when each was first logged, and gaps shown honestly rather than filled.
- What counts as a campaign effect? Recommended: compare the window after the start with the same weekdays in the prior four weeks, and show the difference with its uncertainty, not a verdict, because attribution from a single series is weak.
- Should PayPal Giving Fund donations be shown gross, given their Gift Aid is claimed by PayPal? Recommended: show gross as recorded, with a flag, matching the finance plan's treatment.
- How is "thanked" recorded: a button on the Thanks page, detection of a sent mail to the donor's address in the thanks mailbox, or both? Recommended: both, with the sent-mail detection filling the record automatically and the button for thanks given another way.
- Which mailboxes feed the history: support@ and info@ only, or giftaid@ too? Recommended: all three, since the volunteer named all three as places she looks.
- Should the Xero pull from the finance plan be built as task 0 of this candidate, so the tie-out shows real bank actuals from day one? Recommended: yes if the Xero API tier question is answered before the build; otherwise the tie-out starts from a CSV export.

## External prerequisites

- [ ] Playwire: whether revenue reports or an API are available on our account tier. Owner: whoever holds the Playwire login. Start now, answer needed before the build.
- [ ] WhatJobs: whether per-click or daily revenue reports can be obtained, to replace the cpc estimate. Owner: Edward, via the partner contact. Start now.
- [ ] A list of past campaign dates (appeals, mailings, placement changes) from the treasurer or the fundraising volunteers, to seed the campaign table and test the effect view.
- [ ] Xero: confirm the subscription tier gives API access and which app type (an existing action from the May 2026 meeting). Owner: Edward. Needed before the tie-out can be more than a CSV import.
- [ ] Treasurer: the standard Xero tags for unidentified and CAF donations are in use, so the pull can separate them.
- [ ] Read-only IMAP credentials (app passwords) for support@, info@ and giftaid@, held as secrets on the batch host like the existing role mailboxes. Owner: Edward.

## Tasks

| # | Task | Depends on | Parallel track | Touches |
|---|------|-----------|----------------|---------|
| 1 | Daily rollup of donations by channel, with backfill and tests against fixtures | - | A | iznik-batch |
| 2 | Daily rollup of job ad clicks and estimated value, and of Playwire clicks | 1 (shared rollup table) | A | iznik-batch |
| 3 | Campaign table and Go API: series, baseline comparison, campaign effect | 1 | B | iznik-server-go, iznik-batch |
| 4 | SysAdmin tab: charts, trend and baseline view, campaign form | 3 | C | iznik-nuxt3/modtools |
| 5 | Docs page for the dashboard and its definitions | 4 | C | docs |
| 6 | Thanks section: donations needing thanks from the existing thank-prep service, thanked status (button and sent-mail detection), who, when, what was said; role-restricted | 3 | D | iznik-server-go, iznik-nuxt3/modtools, iznik-batch |
| 6a | Mailbox history: batch service that reads support@, info@ and giftaid@ by IMAP for a donor's known addresses, caches headers and a short excerpt per donor, and serves it to the Thanks section; shared with the support-helper candidate | 6 | D | iznik-batch, iznik-server-go |
| 7 | Xero pull and tie-out panel, if the API tier allows: daily actuals per channel beside the rollup, differences listed | 1 | B | iznik-batch, iznik-server-go, iznik-nuxt3/modtools |

Tasks 2 and 3 can overlap once task 1 has fixed the rollup table, but only if the table is merged first.

## Acceptance

- For a month of known data, each daily total on the dashboard equals a hand sum of the source rows, per channel.
- Days with no data show as gaps, not zero, and the page says which source starts when.
- Job click value is labelled as an estimate and the page says how it was calculated.
- Adding a campaign shows its window on the charts and a before and after comparison that a finance volunteer can read without help.
- A user without the SysAdmin role gets nothing from the API and no tab.
- Nothing on the dashboard page or its API response identifies a donor; the Thanks section does, and only to the thanks role, with access logged.
- On the Thanks section, a donation that warrants thanks shows thanked or not; marking it, or a sent mail to the donor, flips it; the donor's prior mail from the three mailboxes appears as dated headers and excerpts, newest first.

## Review burden

- PRs: 6 to 8
- Each UI PR carries: screenshots or a walkthrough video
- Each migration PR carries: raw production SQL in the description (the rollup and campaign tables)
- Docs page(s) to add or update, with `covers:`: a new developer reference page covering the rollup command, the Go handlers and the SysAdmin component.

## Out of scope

- Writing anything back to Xero, or replacing the Xero import in the finance plan.
- Donor-level search, Gift Aid claims and the sleuthing of unmatched donations. These stay in the finance plan. The Thanks section is the one donor-level piece here.
- Forecasting beyond a simple baseline comparison.
- Changing how ads or job links are shown.

## Related plans

- `plans/future/finance-donations-automation.md` - linked. Its alert (0b) could later read the rollup this builds.
