---
name: community-reuse-outreach
description: Use when researching or shortlisting local community organisations to approach for a bulk reuse clearance, bulk freegling, a donor offloading lots of items (office/charity move, school refit), or partnership outreach in a given town or area. Produces a scored, audited shortlist of orgs that would actually take the items, with published emails and activity evidence.
---

# Community reuse outreach

Given (a) a list of items a donor wants to rehome and (b) a target geography, produce a **scored, audited shortlist of community organisations to contact**, each with a published email and dated activity evidence. Proven on the Mind in Brighton & Hove clearance (2026).

**REQUIRED BACKGROUND:** the full rationale, data model, scoring rubric and forward-looking tech design live in `plans/community-reuse-outreach-methodology.md`. This skill is the operational distillation you run now.

## The two ideas that make this work

1. **Two tiers, different economics.**
   - **Tier 1 - known-interested.** Orgs that exist to take donated goods: Reuse Network members, Men's Sheds, scrapstores, tool libraries, Emmaus, starter-pack/homelessness/refuge charities. Small finite list (~10-20 in a town), 40-60% conversion. Contact them ALL, warmly, with the FULL list. Tier 1 runs FIRST; whatever they take comes off the list before Tier 2 (this is what stops the "promised it to two people" failure).
   - **Tier 2 - speculative.** Community orgs that would plausibly benefit but have no evidenced history of taking bulk reuse (community centres, faith groups, refugee drop-ins, scouts). Large population (hundreds), low hit rate (2-10%). Each gets ONE specific item cluster, never the whole list.

2. **Activity evidence is the hard gate, not the register.** Charity Commission / Companies House addresses are convenience addresses and half the entities are dormant. Use them only IN REVERSE - to confirm legal status once an org is already found via activity signals.

## Two modes - pick before you run

- **Curated** (default; high-stakes, limited stock, or reputation-sensitive): score and human-gate down to a precise shortlist. Tight fit matters.
- **High-recall** (donor wants maximum reach, or there's plenty of stock): include **as many organisations as possible** for which there is evidence they are **active** and could **plausibly want** the items. Drop the pruning and the tight-fit filter - desks/chairs/tables suit almost any org with premises. The gate relaxes to just: active (dated sign of life) + a published email + a plausible use. The biggest lever is **wholesale-harvesting the local voluntary-sector directories** (council/CVS community directories per borough), not just the 12-category search. Still human-reviewed before sending, but the list is large by design.

Both modes keep the activity-evidence and published-email gates below. Mode only changes how hard you prune.

## Hard gates (an org enters the shortlist iff ALL three hold)

1. **Activity:** one specific dated URL within the last 12 months showing operations in the target area (own website news/post, dated Facebook/Instagram/X/LinkedIn post, local news, CVS event listing, dated job ad).
2. **Email:** a published address on the website or social "about" - NOT a contact form, NOT phone-only.
3. **Reality:** genuine operating presence in the target geography - not a registered address for an entity operating elsewhere.

Archive every evidence URL to archive.org at the moment of checking, so the audit trail survives site takedown.

## Run it (the pipeline)

1. **Cluster the items.** Group the donor list into ~6-8 functional clusters; each maps to org types. See `reference.md` (clusters table). A Tier-2 email offers exactly ONE cluster.
2. **Tier-1 harvest (exhaustive).** Start from `reuse-organisations.md` (the curated UK reuse directory: national networks + per-material operators, each with a "find your local branch" route). Use the locators to pull the orgs operating in the target geography. Cross-check activity (UK Men's Sheds map and old directories contain dormant entries). Also add any org that took items in a previous clearance (response history promotes to Tier 1 after ~2 collections in 18 months).
   - **Cost-to-donor gate (CRITICAL when the donor gives away FREE).** Only include routes that are FREE to the donor: charities that collect for nothing, or recipients who collect themselves. **NEVER include paid/commercial routes - exclude them entirely, do not even list them.** Office-clearance, fit-out and furniture-resale firms (commercial "office furniture reuse" companies) redistribute to charities but **charge the donor** or **buy/resell** the goods - a clearance company topping a "reuse" search is a classic false positive. Check each intermediary's model; if it charges the donor or buys/resells, drop it. (Room "hire" by a community centre or incidental "clearance" mentions are NOT paid-collection - those orgs are free recipients; don't over-filter them.)
3. **Tier-2 discovery (parallel).** One research agent per category across all 12 categories (see `reference.md`). Each agent uses the brief in `category-agent-brief.md`, applies the hard gates, and returns BOTH included and rejected (with reasons). Fan out with the Workflow tool or parallel Agent calls; each writes a markdown file.
4. **Score** each candidate on four 1-3 axes (total 4-12): **Fit** (cluster serves their mission), **Capacity** (can they collect & store this scale), **Recency** (how fresh the activity signal), **Reachability** (email quality). Tier-1 bypasses Fit. The model must cite evidence per score; scores are human-overridable.
5. **Aggregate** to a spreadsheet with two sheets: **Included** (Category, Tier, Name, Website, Email, Postcode/Area, Activity evidence [URL+date+one-line], Item-cluster fit, Why they fit, Confidence, Priority) + operator columns (Decision send/skip, Sent?, Reply?, Outcome, Notes); and **Rejected** (with reason, for audit + false-negative analysis).
6. **Validate after the clearance.** Compare the shortlist to who actually took items: true/false positives, and false negatives (orgs that took but weren't found - trace which source would have caught them). Promote responders to Tier 1; feed source gaps into the next run.

## Bounce salvage (recover the bounced sends - proven on the WAGGGS clearance, 2026)

A bounce usually means the *listed* address is stale, not that the org is unreachable. On the WAGGGS run, **16 of 24 bounces** yielded a different, MX-valid published address (the rest were dead orgs or email-less). Per bounced org, in order:

1. **Is the org still alive? Check this FIRST**, before hunting an address. Charity Commission register (status "Removed" / "Does not operate"), Companies House (dissolved), Ofsted for nurseries/schools ("Closed"). If dead, DROP it - don't spend time. (WAGGGS run: 4 confirmed dead by exactly these registers.)
2. **Hunt a DIFFERENT published email** from, in rough priority: the org's own site contact/about page; its Facebook page About/Contact tab; the Charity Commission register's contact-information page; the local CVS/council directory. Verbatim + source URL only; never construct or infer an address.
3. **Grep the RAW HTML, not the WebFetch/WebSearch summary.** Those summaries invent placeholder addresses ("[email protected]", a guessed "info@..."). `curl` the page and grep for genuine `@` patterns (filter `@font-face`/`@media`/`@context` noise) and decode Cloudflare-obfuscated mailto links. A hallucinated address that then MX-passes on a real provider is the worst failure mode - it looks verified. (A group-C agent caught exactly this for a nursery.)
4. **Common bounce patterns (check these before deep research):**
   - Trussell Trust **foodbanks**: the generic `<branch>@foodbank.org.uk` bounces (routes to national), but a live `info@<branch>.foodbank.org.uk` sits on the branch's own site. (Held for all 3 foodbanks on the WAGGGS run.)
   - Generic `admin@` / `office@` / `enquiries@` often bounce while `info@` or a named-person address on the same domain works.
   - `.org` vs `.org.uk` (and similar TLD) typos in the original list.
   - Whole-domain death (NXDOMAIN / broken DNS delegation) = no email channel at all; fall back to Facebook/phone, or drop.
5. **MX-gate every candidate** before re-sending (`dns.resolveMx`, or `~/.claude/bin`-style helper). A resolving MX is necessary, not sufficient - it says the domain accepts mail, not that the mailbox exists.
6. **Facebook as the fallback channel.** When no email survives but the org has an active, ownership-verified Facebook page (linked from its own site, or a handle its own site confirms), message the page / post the offer - a manual, human-in-the-loop step. Login walls block dating posts, so verify the page is *theirs* via a link on their own site and treat activity as "unknown" rather than guessing a URL.
7. **Re-send is a fresh send:** same one-cluster discipline, same human sign-off, and add BOTH the bounced and the new address to the ledger (suppression + alternatives) so the next run starts clean.

## The output is a candidate list, NOT a send list

A human reviews and marks send/skip PER ROW. The whole exercise is stakeable on one bad email, so the human gate is deliberate and sticky (no bulk-send > 5; rate-limit ~20/hour). Tier 1 first, then Tier 2 one-cluster emails, each naming specific items - no mail-merge. Sign as the human facilitator, never "AI".

## Hard-won gotchas (from the Mind debrief)

- **Never offer Tier 2 the whole list** - one cluster only; the full list is commitment-repellent.
- **Scope the donor's upfront ask in ONE go**, not across drifting messages: item name, dimensions (H x W x D), condition (rubric, eBay-style), dismantlable?, notes, a photo per item, and a **number per item**. Orgs will do surprising amounts of upfront work if the ask is clear and single.
- **Item numbers and measurements are essential** - "I'd like the desk and the chest with two drawers" is undisambiguable across 50 desks without them.
- **Open days** (people come in pairs to look before committing) dramatically raise uptake - worth offering if the donor organises early; allocate with a short memorable word-code, not QR.
- **Waiting list, don't dead-end.** When a replier wants only items already allocated/gone, don't just say no - reply that they're **on the waiting list** and you'll come back if it frees up or more is confirmed. Keep a waiting-list table; call-back order when an item frees is priority orgs first, individuals last. Costs nothing, keeps goodwill, and catches the common case where a promised collection falls through.
- **PECR / privacy:** legitimate-interest basis, published org addresses only, never individuals; one email per org per ~6 months; persistent suppression list for opt-outs.
- **WebFetch/WebSearch hallucinate email addresses** (placeholder "[email protected]", plausible-looking "info@..." guesses). Verify every address against the RAW page HTML or the Charity Commission register before trusting it - a hallucinated address that MX-passes on a real provider looks fully verified but isn't. Provenance rule: verbatim published address + source URL + MX gate, always.

## Files
- `reuse-organisations.md` - **the Tier-1 directory**: 73 verified UK reuse organisations and networks by material type (furniture, electricals, IT, bikes/tools, books, textiles/uniform/baby, paint/wood/scrap), each with a "find your local branch/member" route. Start here for Tier 1.
- `reference.md` - the 12 categories, the item-cluster -> org-type table, the hard-gates card, scoring rubric and Tier-1 sources.
- `category-agent-brief.md` - the reusable prompt for one Tier-2 category-search agent (fan out 12 of these).
