# Finance & Donations Automation — Edward's Plan

Source: Finance / donations / support meeting (Edward, Jane, Jacky).

This plan covers only the actions assigned to Edward. Jane's and Jacky's actions are noted as dependencies where relevant but are not tracked here.

## Edward's actions (from the minutes)

1. Research the Xero API and confirm subscription tier gives us access.
2. Write code to pull donations from Xero into the Freegle system and send improved consolidated emails to Jacky.
3. Eventually build automated sleuthing for unmatched PayPal/Stripe donations.
4. Investigate HMRC API registration and build code to submit Gift Aid claims directly.
5. Add a warning system that alerts if donations plummet unexpectedly.
6. Continue developing the AI-assisted approach for support (board AI policy pending).
7. Build automation for monthly contractor reminder emails (Jane to specify timing/recipients).
8. Automate the approvals workflow for invoice payments.
9. Explore tightening the Xero → Unity payments link.

## Prioritisation rationale

Three principles drove the ordering:

- **Long external lead times start now.** HMRC vendor recognition is 2–4 weeks officially, "elastic" in practice. Paperwork begins in parallel with everything else so it isn't the blocker at the end.
- **Quick wins first.** Two items (donations-plummet alert, contractor reminders) are small, independent, and don't depend on Jane or external services. They earn breathing room.
- **Respect Jane's prerequisites.** The Xero pull can't begin until Jane has Unity → Xero feeding and her CAF/unidentified tagging convention in place. That work also unblocks the consolidated Jacky email.
- **AI support work is gated by board policy.** It stays in the queue but won't be scheduled aggressively until the policy lands.

## Ordered work plan

### Phase 0 — Kick off in parallel (week 1)

These can all start immediately and are independent.

**0a. Initiate HMRC vendor recognition.** Email SDSTeam@hmrc.gov.uk to start the Recognition Process. Download the Local Test Service and Charities Technical Pack v1.3 so dev work can begin against schemas straight away. *(No code yet — just paperwork in motion. Action 4 prerequisite.)*

**0b. Donations-plummet alert.** Add a monitoring check modelled on the existing trash-nothing-posts check. Compare rolling 7-day donation count/amount against a trailing 28-day baseline; alert if it drops more than a configurable threshold. *Effort: ~1 day. No dependencies.* (Action 5)

**0c. Monthly contractor reminder emails.** Once Jane provides timing/recipients, add a Laravel scheduled command in `iznik-batch/app/Console/Commands/` that sends a templated email on a cron schedule. *Effort: ~0.5 day after Jane's spec. No dependencies.* (Action 7)

**0d. Consolidated email to Jacky (pre-Xero).** Replace today's per-donation email storm with a daily digest, triggered off the existing CSV-upload flow — no Xero work required. Hook into wherever individual donation emails to Jacky are currently sent (in iznik-server or iznik-batch), buffer instead of sending, and emit one digest per day.

The digest is shaped around Jacky's thank-you-email workflow ("workflow a" in her notes), so she can compose a thank-you straight from the email without bouncing between Modtools, info@, support@, giftaid@ and the GA register. For each donation:

- Email address and name (with any known aliases — see 0e).
- Amount, date and method (PayPal / Stripe / bank / CAF / PGF).
- Other donations from this donor, with dates.
- Gift Aid status: already have / just requested / declined / CAF — no claim.
- Membership: groups, member-since, active/inactive.
- Recent mod-team contact with the member (any chat/messages worth knowing about).
- Any prior thank-you email sent by Jacky, with content snippet and date.
- Quick links: Modtools member page, info@/support@/giftaid@ search for this email, GA register entry.

Notes for the Xero rebuild later: the email template/builder is a service that takes a list of donation records — agnostic to whether they came from a CSV upload or a Xero pull — so Phase 1 only swaps the data source. *Effort: ~4–5 days. Depends on Jacky confirming cadence/format.* *(Action 2 — front half)*

**0e. Donor lookup view v1 (in ModTools).** A single-page donor record that addresses the three search holes Jacky has flagged and supports her on-demand workflows (b: GA chase-up check; c: bulk thank-you check; d: "why am I still seeing ads"). Built from existing data — no Xero or PayPal API work needed for v1; those sources slot in as Phase 1 and 3 land.

Search inputs:
- **Email with variants** — Jacky reports she puts in many variants and sometimes misses results that Edward can find. Reuse/extend the alias-aware search the codebase already uses internally (see existing `users_emails` lookup): normalise dots, plus-aliases, common typos; index aliases and historical emails on the user record. This is the highest-value search fix.
- **Postcode / location / group** — search donors by postcode prefix, by group membership, by approximate location.
- **Membership ID**.

Per-donor view shows:
- All known emails (including variants and historical), name, postcode, group memberships, active/inactive.
- Donation history across all sources currently in our system (PayPal, Stripe, bank-via-CSV today; Xero takes over from Phase 1; PayPal-direct matched donations improve from Phase 3).
- GA status, GA form on file, any "give up" history (see 2g).
- Mailbox hits for info@/support@/giftaid@ matching any known email (search links at minimum; inline summary if cheap).
- Thank-you log — every thank-you Jacky has sent this donor, with date and content snippet.
- Notes field she can append to.

Where data is missing today (PayPal-direct correlation, GA-form-only with no donation), the view surfaces the gap honestly rather than hiding it — Phase 3 and 2g close those gaps. *Effort: ~1 week for v1 (search + view, populated from current data). No external dependencies.*

### Phase 1 — Xero API foundation (weeks 2–4)

Begins once Jane confirms the Unity → Xero feed is flowing and she's chosen the tagging convention for unidentified and CAF donations.

**1.0 Pre-flight for Jane — initiate the feed from Unity, not from Xero.** A screenshot of Jane's Xero "Edit Bank Account Details" dialog shows the warning "Unity Trust Bank is not a recognised bank with an available feed". This is misleading — the feed *does* exist and is free. It just isn't initiated from Xero's "Add Bank Account" flow.

Correct route (from Unity Trust's Xero integration user guide and Xero Central's Unity-Trust-Bank-direct-feeds article):
1. Log in to **Unity Trust Bank Online Banking** → **Account Management** → **Manage Integrations**.
2. Select Xero, authorise the link, and choose which Unity accounts to feed.
3. The Unity-side activation then surfaces the feed in Xero — no need to "add" the account on Xero's side first.
4. Xero will then show feed status as **Active**, **Stopped**, or **Not connected** under the bank account.

Known properties of the feed:
- **Free** (both sides).
- **Daily sync, ~03:30 GMT** — *not* real-time. Our Xero pull (1b) should run shortly after, e.g. 05:00.
- Initial connection includes up to **12 months of historical transactions**.
- **No payer/contact enrichment.** Transactions arrive as raw bank-format references; matching donations to Freegle users is entirely our responsibility (see 1c).
- **Go-live**: not specified in Xero docs; Unity (AU) cites "within 10 working days". Worth allowing 1–2 weeks in our schedule.
- **Disconnect** is user-triggered ("Stopped" via Unity's Manage Integrations); no periodic re-auth documented.

Edward to share this with Jane so she can stop fighting the Xero UI and route around it via Unity. Confirm sort code + account number match exactly between Unity and Xero before activating.

**1a. Xero custom connection setup.**
- Register a custom connection at developer.xero.com with Jane's authorising email — **free**, no Premium tier needed.
- Store `client_id` / `client_secret` in `.env.background` (matching the existing batch-prod secret pattern).
- Custom connections issue a fresh access token (30-minute lifetime) from the credential pair on each request — no refresh-token dance.
- Install `xeroapi/xero-php-oauth2` in `iznik-batch`. (The `webfox/laravel-xero-oauth2` wrapper is built for user-OAuth flows and adds little value for a custom connection.)

**1b. Donation pull service.** New `iznik-batch/app/Services/XeroDonationImportService.php`.
- Schedule: daily after Unity's 03:30 GMT feed delivery — e.g. 05:00 cron.
- Pull bank transactions via `GET /BankTransactions` using `If-Modified-Since` for incremental sync (community reports 80–90% fewer calls).
- Read tracking categories on each transaction to detect Jane's CAF and unidentified tags.
- Resolve contact details from the linked Xero contact *when set by Jane in Xero* — the Unity feed itself contributes no contact link, so this is only populated for transactions Jane (or Xero's auto-suggest) has manually associated.
- Store an import audit row per transaction so we can replay/debug.
- API budget is fine for our volume: free tier is 1,000 calls/day, paid is 5,000/day.

**1c. Match to Freegle users.** The Unity feed gives us raw bank reference strings only, so all donor identification is ours to do. Reuse and extend existing matching heuristics:
- Membership number / known reference patterns in the bank reference field.
- Name lookups using the 0e alias index (handles "Hibbert E" → Edward Hibbert style permutations).
- Postcode-and-amount cross-reference for cases where the name is mangled by the bank.
- Skip CAF-tagged transactions for Gift Aid (must not double-claim).

Surface unmatched transactions as a "needs sleuthing" list — the seed for Action 3. Feed matched donations into the digest builder from 0d. Retire the CSV-upload trigger. *(Action 2 — back half)*

### Phase 2 — HMRC Gift Aid API (weeks 4–8, overlapping Phase 1)

Dev work runs offline against the Local Test Service while HMRC vendor recognition progresses. Code can be production-ready before HMRC approves — switching endpoint URL is the cut-over.

**2a. Library + service skeleton.** Adopt `thebiggive/hmrc-gift-aid` (actively used PHP library — covers IRmark/HMAC-SHA256 signature and Government Gateway transport). New `iznik-batch/app/Services/HmrcGiftAidApiService.php`. Extract HMRC-row building from the existing `GiftAidClaimService::generateClaim()` so both the CSV and API paths share it.

**2b. Submission tracking table.** New migration: `giftaid_submissions` (id, submitted_at, correlation_id, status, response_xml/error, record_count, batch_window). The current `giftaidclaimed` timestamp records "included in some batch" — we now need a correlation ID per HMRC submission to poll status and unwind rejections.

**2c. Data-cleansing rules.** Move the post-export cleanups currently done by hand into the shared row-builder so both the CSV path (today's manual process gets clean rows immediately) and the future API path benefit. Rules to encode:

1. **Title field always blank.** Current code already emits empty title; add a regression test and a guard to make sure nothing ever populates it.
2. **First name promotion.** If the first-name field is blank, or contains only a title (`Mr`, `Mrs`, `Ms`, `Miss`, `Dr`, with/without trailing dot, case-insensitive), promote the first word of the last-name field into the first-name field. So `first="Mr" last="John Smith"` → `first="John" last="Smith"`.
3. **Last name = final word.** If the last-name field contains more than one whitespace-separated word, take the last word as the last name. Hyphens are part of a word, so `Smith-Jones`, `Smith-Jones-Brown` stay intact. Apply after rule 2.
4. **Postcode validation and repair.** Use the existing `locations` table (which canonicalises UK postcodes) as the source of truth. Algorithm:
   - Strip whitespace, uppercase.
   - Try exact match against `locations`.
   - If no match, try inserting a space at each of the standard split points (3-from-end, 4-from-end) and re-look-up. Catches the `NR11JW` → `NR1 1JW` case where the original had a missing or misplaced space (Jacky's example: `NR11 JW`).
   - If still no match, log the row and reset to unreviewed (don't submit a bad postcode to HMRC).
   - Postcodes.io can be a secondary validator if the locations table proves insufficient, but try locations first — it's local and zero-latency.
5. **Drop out-of-date donations.** Filter out donations older than the HMRC 4-year claim window before building rows. HMRC's rule: donations made up to 4 years before the end of the accounting period in which the claim is submitted. Replace the current static `>= 2016-04-06` floor with a rolling cutoff computed from today + Freegle's accounting year-end. *Confirm the exact cutoff with Jane/Jacky before going live — what they call "out of date" might be tighter than the HMRC max.*

**Do not** feed any real spreadsheet to AI tools (personal data; board AI policy pending). The rules above are descriptive — Edward implements directly from them without needing the source spreadsheet.

Each rule lands as a unit test against synthetic fixtures so the cleanups can't silently regress.

**2d. Submission flow.**
- Build ≤1,000 rows → wrap in XML → IRmark sign → POST → store correlation_id.
- Async poll for HMRC's response → mark `donations.giftaidclaimed` only on success; reset rows on rejection with reason logged.
- Run against Local Test Service end-to-end before submitting test scenarios to SDS team.

**2e. ModTools UI.** Submission history, status, retry button under `iznik-nuxt3/modtools/pages/giftaid.vue`. Keeps the CSV path as a dry-run/audit format and a disaster fallback.

**2f. Frequency.** No HMRC-imposed cadence to worry about (the rumoured "1 per 24 hours" limit appears unverified; the technical pack imposes no daily cap). Schedule daily after `donations:update-giftaid` runs — best cash flow, no admin cost.

**2g. Don't permanently "give up" on GA forms (Jacky's workflow e).** Today, when a GA declaration is received but can't be matched to any donation (often because the donor used a different email when paying), the team emails the donor for clarification, usually gets no answer, and then the GA is given up — lost for any future inspirational match. Fix:
- New soft-state on the `giftaid` record: "unmatched — pending" (kept alongside the current declined/active states). The GA stays in the register indefinitely once "given up", not deleted, with a `givenup_at` timestamp and reason.
- Every new PayPal/Stripe/bank donation runs a lookup against unmatched GA forms by name + postcode + any email variant (using the 0e alias search). A late match retroactively links them.
- The donor-view in 0e shows orphan GA forms in a "no matching donation yet" panel so Jacky can spot-check.
- Surface aggregated stats in the digest occasionally ("12 GA forms still unmatched") to keep them on the radar.

**Hold-points before going live:** HMRC Vendor ID issued; SDS team has signed off on a real test submission.

### Phase 3 — PayPal / Stripe matching + cross-source visibility (weeks 8–10)

Goal: close the gap where Jacky currently has to ask Edward (for PayPal) or Jane (for Stripe/bank) to track down a donor — workflow d from her notes ("why am I still seeing ads despite donating").

**3a. Automated sleuthing for unmatched PayPal/Stripe donations (Action 3).** Build on Phase 1c's unmatched-list and 0e's alias search. Many fail because the PayPal email differs from the Freegle email. Approaches:
- Name fuzzy-match against users (Levenshtein on normalised forms).
- Lookup the PayPal email against `users_emails` (including aliases from 0e).
- For Stripe Link/Apple Pay/Google Pay, fall back to address postcode + amount cross-reference.
- Surface ambiguous candidates in the Jacky digest with a one-click confirm/reject link. Confirmed matches feed back to enrich the alias index.
- Each new match also retries unmatched GA forms (see 2g) — a donation arriving may rescue a GA form orphaned months ago.

**3b. PayPal & Stripe records in the donor view.** Surface raw PayPal/Stripe entries (matched or not) on the 0e donor page so Jacky can self-serve workflow d without round-tripping. This is mostly a UI/data-join job once the matching is in place.

Starts after the Xero pipeline is steady because the matching code lives in the same path.

### Phase 4 — Invoice approvals & payments (weeks 10+, lower priority)

**4a. Invoice approval workflow (Action 8).** Read invoices awaiting approval via Xero API (`GET /Invoices`); send poll-style approval emails to the finance subcommittee; record approvals; mark approved invoices in Xero. Keep dual-auth human. Xero itself has no native approval workflow — we either build this ourselves or pay for ApprovalMax (~£20–50/month).

**4b. Xero → Unity payment link (Action 9).** Spike first. Xero's API **does not expose payment-batch creation**, which is a known gap. Likely outcome: the most we can do is generate a Unity-compatible payment file from the approved-invoice list and let Jane upload it, rather than full end-to-end automation. Confirm with Craig (he's checking Open Banking for the Multipay card — same territory).

### Phase 5 — AI support assistant (parked until board AI policy lands)

**5.** Continue building the AI-assisted support helper (Action 6) along the same lines as the Discourse-monitor tool. No production rollout until the board agrees the AI policy. Dev can continue against test data.

## Key constraints to remember

- **Plans go in `FreegleDocker/plans/`**, never in subdirectory repos. (CLAUDE.md)
- **Don't feed Jane's spreadsheets to AI tools** — they contain personal data. She'll describe rules in writing.
- **Never double-claim Gift Aid** on CAF or PayPal Giving Fund donations — both flag in Xero (CAF) or by source (PGF).
- **Migrations**: Laravel migrations in `iznik-batch/database/migrations/` are the single source of truth.
- **Stay manual fallback for HMRC**: keep `donations:giftaid-claim` CSV command working as dry-run/audit/DR fallback even after API is live.

## Effort summary

| Phase | Effort | Critical path? |
|---|---|---|
| 0 — Quick wins + consolidated email + donor view v1 + HMRC paperwork | ~2 weeks code + paperwork | Yes (HMRC lead time) |
| 1 — Xero pull (swaps in as data source for 0d / 0e) | ~2 weeks | Yes (unblocks Jane) |
| 2 — HMRC API + GA "don't give up" | ~4–6 weeks dev, overlaps Phase 1 | Yes (long-pole = HMRC approval) |
| 3 — PayPal/Stripe sleuthing + cross-source visibility | ~2 weeks | No |
| 4 — Invoice approvals & payments | ~3 weeks (spike Xero gap first) | No |
| 5 — AI support | Open-ended; gated by board | No |

## Vision: one-stop donor record (Jacky's framing)

Jacky's longer-term wish, which the phases above are nudging us towards rather than building head-on:

> A system where a full member's donation record is in one place. All donations, GA status, whether they've been contacted by national mailboxes, automated message subscriptions, notes, alternate email addresses, physical addresses, group memberships. Links to emails, GA form, Modtools details. Searchable by email / membership ID / location / thanked-or-not.

0e is v1 of this. Phases 1–3 extend it source-by-source. Beyond Phase 5, considerations worth revisiting deliberately rather than drifting into:

- **Access scope.** Currently a 3-person view (Edward, Jane, Jacky). Mods could see a scoped, time-limited view of a member they've been contacted by — but only as a deliberate policy choice; default stays narrow.
- **Auto vs personal touch.** The aim is to remove the drudgery, not the human in the loop. Thank-yous stay human-composed (the digest just makes the composing fast); auto-thanks remain a separate, opt-in channel.
- **Succession.** Every piece of automation here is also documented knowledge — if the three of us were unavailable, the system should be navigable by a successor. That's a check to apply to each phase: is the knowledge captured, or is it still in someone's head?
- **Confidentiality / scale.** As we widen access, GDPR-grade access controls become more pressing. The donor view should log who viewed what.

## Open questions for Jane / Jacky before kicking off

- Jane: target date Unity → Xero feed will be live? (Gates Phase 1. Note: per 1.0, the feed is initiated from Unity's online banking, *not* from Xero's "Add Bank Account" UI — the screenshot of the Xero "not a recognised bank" warning is a dead end; the correct route is Unity → Account Management → Manage Integrations.)
- Jane: chosen tag/tracking-category names for CAF and unidentified donations? (Phase 1b.)
- Jane: contractor reminder timing and recipients? (Phase 0c.)
- ~~Jane: written rules for Gift Aid data cleansing?~~ Captured from Jacky — see 2c. Still need: confirmation of the "out of date" cutoff (HMRC 4-year max, or tighter?), and whether any rules beyond the five listed are applied in practice.
- Jacky: preferred format/cadence for the consolidated email — daily digest, per-batch, both? (Phase 0d.)
- Jacky: which mailbox-history fields are useful inline in the digest vs. just a search link? (Phase 0d.)
- Jacky: any donors you'd like to test the 0e donor view on first — i.e. real-world workflows b/c/d to validate? (Phase 0e.)
- All three: is the donor view 3-person only for now, or do we want a scoped mod view in Phase 0e or later? (Vision.)
